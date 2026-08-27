<?php
/**
 * Database Configuration
 * Belmont Helpdesk System
 */

// Set timezone to match MySQL server (Asia/Manila = UTC+8)
date_default_timezone_set('Asia/Manila');

/**
 * Lightweight .env file loader for environment configuration
 */
(function () {
    $envFile = __DIR__ . '/../.env';
    if (!file_exists($envFile) || !is_readable($envFile)) {
        return;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }
            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
})();

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($val === false || $val === null || $val === '') {
            return $default;
        }
        $lower = strtolower((string)$val);
        if ($lower === 'true') return true;
        if ($lower === 'false') return false;
        if ($lower === 'null') return null;
        return $val;
    }
}

define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_PORT', (int)env('DB_PORT', 3306));
define('DB_NAME', env('DB_NAME', 'belmont_helpdesk'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

define('APP_NAME', env('APP_NAME', 'Belmont Online Ticketing System'));

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('APP_URL', $protocol . '://' . $host . '/Belmont-ticketing-system');

define('APP_VERSION', '1.0.0');
define('COMPANY', env('COMPANY', 'Cebu Belmont, Inc.'));

define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024); // 10 MB
define('UPLOAD_ALLOWED', [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain',
    'text/csv',
    'application/zip',
    'application/x-zip-compressed'
]);

// ---- Email / SMTP Configuration ----
define('MAIL_ENABLED', (bool)env('MAIL_ENABLED', false));
define('SMTP_HOST', env('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', (int)env('SMTP_PORT', 587));
define('SMTP_USER', env('SMTP_USER', 'your-helpdesk@gmail.com'));
define('SMTP_PASS', env('SMTP_PASS', ''));
define('MAIL_FROM', env('MAIL_FROM', 'your-helpdesk@gmail.com'));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', COMPANY . ' Helpdesk'));

// ---- AI Configuration (multi-provider with automatic failover) ----
define('AI_ENABLED', (bool)env('AI_ENABLED', true));
define('AI_TIMEOUT', (int)env('AI_TIMEOUT', 30));

$aiProviders = [];

$groqKey = env('GROQ_API_KEY', '');
if ($groqKey) {
    $aiProviders[] = [
        'name'  => 'Groq',
        'key'   => $groqKey,
        'model' => env('GROQ_MODEL', 'openai/gpt-oss-20b'),
        'url'   => 'https://api.groq.com/openai/v1/chat/completions',
    ];
    $aiProviders[] = [
        'name'  => 'Groq',
        'key'   => $groqKey,
        'model' => 'openai/gpt-oss-120b',
        'url'   => 'https://api.groq.com/openai/v1/chat/completions',
    ];
}

$cerebrasKey = env('CEREBRAS_API_KEY', '');
if ($cerebrasKey) {
    $aiProviders[] = [
        'name'  => 'Cerebras',
        'key'   => $cerebrasKey,
        'model' => env('CEREBRAS_MODEL', 'gpt-oss-120b'),
        'url'   => 'https://api.cerebras.ai/v1/chat/completions',
    ];
}

$sambanovaKey = env('SAMBANOVA_API_KEY', '');
if ($sambanovaKey) {
    $aiProviders[] = [
        'name'  => 'SambaNova',
        'key'   => $sambanovaKey,
        'model' => env('SAMBANOVA_MODEL', 'Meta-Llama-3.3-70B-Instruct'),
        'url'   => 'https://api.sambanova.ai/v1/chat/completions',
    ];
}

// Provider chain — order matters (first working provider wins)
define('AI_PROVIDERS', json_encode($aiProviders));

// Legacy constants kept for backward compatibility
define('GROQ_API_KEY', $aiProviders[0]['key'] ?? '');
define('GROQ_MODEL', $aiProviders[0]['model'] ?? '');
define('GROQ_URL', $aiProviders[0]['url'] ?? '');
define('GROQ_TIMEOUT', AI_TIMEOUT);


class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_FOUND_ROWS => true,
            ];
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Never expose DB credentials in production
                die(json_encode(['error' => 'Database connection failed. Please contact your administrator.']));
            }
        }
        return self::$instance;
    }

    private function __construct()
    {
    }
    private function __clone()
    {
    }
}

function db(): PDO
{
    return Database::getInstance();
}
