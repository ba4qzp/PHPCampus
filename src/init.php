<?php
if(!file_exists(__DIR__ . '/../.env')){ header('Location: install.php'); exit; }

$env = [];
$lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0 || trim($line) === '') continue;
    list($name, $value) = explode('=', $line, 2);
    $name = trim($name); $value = trim($value, " \"'");
    $env[$name] = $value;
    @putenv("{$name}={$value}");
    $_ENV[$name] = $value;
}

define('SITE_NAME', $env['SITE_NAME'] ?? 'PHPCampus');
define('SITE_URL', $env['SITE_URL'] ?? 'http://localhost');
define('ADMIN_EMAIL', $env['ADMIN_EMAIL'] ?? 'admin@example.com');
define('ALLOW_REGISTER', ($env['ALLOW_REGISTER'] ?? 'true') === 'true');
define('REQUIRE_APPROVAL', ($env['REQUIRE_APPROVAL'] ?? 'true') === 'true');
define('REQUIRE_EMAIL', ($env['REQUIRE_EMAIL'] ?? 'false') === 'true');
define('LIMIT_PRIVATE_MSG', ($env['LIMIT_PRIVATE_MSG'] ?? 'true') === 'true');

define('DB_HOST', $env['DB_HOST'] ?? '');
define('DB_NAME', $env['DB_NAME'] ?? '');
define('DB_USER', $env['DB_USER'] ?? '');
define('DB_PASS', $env['DB_PASS'] ?? '');

define('AI_BASE_URL', $env['AI_BASE_URL'] ?? '');
define('AI_API_KEY', $env['AI_API_KEY'] ?? '');
define('AI_MODEL', $env['AI_MODEL'] ?? '');
define('HAS_AI', (AI_BASE_URL !== '' && AI_API_KEY !== ''));

// ★ session 目录修复（InfinityFree /php_sessions 无写权限）
$sess_dir = __DIR__ . '/../sess_tmp';
if (!is_dir($sess_dir)) @mkdir($sess_dir, 0755, true);
if (is_writable($sess_dir)) { ini_set('session.save_path', $sess_dir); }
if(session_status() === PHP_SESSION_NONE) @session_start();

date_default_timezone_set('Asia/Shanghai');
if(!headers_sent()) header('X-Frame-Options: DENY');

try{
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET time_zone = '+08:00'");
}catch(PDOException $e){
    die('数据库连接失败，详细原因：' . $e->getMessage() . '<br><br>请检查你的 .env 文件是否正确，或者删掉 .env 和 install.lock 重新安装。');
}

$super_uid = 0;
try { $super_uid = (int)$pdo->query("SELECT MIN(uid) FROM users")->fetchColumn(); } catch (Exception $e) {}
define('SUPER_UID', $super_uid ?: 1);

define('APP_VERSION', 'v0.0.1-2610251');

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/layout.php';
ensure_dirs();

// ==========================================
// ★ 自动版本自愈：上传新版本后首次访问触发
// ==========================================
$heal_file = __DIR__ . '/../.healed';
$heal_ver  = @trim(file_get_contents($heal_file));
if ($heal_ver !== APP_VERSION) {
    try {
        $pdo->exec("SET NAMES utf8mb4");

        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[] = $c['Field'];
        if (!in_array('gender', $cols))     $pdo->exec("ALTER TABLE users ADD COLUMN gender VARCHAR(10) DEFAULT 'secret'");
        if (!in_array('grade', $cols))      $pdo->exec("ALTER TABLE users ADD COLUMN grade VARCHAR(20) DEFAULT ''");
        if (!in_array('class_name', $cols)) $pdo->exec("ALTER TABLE users ADD COLUMN class_name VARCHAR(20) DEFAULT ''");
        if (!in_array('show_info', $cols))  $pdo->exec("ALTER TABLE users ADD COLUMN show_info TINYINT(1) DEFAULT 0");

        foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $row = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
            $ddl = $row['Create Table'] ?? '';
            if (stripos($ddl, 'utf8mb4') === false) {
                @$pdo->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        }

        if (!is_dir($sess_dir)) @mkdir($sess_dir, 0755, true);

        @file_put_contents($heal_file, APP_VERSION);
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/../.heal_error', date('c') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
    }
}
