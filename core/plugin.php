<?php
/**
 * 插件机制：Hook、路由、后台页面、资源合并、在线安装（zip）、统一计划任务
 * 插件结构：plugins/<name>/plugin.json + main.php
 *   plugin.json: {"name":"显示名","version":"1.0.0","description":"...","author":"..."}
 *   main.php 中通过 Plugin::on('钩子名', callable) 注册钩子
 * 内置钩子：message.before_send / message.after_send / page.head / page.footer /
 *           admin.menu / api.route / cron.minute
 *
 * v1.0.90 性能改造（元数据缓存 + 按需加载）：
 *   - init() 不再逐个 require 插件 main.php，只做一次轻量目录扫描 + include 缓存文件；
 *   - 元数据（plugin.json）与「注册清单」（插件登记了哪些钩子/路由/后台页/资源）
 *     缓存到 data/cache/plugins.php；仅在首次部署、新增启用、插件文件变更后重建一次；
 *   - 钩子触发 / 路由分发 / 后台页渲染时按清单精确加载涉及的插件，
 *     与当前请求无关的插件代码不再进入请求周期；
 *   - ?action=assets 的合并输出做文件级缓存（参与文件集合与 mtime 未变则直接复用）。
 * 契约（与既有约定一致）：main.php 顶层只做 Plugin::* 注册，不做输出 / 其它副作用。
 */
class Plugin
{
    private static array $hooks = [];
    private static array $routes = [];
    private static array $adminPages = [];
    private static array $assets = ['css' => [], 'js' => []];
    private static array $stats = [];   // 兼容保留：每个插件本次加载的注册计数
    private static string $dir = '';
    private static string $cacheDir = '';
    private static int $lastCron = 0;

    private static array $order = [];      // 启用插件名（DB 顺序）
    private static array $loaded = [];     // name => 本请求是否已加载 main.php
    private static array $meta = [];       // name => plugin.json 解析内容
    private static array $manifest = [];   // name => ['sig','hooks','routes','pages','assets','stats']
    private static ?array $pending = null; // 正在加载插件的注册增量
    private static string $loading = '';   // 正在加载的插件名
    private static bool $dirty = false;    // 缓存是否需要落盘

    public static function init(string $dir, string $cacheDir = ''): void
    {
        self::$dir = $dir;
        self::$cacheDir = $cacheDir !== '' ? $cacheDir : dirname(rtrim($dir, '/\\')) . '/data/cache';
        @mkdir($dir, 0775, true);

        // 1) 轻量目录扫描（目录名 + mtime），识别插件名单变化
        $dirs = [];
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            $n = basename($d);
            if (preg_match('/^[a-zA-Z0-9_-]+$/', $n)) $dirs[$n] = (int)@filemtime($d);
        }

        // 2) 读缓存并按签名（目录 / main.php / plugin.json 的 mtime）校验
        $cache = self::readCache();
        $stale = [];
        foreach ($dirs as $n => $dm) {
            $c = $cache['plugins'][$n] ?? null;
            $sig = self::sigOf($n, $dm);
            if ($c && ($c['sig'] ?? null) === $sig) {
                self::$meta[$n] = $c['meta'] ?? self::readMeta($n);
                self::$manifest[$n] = $c;
            } else {
                // 新插件或文件有变更：重读元数据，旧清单仅作兜底，标记待重建
                self::$meta[$n] = self::readMeta($n);
                if ($c) self::$manifest[$n] = $c;
                self::$dirty = true;
                $stale[$n] = true;
            }
        }
        // 名单中已消失的插件：剔除本地状态
        foreach (array_keys($cache['plugins']) as $n) {
            if (!isset($dirs[$n])) { unset(self::$manifest[$n], self::$meta[$n]); self::$dirty = true; }
        }

        // 3) 启用集（与旧版同一查询、同一顺序）
        self::$order = array_map('strval', array_column(DB::all('SELECT name FROM plugins WHERE enabled=1'), 'name'));

        // 4) 已启用但清单缺失或文件变更的插件：本请求先真实加载一次重建清单
        //    （仅首次部署 / 新启用 / 文件变更后的第一个请求），之后恢复按需加载
        foreach (self::$order as $n) {
            if (isset($stale[$n]) || empty(self::$manifest[$n]['stats'])) self::loadPlugin($n);
        }
        self::saveCache();
    }

    // ---------- 元数据 / 清单缓存 ----------

    private static function sigOf(string $name, ?int $dirMtime = null): array
    {
        return [
            'd' => $dirMtime ?? (int)@filemtime(self::$dir . '/' . $name),
            'm' => (int)@filemtime(self::$dir . '/' . $name . '/main.php'),
            'j' => (int)@filemtime(self::$dir . '/' . $name . '/plugin.json'),
        ];
    }

    private static function readMeta(string $name): array
    {
        $f = self::$dir . '/' . $name . '/plugin.json';
        return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }

    private static function cacheFile(): string { return self::$cacheDir . '/plugins.json'; }

    /**
     * 缓存用 JSON + file_get_contents，不用 include：
     * php-cgi 的 OPcache 会按路径缓存 include 文件的编译结果，本机重校验有数十秒延迟，
     * 重写后的清单可能读到旧版本导致钩子不触发；json 直读完全绕开该问题。
     */
    private static function readCache(): array
    {
        $f = self::cacheFile();
        if (!is_file($f)) return ['plugins' => []];
        $c = json_decode((string)file_get_contents($f), true);
        return (is_array($c) && isset($c['plugins']) && is_array($c['plugins'])) ? $c : ['plugins' => []];
    }

    private static function blankManifestParts(): array
    {
        return ['hooks' => [], 'routes' => [], 'pages' => [],
                'assets' => ['css' => [], 'js' => []],
                'stats' => ['hooks' => 0, 'routes' => 0, 'pages' => 0]];
    }

    /** 原子落盘缓存（tmp + rename，多进程并发下后写覆盖，内容等价无害） */
    private static function saveCache(): void
    {
        if (!self::$dirty || self::$cacheDir === '') return;
        if (!is_dir(self::$cacheDir)) @mkdir(self::$cacheDir, 0775, true);
        $data = ['v' => 1, 'plugins' => []];
        $keep = array_flip(['hooks', 'routes', 'pages', 'assets', 'stats', 'sig']);
        foreach (self::$manifest as $n => $m) {
            $data['plugins'][$n] = array_intersect_key($m, $keep)
                + ['sig' => self::sigOf($n), 'meta' => self::$meta[$n] ?? []]
                + self::blankManifestParts();
        }
        $tmp = self::cacheFile() . '.' . (string)getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
            @rename($tmp, self::cacheFile());
        }
        self::$dirty = false;
    }

    // ---------- 按需加载 ----------

    /** 加载单个插件的 main.php：记录注册增量 → 合并进清单 → 落盘缓存 */
    private static function loadPlugin(string $name): void
    {
        if (!empty(self::$loaded[$name])) return;
        self::$loaded[$name] = true;
        $main = self::$dir . '/' . $name . '/main.php';
        if (!is_file($main)) return;

        self::$pending = ['hooks' => [], 'routes' => [], 'pages' => [], 'assets' => ['css' => [], 'js' => []]];
        self::$loading = $name;
        try { require $main; } catch (Throwable $e) { Sec::log('plugin_error', $name, ['error' => $e->getMessage()]); }
        self::$loading = '';
        $p = self::$pending;
        self::$pending = null;

        $stats = ['hooks' => count($p['hooks']), 'routes' => count($p['routes']), 'pages' => count($p['pages'])];
        self::$stats[$name] = $stats;
        $old = self::$manifest[$name] ?? null;
        $m = ($old ?? []) + self::blankManifestParts() + ['sig' => []];
        foreach (['hooks', 'routes', 'pages'] as $k) {
            $m[$k] = array_values(array_unique(array_merge($m[$k], $p[$k])));
        }
        foreach (['css', 'js'] as $t) {
            $m['assets'][$t] = array_values(array_unique(array_merge($m['assets'][$t] ?? [], $p['assets'][$t])));
        }
        $m['stats'] = $stats;
        $m['sig'] = self::sigOf($name);
        self::$manifest[$name] = $m;
        // 内容与签名都未变化时不落盘（后台页每次请求都会加载全部页面型插件，避免重复写缓存）
        if ($old === null || $old !== $m) {
            self::$dirty = true;
            self::saveCache();
        }
    }

    // ---------- 注册接口（加载期被 main.php 调用） ----------

    public static function on(string $hook, callable $fn): void
    {
        self::$hooks[$hook][] = $fn;
        if (self::$pending !== null) self::$pending['hooks'][] = $hook;
    }

    public static function route(string $action, callable $fn): void
    {
        self::$routes[$action] = $fn;
        if (self::$pending !== null) self::$pending['routes'][] = $action;
    }

    public static function adminPage(string $slug, string $title, callable $fn): void
    {
        self::$adminPages[$slug] = ['title' => $title, 'fn' => $fn];
        if (self::$pending !== null) self::$pending['pages'][] = $slug;
    }

    public static function asset(string $type, string $path): void
    {
        if (!in_array($type, ['css', 'js'], true)) return;
        self::$assets[$type][] = $path;
        if (self::$pending !== null && !in_array($path, self::$pending['assets'][$type], true)) {
            self::$pending['assets'][$type][] = $path;
        }
    }

    // ---------- 运行时触发（按清单懒加载涉及的插件） ----------

    public static function fire(string $hook, array $args = []): void
    {
        foreach (self::$order as $name) {
            if (empty(self::$loaded[$name])
                && in_array($hook, self::$manifest[$name]['hooks'] ?? [], true)) {
                self::loadPlugin($name);
            }
        }
        foreach (self::$hooks[$hook] ?? [] as $fn) {
            try { $fn(...$args); } catch (Throwable $e) { /* 插件异常不影响主流程 */ }
        }
    }

    public static function dispatch(string $action, array $ctx)
    {
        foreach (self::$order as $name) {
            if (empty(self::$loaded[$name])
                && in_array($action, self::$manifest[$name]['routes'] ?? [], true)) {
                self::loadPlugin($name);
            }
        }
        if (isset(self::$routes[$action])) return call_user_func(self::$routes[$action], $ctx);
        return null;
    }

    /** 后台页集合：先按清单把声明了后台页的启用插件加载进来，再返回（保持既有行为） */
    public static function adminPages(): array
    {
        foreach (self::$order as $name) {
            if (empty(self::$loaded[$name]) && !empty(self::$manifest[$name]['pages'])) self::loadPlugin($name);
        }
        return self::$adminPages;
    }

    // ---------- 资源合并（清单驱动，无需加载 main.php） ----------

    public static function renderAssets(string $type): string
    {
        if (!in_array($type, ['css', 'js'], true)) return '';
        // 启用插件清单中的资源（未加载的插件也算上）+ 本请求运行时注册的资源
        $files = [];
        foreach (self::$order as $name) {
            foreach (self::$manifest[$name]['assets'][$type] ?? [] as $p) $files[] = $p;
        }
        foreach (self::$assets[$type] ?? [] as $p) $files[] = $p;
        $files = array_values(array_unique($files));

        // 合并缓存：参与文件集合与 mtime 未变则直接复用
        $sig = [];
        foreach ($files as $p) {
            $f = self::$dir . '/' . ltrim($p, '/');
            $sig[$p] = is_file($f) ? (int)@filemtime($f) : 0;
        }
        $key = md5(json_encode([$type, $sig]));
        $cfile = self::$cacheDir !== '' ? self::$cacheDir . '/assets-' . $key . '.json' : '';
        if ($cfile !== '' && is_file($cfile)) {
            $c = json_decode((string)file_get_contents($cfile), true);
            if (is_array($c) && ($c['sig'] ?? null) === $sig) return (string)$c['out'];
        }
        $out = '';
        foreach ($files as $p) {
            $f = self::$dir . '/' . ltrim($p, '/');
            if (is_file($f)) $out .= file_get_contents($f) . "\n";
        }
        if ($cfile !== '') {
            if (!is_dir(self::$cacheDir)) @mkdir(self::$cacheDir, 0775, true);
            $tmp = $cfile . '.' . (string)getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode(['sig' => $sig, 'out' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
                @rename($tmp, $cfile);
                foreach (glob(self::$cacheDir . '/assets-*.json') ?: [] as $old) {   // 清理过期合并缓存
                    if ($old !== $cfile) @unlink($old);
                }
            }
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
            $meta = self::$meta[$name] ?? (json_decode((string)file_get_contents($file), true) ?: []);
            $on = (int)($enabled[$name] ?? 0);
            // 注册计数：清单新鲜直接用（免加载）；清单缺失/文件变更时临时加载探测
            $m = self::$manifest[$name] ?? null;
            $fresh = $m && ($m['sig'] ?? null) === self::sigOf($name) && !empty($m['stats']);
            $stats = $fresh ? $m['stats'] : self::inspect($name);
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

    /** 已启用插件的注册计数（加载时记录） */
    public static function stats(string $name): array
    {
        return self::$stats[$name] ?? ['hooks' => 0, 'routes' => 0, 'pages' => 0];
    }

    /**
     * 探测插件注册计数：临时加载 main.php 统计后完整回滚（含资源），并把清单刷新进缓存。
     * 仅用于后台列表展示；插件应保证 main.php 只做 Plugin::* 注册（见 PLUGIN.md）。
     */
    public static function inspect(string $name): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) return ['hooks' => 0, 'routes' => 0, 'pages' => 0];
        $main = self::$dir . '/' . $name . '/main.php';
        if (!is_file($main)) return ['hooks' => 0, 'routes' => 0, 'pages' => 0];
        $before = self::snapshot();
        $beforeAssets = self::$assets;
        self::$pending = ['hooks' => [], 'routes' => [], 'pages' => [], 'assets' => ['css' => [], 'js' => []]];
        self::$loading = $name;
        try { require $main; $stats = ['hooks' => count(self::$pending['hooks']), 'routes' => count(self::$pending['routes']), 'pages' => count(self::$pending['pages'])]; }
        catch (Throwable $e) { $stats = ['hooks' => 0, 'routes' => 0, 'pages' => 0]; }
        $p = self::$pending;
        self::$pending = null;
        self::$loading = '';
        // 回滚全部注册（探测不生效，包括资源）
        self::$hooks = $before['hooks'];
        self::$routes = $before['routes'];
        self::$adminPages = $before['pages'];
        self::$assets = $beforeAssets;
        // 刷新清单与签名（后续后台列表免探测）
        $m = (self::$manifest[$name] ?? []) + self::blankManifestParts() + ['sig' => []];
        foreach (['hooks', 'routes', 'pages'] as $k) {
            $m[$k] = array_values(array_unique(array_merge($m[$k], $p[$k])));
        }
        foreach (['css', 'js'] as $t) {
            $m['assets'][$t] = array_values(array_unique(array_merge($m['assets'][$t] ?? [], $p['assets'][$t])));
        }
        $m['stats'] = $stats;
        $m['sig'] = self::sigOf($name);
        self::$manifest[$name] = $m;
        self::$dirty = true;
        self::saveCache();
        return $stats;
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
        unset(self::$manifest[$name], self::$meta[$name], self::$loaded[$name]);
        self::$dirty = true;
        self::saveCache();
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
