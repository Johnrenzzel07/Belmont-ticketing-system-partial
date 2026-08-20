<?php
/**
 * Database Configuration
 * Belmont Helpdesk System
 */

// Set timezone to match MySQL server (Asia/Manila = UTC+8)
date_default_timezone_set('Asia/Manila');

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'belmont_helpdesk');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'Belmont Online Ticketing System');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('APP_URL', $protocol . '://' . $host . '/Belmont-ticketing-system');

define('APP_VERSION', '1.0.0');
define('COMPANY', 'Cebu Belmont, Inc.');

define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024); // 10 MB
define('UPLOAD_ALLOWED', [
    'image/jpeg',
    'image/png',
    'image/gif',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
]);

// ---- Email / SMTP Configuration ----
// Set MAIL_ENABLED to true once you have SMTP credentials configured
define('MAIL_ENABLED', false);
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-helpdesk@gmail.com');   // <-- change this
define('SMTP_PASS', '');                           // <-- Gmail App Password
define('MAIL_FROM', 'your-helpdesk@gmail.com');   // <-- change this
define('MAIL_FROM_NAME', COMPANY . ' Helpdesk');

// ---- AI Configuration (multi-provider with automatic failover) ----
// The system tries each provider in order. If one hits rate limits (429)
// or fails, it automatically moves to the next. All are free tier.
//
// Provider 1: Groq   — https://console.groq.com/keys
// Provider 2: Cerebras — https://cloud.cerebras.ai  (Settings > API Keys)
// Provider 3: SambaNova — https://cloud.sambanova.ai (API Keys)
//
// Leave any API key empty to skip that provider.
// If ALL providers fail, the system falls back to the keyword engine.
define('AI_ENABLED', true);
define('AI_TIMEOUT', 30); // seconds to wait per provider attempt

// Provider chain — order matters (first working provider wins)
define('AI_PROVIDERS', json_encode([
    [
        'name'  => 'Groq',
        'key'   => '', // Set your Groq API key here
        'model' => 'openai/gpt-oss-20b',
        'url'   => 'https://api.groq.com/openai/v1/chat/completions',
    ],
    [
        'name'  => 'Groq',
        'key'   => '', // Set your Groq API key here
        'model' => 'openai/gpt-oss-120b',
        'url'   => 'https://api.groq.com/openai/v1/chat/completions',
    ],
    [
        'name'  => 'Cerebras',
        'key'   => '', // Set your Cerebras API key here
        'model' => 'gpt-oss-120b',
        'url'   => 'https://api.cerebras.ai/v1/chat/completions',
    ],
    [
        'name'  => 'SambaNova',
        'key'   => '', // Set your SambaNova API key here
        'model' => 'Meta-Llama-3.3-70B-Instruct',
        'url'   => 'https://api.sambanova.ai/v1/chat/completions',
    ],
]));

// Legacy constants kept for backward compatibility
define('GROQ_API_KEY', json_decode(AI_PROVIDERS, true)[0]['key'] ?? '');
define('GROQ_MODEL', json_decode(AI_PROVIDERS, true)[0]['model'] ?? '');
define('GROQ_URL', json_decode(AI_PROVIDERS, true)[0]['url'] ?? '');
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
