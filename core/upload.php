<?php
/**
 * 上传与媒体：头像 / 贴纸 / 图片消息一律本地原样存储，不做任何压缩，也不走图床
 */
class Upload
{
    private static array $cfg = [];

    /**
     * 允许作为「文件附件」的扩展名 => 可接受真实 MIME 列表
     *
     * 这是后台「允许的文件扩展名」设置的最后一道闸：设置里写了什么扩展名，
     * 都必须先在这里登记过，且 finfo 读出的真实 MIME 必须落在对应列表里，
     * 所以把 .zip 改名为 .pdf 之类的伪装会被挡下。
     *
     * ⚠️ svg / svgz 一律不登记：它是 XML、可内嵌 <script>，浏览器按图片渲染时脚本会执行。
     * ⚠️ php/phtml/html 等可执行 & 网页类同样不登记（本项目不需要交换源码）。
     *
     * office 2007+（docx/xlsx/pptx）本质是 zip 包，finfo 常报 application/zip，
     * 故其 MIME 列表里一并接受 zip 的 MIME，避免误伤正常文件。
     */
    private const EXT_MIME = [
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'rar'  => ['application/x-rar-compressed', 'application/vnd.rar', 'application/x-rar'],
        '7z'   => ['application/x-7z-compressed'],
        'gz'   => ['application/gzip', 'application/x-gzip'],
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/plain'],
        'md'   => ['text/markdown', 'text/plain'],
        'csv'  => ['text/plain', 'text/csv'],
        'log'  => ['text/plain', 'text/x-log'],
        'doc'  => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'mp3'  => ['audio/mpeg'],
        'wav'  => ['audio/wav', 'audio/x-wav'],
        'mp4'  => ['video/mp4'],
        'webm' => ['video/webm'],
    ];

    public static function init(array $cfg): void
    {
        self::$cfg = $cfg['upload'];
        @mkdir(self::$cfg['dir'], 0775, true);
        foreach (['avatar', 'sticker', 'image'] as $d) @mkdir(self::$cfg['dir'] . '/' . $d, 0775, true);
    }

    private static function checkImage(array $f): array
    {
        if ($f['error'] !== UPLOAD_ERR_OK) return [false, '上传失败（错误码 ' . $f['error'] . '）'];
        if ($f['size'] > self::$cfg['max_size']) return [false, '文件超过大小限制'];
        $info = @getimagesize($f['tmp_name']);
        if (!$info) return [false, '仅支持图片文件'];
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null;
        if (!$ext) return [false, '仅支持 jpg/png/gif/webp'];
        return [true, $ext];
    }

    /** 本地存储：头像 / 贴纸 / 图片消息（原图直存，不压缩） */
    public static function local(array $f, string $kind): array
    {
        [$ok, $extOrMsg] = self::checkImage($f);
        if (!$ok) return [false, $extOrMsg];
        if (!in_array($kind, ['avatar', 'sticker', 'image'], true)) return [false, '非法类型'];
        $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $extOrMsg;
        $dest = self::$cfg['dir'] . '/' . $kind . '/' . $name;
        if (!move_uploaded_file($f['tmp_name'], $dest)) return [false, '保存失败'];
        return [true, self::$cfg['url'] . '/' . $kind . '/' . $name];
    }

    /**
     * 统一上传入口：全部走本地原样存储（不压缩、不转码、不转发图床）
     */
    public static function handle(array $f, string $kind): array
    {
        return self::local($f, $kind);
    }

    // ---------- 文件附件（聊天文件消息） ----------
    // 安全要点：① 错误码 + 大小上限 ② finfo 真实 MIME（不信任 $_FILES['type']）
    //          ③ 双重白名单（后台设置 ∩ 内置表）④ 随机重命名 + 年月目录
    //          ⑤ 目录写 index.html 防列举（nginx 已禁 uploads 下执行 PHP）
    //          ⑥ 下载走受控接口、强制 attachment，不直接暴露路径

    /** 是否开启文件上传 */
    public static function fileEnabled(): bool
    {
        return DB::setting('file_upload', '1') === '1';
    }

    /** 单文件体积上限（字节） */
    public static function fileMaxBytes(): int
    {
        return max(1, (int)DB::setting('file_max_size', 10)) * 1048576;
    }

    /** 允许的扩展名：后台设置 ∩ 内置安全类型表 */
    public static function fileExts(): array
    {
        $raw = (string)DB::setting('file_exts', 'zip,rar,7z,pdf,txt,md,doc,docx,xls,xlsx,ppt,pptx');
        $out = [];
        foreach (preg_split('/[\s,，;；]+/u', $raw) ?: [] as $piece) {
            $ext = strtolower(ltrim(trim((string)$piece), '.'));
            if ($ext !== '' && isset(self::EXT_MIME[$ext])) $out[$ext] = true;
        }
        return $out === [] ? ['zip', 'pdf', 'txt'] : array_keys($out);
    }

    /** 读取真实 MIME（finfo，绝不信任客户端声明） */
    private static function realMime(string $path): string
    {
        if (class_exists('finfo')) {
            $f = new finfo(FILEINFO_MIME_TYPE);
            $m = $f->file($path);
            if (is_string($m) && $m !== '') return strtolower($m);
        }
        return 'application/octet-stream';
    }

    /**
     * 保存一个聊天文件附件
     * @return array [bool, array|string] 成功返回 ['name','ext','size','path','mime']
     */
    public static function storeFile(array $f): array
    {
        if (!self::fileEnabled()) return [false, '站点已关闭文件上传'];
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) return [false, '上传失败（错误码 ' . $err . '）'];
        $tmp  = (string)($f['tmp_name'] ?? '');
        $orig = (string)($f['name'] ?? 'file');
        if ($tmp === '' || !is_file($tmp)) return [false, '临时文件不可读'];

        $max = self::fileMaxBytes();
        $size = (int)@filesize($tmp);
        if ($size <= 0) return [false, '文件内容为空'];
        if ($size > $max) return [false, '文件超过 ' . round($max / 1048576) . ' MB 限制'];

        $mime  = self::realMime($tmp);
        $extIn = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
        $allow = self::fileExts();

        if ($extIn === '' || !in_array($extIn, $allow, true) || !in_array($mime, self::EXT_MIME[$extIn], true)) {
            // 声明扩展名不可用：再看真实内容是否属于站点已开放的类型
            $real = null;
            foreach ($allow as $e) {
                if (in_array($mime, self::EXT_MIME[$e], true)) { $real = $e; break; }
            }
            if ($real === null) return [false, '不支持的文件类型（' . $mime . '），仅允许：' . implode('/', $allow)];
            $extIn = $real;
        }

        $dir = self::$cfg['dir'] . '/file/' . date('Ym');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return [false, '无法创建上传目录'];
        if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');

        $name = date('dHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extIn;   // 随机名，杜绝穿越/覆盖
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($tmp, $dest)) return [false, '保存失败'];
        @chmod($dest, 0644);

        $safeName = preg_replace('/[\\\\\/\x00-\x1F\x7F]/u', '', $orig) ?: 'file';
        return [true, [
            'name' => mb_substr($safeName, 0, 120),
            'ext'  => $extIn,
            'size' => $size,
            'path' => 'file/' . date('Ym') . '/' . $name,
            'mime' => $mime,
        ]];
    }

    /**
     * 由消息里的相对路径解析出可下载的绝对路径（阻断路径穿越）
     * @return string|null
     */
    public static function fileAbs(string $rel): ?string
    {
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || strpos($rel, '..') !== false) return null;
        if (!preg_match('#^file/[A-Za-z0-9_\-./]+$#', $rel)) return null;
        $abs = realpath(self::$cfg['dir'] . '/' . $rel);
        $base = realpath(self::$cfg['dir']);
        if ($abs === false || $base === false || strpos($abs, $base) !== 0) return null;
        return is_file($abs) ? $abs : null;
    }
    public static function addSticker(array $actor, string $url): array
    {
        if ($actor['kind'] === 'none') return [false, '请先登录'];
        $owner = $actor['kind'] . $actor['id'];
        if ((int)DB::val('SELECT COUNT(*) FROM stickers WHERE owner_key=?', [$owner]) >= 100) {
            return [false, '贴纸收藏已满（100 张）'];
        }
        DB::insert('stickers', ['owner_key' => $owner, 'url' => $url, 'created_at' => time()]);
        return [true, '已收藏'];
    }

    public static function stickers(array $actor): array
    {
        if ($actor['kind'] === 'none') return [];
        return array_map(
            fn($r) => ['id' => (int)$r['id'], 'url' => $r['url']],
            DB::all('SELECT * FROM stickers WHERE owner_key=? ORDER BY id DESC LIMIT 100', [$actor['kind'] . $actor['id']])
        );
    }

    public static function delSticker(array $actor, int $id): array
    {
        DB::run('DELETE FROM stickers WHERE id=? AND owner_key=?', [$id, $actor['kind'] . $actor['id']]);
        return [true, '已删除'];
    }
}
