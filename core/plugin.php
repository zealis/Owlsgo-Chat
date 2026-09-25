<?php
/**
 * 插件机制：Hook、路由、后台页面、资源合并、在线安装（zip）、统一计划任务
 * 插件结构：plugins/<name>/plugin.json + main.php
 *   plugin.json: {"name":"显示名","version":"1.0.0","description":"...","author":"..."}
 *   main.php 中通过 Plugin::on('钩子名', callable) 注册钩子
 * 内置钩子：message.before_send / message.after_send / page.head / page.footer /
 *           admin.menu / api.route / cron.minute
 */
class Plugin
{
    private static array $hooks = [];
    private static array $routes = [];
    private static array $adminPages = [];
    private static array $assets = ['css' => [], 'js' => []];
    private static string $dir = '';
    private static int $lastCron = 0;

    public static function init(string $dir): void
    {
        self::$dir = $dir;
        @mkdir($dir, 0775, true);
        foreach (DB::all('SELECT name FROM plugins WHERE enabled=1') as $row) {
            $main = $dir . '/' . $row['name'] . '/main.php';
            if (is_file($main)) {
                try { require $main; }
                catch (Throwable $e) { Sec::log('plugin_error', $row['name'], ['error' => $e->getMessage()]); }
            }
        }
    }

    public static function on(string $hook, callable $fn): void { self::$hooks[$hook][] = $fn; }

    public static function fire(string $hook, array $args = []): void
    {
        foreach (self::$hooks[$hook] ?? [] as $fn) {
            try { $fn(...$args); } catch (Throwable $e) { /* 插件异常不影响主流程 */ }
        }
    }

    /** 插件注册 API 路由（action 名带前缀 plugin_<name>_） */
    public static function route(string $action, callable $fn): void { self::$routes[$action] = $fn; }

    public static function dispatch(string $action, array $ctx)
    {
        if (isset(self::$routes[$action])) {
            return call_user_func(self::$routes[$action], $ctx);
        }
        return null;
    }

    /** 插件注册后台页面：Plugin::adminPage('标识', '标题', callable 返回 HTML) */
    public static function adminPage(string $slug, string $title, callable $fn): void
    {
        self::$adminPages[$slug] = ['title' => $title, 'fn' => $fn];
    }

    public static function adminPages(): array { return self::$adminPages; }

    /** 插件注册静态资源（相对插件目录），统一合并输出 */
    public static function asset(string $type, string $path): void
    {
        if (in_array($type, ['css', 'js'], true)) self::$assets[$type][] = $path;
    }

    public static function renderAssets(string $type): string
    {
        $out = '';
        foreach (self::$assets[$type] ?? [] as $p) {
            $f = self::$dir . '/' . ltrim($p, '/');
            if (is_file($f)) $out .= file_get_contents($f) . "\n";
        }
        return $out;
    }

    /** 统一计划任务：每分钟最多触发一次 cron.minute（由长轮询驱动，也可挂系统 cron 调 ?action=cron） */
    public static function cronTick(bool $force = false): void
    {
        $minute = (int)(time() / 60);
        if (!$force && $minute === self::$lastCron) return;
        self::$lastCron = $minute;
        self::fire('cron.minute');
    }

    // ---------- 后台管理 ----------
    public static function listAll(): array
    {
        $out = [];
        $enabled = array_column(DB::all('SELECT name, enabled FROM plugins'), 'enabled', 'name');
        foreach (glob(self::$dir . '/*/plugin.json') ?: [] as $file) {
            $name = basename(dirname($file));
            $meta = json_decode((string)file_get_contents($file), true) ?: [];
            $out[] = [
                'id' => $name,
                'name' => $meta['name'] ?? $name,
                'version' => $meta['version'] ?? '?',
                'description' => $meta['description'] ?? '',
                'author' => $meta['author'] ?? '',
                'enabled' => (int)($enabled[$name] ?? 0),
            ];
        }
        return $out;
    }

    public static function toggle(string $name, bool $enable): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) return;
        if (!is_file(self::$dir . "/$name/plugin.json")) return;
        DB::upsert('plugins', ['name' => $name, 'enabled' => $enable ? 1 : 0, 'config' => ''], ['name']);
    }

    /** 在线安装：上传 zip 解压到 plugins/（ZipArchive 为 PHP 内置扩展） */
    public static function installZip(array $f): array
    {
        if ($f['error'] !== UPLOAD_ERR_OK) return [false, '上传失败'];
        if (!class_exists('ZipArchive')) return [false, '服务器未启用 ZipArchive 扩展'];
        $zip = new ZipArchive();
        if ($zip->open($f['tmp_name']) !== true) return [false, '无法解压插件包'];
        $root = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('#^([^/]+)/plugin\.json$#', $n, $m)) { $root = $m[1]; break; }
        }
        if (!$root || !preg_match('/^[a-zA-Z0-9_-]+$/', $root)) { $zip->close(); return [false, '插件包缺少 plugin.json']; }
        $dest = self::$dir . '/' . $root;
        @mkdir($dest, 0775, true);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (strpos($n, $root . '/') !== 0) continue;
            $rel = substr($n, strlen($root) + 1);
            if ($rel === '' || strpos($rel, '..') !== false) continue; // 防目录穿越
            $target = $dest . '/' . $rel;
            if (substr($n, -1) === '/') { @mkdir($target, 0775, true); continue; }
            @mkdir(dirname($target), 0775, true);
            copy('zip://' . $f['tmp_name'] . '#' . $n, $target);
        }
        $zip->close();
        Sec::log('plugin_install', '', ['plugin' => $root]);
        return [true, '插件已安装：' . $root];
    }
}
