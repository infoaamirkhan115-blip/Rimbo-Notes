<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
function reply(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
set_exception_handler(function (Throwable $error): void {
    error_log('Rimbo API: ' . $error->getMessage());
    reply(500, ['error' => 'The server could not complete the request. Check the server logs.']);
});
$configFile = __DIR__ . '/config.php';
$config = is_file($configFile) ? require $configFile : [];
function config(string $key, mixed $default = null): mixed {
    global $config;
    $env = getenv('RIMBO_' . strtoupper($key));
    return $env !== false ? $env : ($config[$key] ?? $default);
}
function configBool(string $key, bool $default): bool {
    return filter_var(config($key, $default), FILTER_VALIDATE_BOOLEAN);
}
function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $name = config('db_name'); $user = config('db_user');
        if (!$name || !$user) reply(503, ['error' => 'Database is not configured. Follow README.md to configure PHP and MySQL.']);
        $pdo = new PDO('mysql:host=' . config('db_host', '127.0.0.1') . ';port=' . (int)config('db_port', 3306) . ';dbname=' . $name . ';charset=utf8mb4', $user, config('db_password', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '43200');
session_name('rimbo_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => configBool('secure_cookies', true), 'httponly' => true, 'samesite' => 'Lax']);
session_start();
if (isset($_SESSION['user_id']) && (time() - ($_SESSION['last_seen'] ?? 0) > 1800 || time() - ($_SESSION['signed_in'] ?? 0) > 43200)) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
if (isset($_SESSION['user_id'])) $_SESSION['last_seen'] = time();
function requireUser(): int {
    if (!isset($_SESSION['user_id'])) reply(401, ['error' => 'Please sign in again.']);
    return (int)$_SESSION['user_id'];
}
function requireCsrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) reply(403, ['error' => 'Your session changed. Reload the page before trying again.']);
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') reply(403, ['error' => 'Cross-site requests are not allowed.']);
}
function body(): array {
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) reply(415, ['error' => 'Send application/json.']);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 12582912) reply(413, ['error' => 'Workspace is too large. Maximum request size is 12 MB.']);
    $raw = file_get_contents('php://input', false, null, 0, 12582913);
    if (strlen($raw) > 12582912) reply(413, ['error' => 'Workspace exceeds 12 MB.']);
    try { $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { reply(400, ['error' => 'Invalid JSON.']); }
    if (!is_array($data)) reply(400, ['error' => 'Expected a JSON object.']);
    return $data;
}
function throttle(string $email): void {
    $pdo = db(); $time = time();
    // Only trust REMOTE_ADDR; configure trusted proxy handling at the web server.
    foreach (['ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') => 60, 'email:' . $email => 15] as $key => $max) {
        $hash = hash('sha256', $key);
        $q = $pdo->prepare('INSERT INTO auth_limits (bucket_key,hits,expires_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=IF(expires_at <= ?,1,hits+1), expires_at=IF(expires_at <= ?,?,expires_at)');
        $q->execute([$hash, $time+900, $time, $time, $time+900]);
        $q = $pdo->prepare('SELECT hits FROM auth_limits WHERE bucket_key=?'); $q->execute([$hash]);
        if ((int)$q->fetchColumn() > $max) { header('Retry-After: 900'); reply(429, ['error' => 'Too many sign-in attempts. Try again in 15 minutes.']); }
    }
    if (random_int(1,100) === 1) $pdo->prepare('DELETE FROM auth_limits WHERE expires_at < ?')->execute([$time]);
}
function signIn(array $user): void {
    session_regenerate_id(true);
    $_SESSION = ['user_id' => (int)$user['id'], 'csrf' => bin2hex(random_bytes(32)), 'last_seen' => time(), 'signed_in' => time()];
}
function defaultSettings(string $name): array {
    return ['name'=>$name, 'review'=>true, 'quiet'=>true, 'language'=>'en-IN', 'time'=>'18:00', 'day'=>'Friday'];
}
