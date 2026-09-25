<?php
/**
 * 上传与媒体：头像/贴纸（本地，不用图床）；图片消息按配置走本地（客户端压缩）或图床（API 压缩）
 */
class Upload
{
    private static array $cfg = [];

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

    /** 本地存储：头像 / 贴纸 / 图片消息（客户端已压缩） */
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

    /** 图床上传（图片消息）：服务端转发到图床 API，由图床压缩 */
    public static function imgbed(array $f): array
    {
        [$ok, $extOrMsg] = self::checkImage($f);
        if (!$ok) return [false, $extOrMsg];
        $api = self::$cfg['imgbed_api'];
        $post = ['image' => new CURLFile($f['tmp_name'], mime_content_type($f['tmp_name']), $f['name'])];
        if (self::$cfg['imgbed_cdn']) $post['cdn_domain'] = self::$cfg['imgbed_cdn'];
        $ch = curl_init($api);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err || !$resp) return [false, '图床请求失败：' . $err];
        $data = json_decode($resp, true);
        if (!($data['success'] ?? false)) return [false, '图床拒绝：' . ($data['message'] ?? '未知错误')];
        return [true, $data['data']['url'] ?? $data['url']];
    }

    /**
     * 统一上传入口
     * 分流规则（硬性约束）：avatar/sticker 永远本地；image 按 image_mode 配置
     */
    public static function handle(array $f, string $kind): array
    {
        if ($kind === 'image' && self::$cfg['image_mode'] === 'imgbed') {
            return self::imgbed($f);
        }
        return self::local($f, $kind);
    }

    // ---------- 贴纸收藏 ----------
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
