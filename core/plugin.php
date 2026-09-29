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
    private static array $stats = [];   // 每个插件的注册计数：hooks / routes / pages
    private static string $dir = '';
    private static int $lastCron = 0;

    public static function init(string $dir): void
    {
        self::$dir = $dir;
        @mkdir($dir, 0775, true);
        foreach (DB::all('SELECT name FROM plugins WHERE enabled=1') as $row) {
            $main = $dir . '/' . $row['name'] . '/main.php';
            if (is_file($main)) {
                $before = self::snapshot();
                try { require $main; } catch (Throwable $e) { Sec::log('plugin_error', $row['name'], ['error' => $e->getMessage()]); }
                self::$stats[$row['name']] = self::countReg($before);
            }
        }
    }

    /** 注册计数快照：加载插件前后对比，得到该插件注册的钩子/路由/后台页数量 */
    private static function snapshot(): array
    {
        return ['hooks' => self::$hooks, 'routes' => self::$routes, 'pages' => self::$adminPages];
    }

    private static function countReg(array $before): array
    {
        $h = 0;
        foreach (self::$hooks as $list) $h += count($list);
        $h0 = 0;
        foreach ($before['hooks'] as $list) $h0 += count($list);
        return ['hooks' => $h - $h0, 'routes' => count(self::$routes) - count($before['routes']), 'pages' => count(self::$adminPages) - count($before['pages'])];
    }

    /** 已启用插件的注册计数（Plugin::init 时记录） */
    public static function stats(string $name): array
    {
        return self::$stats[$name] ?? ['hooks' => 0, 'routes' => 0, 'pages' => 0];
    }

    /**
     * 探测未启用插件的注册计数：临时加载 main.php 统计后完整回滚注册。
     * 仅用于后台列表展示；插件应保证 main.php 只做 Plugin::* 注册（见 PLUGIN.md）。
     */
    public static function inspect(string $name): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) return ['hooks' => 0, 'routes' => 0, 'pages' => 0];
        $main = self::$dir . '/' . $name . '/main.php';
        if (!is_file($main)) return ['hooks' => 0, 'routes' => 0, 'pages' => 0];
        $before = self::snapshot();
        try { require $main; $stats = self::countReg($before); }
        catch (Throwable $e) { $stats = ['hooks' => 0, 'routes' => 0, 'pages' => 0]; }
        self::$hooks = $before['hooks'];
        self::$routes = $before['routes'];
        self::$adminPages = $before['pages'];
        return $stats;
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
            $on = (int)($enabled[$name] ?? 0);
            // 注册计数：已启用用 init 时的实测值；未启用临时加载探测后回滚
            $stats = $on ? self::stats($name) : self::inspect($name);
            $out[] = [
                'id' => $name,
                'name' => $meta['name'] ?? $name,
                'version' => $meta['version'] ?? '?',
                'description' => $meta['description'] ?? '',
                'author' => $meta['author'] ?? '',
                'source' => $meta['source'] ?? '本地',
                'enabled' => $on,
                'hooks' => (int)$stats['hooks'],
                'routes' => (int)$stats['routes'],
                'pages' => (int)$stats['pages'],
            ];
        }
        return $out;
    }

    /** 卸载：删除注册记录并递归删除插件目录（仅限已安装插件，名称白名单） */
    public static function uninstall(string $name): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) return false;
        $dir = self::$dir . '/' . $name;
        if (!is_file($dir . '/plugin.json')) return false;
        // 先删注册（不是停用：卸载后列表不应留任何痕迹），再删目录
        DB::run('DELETE FROM plugins WHERE name=?', [$name]);
        self::rrmdir($dir);
        Sec::log('plugin_uninstall', '', ['plugin' => $name]);
        return true;
    }

    private static function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? self::rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    /** 打包插件目录为 zip（返回临时文件路径；调用方负责输出与删除） */
    public static function packageZip(string $name): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) return null;
        $dir = self::$dir . '/' . $name;
        if (!is_file($dir . '/plugin.json') || !class_exists('ZipArchive')) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'owplug_');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) return null;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', $dir)) + 1);
            $zip->addFile($f->getPathname(), $name . '/' . $rel);
        }
        $zip->close();
        return $tmp;
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
