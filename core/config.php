<?php
/**
 * Owlsgo-Chat 全局配置
 * 纯原生 PHP 8.1+，无框架 / 无 Composer 依赖。
 * 首次访问会自动进入安装向导；也可直接手动修改本文件后删除 data/install.lock 跳过向导。
 */
return [
    // ---------- 数据库（三选一：sqlite / mysql / pgsql）----------
    'db' => [
        'driver'   => 'sqlite',                 // sqlite | mysql | pgsql
        'sqlite'   => __DIR__ . '/../data/owlsgo.db',
        'host'     => '127.0.0.1',
        'port'     => 3306,                     // pgsql 默认 5432
        'name'     => 'owlsgo',
        'user'     => 'root',
        'pass'     => '',
        'charset'  => 'utf8mb4',
    ],

    // ---------- 安全 ----------
    'secret'     => 'CHANGE_ME_RANDOM_64_CHARS', // API 签名密钥（安装向导会自动改写为随机值）
    'session_name' => 'OWLSESSID',
    'sign_window'  => 300,                       // 签名时间窗（秒）

    // ---------- 邮件（注册验证码 / 密码找回）----------
    'mail' => [
        'driver'  => 'smtp',                    // smtp | mail（PHP mail 函数）
        'host'    => '',
        'port'    => 465,
        'secure'  => 'ssl',                     // ssl | tls | ''
        'user'    => '',
        'pass'    => '',
        'from'    => '',
        'from_name' => 'Owlsgo-Chat',
    ],

    // ---------- 上传 ----------
    'upload' => [
        'dir'        => __DIR__ . '/../uploads',
        'url'        => 'uploads',
        'max_size'   => 10 * 1024 * 1024,       // 服务端兜底 10MB
        'image_mode' => 'local',                // local（客户端压缩+本地存储）| imgbed（图床 API 压缩）
        'imgbed_api' => 'https://img.scdn.io/api/v1.php',
        'imgbed_cdn' => '',                     // 留空使用图床默认 CDN，或填 cdn_domain 参数
    ],

    // ---------- 路径 ----------
    'data_dir'    => __DIR__ . '/../data',
    'plugin_dir'  => __DIR__ . '/../plugins',
    'timezone'    => 'Asia/Shanghai',
];
