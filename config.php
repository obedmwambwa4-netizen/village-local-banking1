<?php
// ============================================================
// VCBI shared configuration — C:\xampp\htdocs\localbanking\api\config.php
// No blank lines before the opening php tag. No closing tag.
// ============================================================

if (!defined('DB_HOST'))    define('DB_HOST', 'localhost');
if (!defined('DB_NAME'))    define('DB_NAME', 'vcbi_banking');
if (!defined('DB_USER'))    define('DB_USER', 'root');   // XAMPP default
if (!defined('DB_PASS'))    define('DB_PASS', '');       // XAMPP default (empty)
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

if (!function_exists('getDB')) {
    function getDB(): PDO {
        static $pdo = null;
        if ($pdo === null) {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }
        return $pdo;
    }
}

// ---- JSON API helpers (used by every file in /api) ----------
if (!function_exists('apiHeaders')) {
    function apiHeaders(): void {
        // Never print PHP warnings as HTML inside a JSON response
        ini_set('display_errors', '0');
        error_reporting(E_ALL);

        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        // Any uncaught problem becomes readable JSON instead of an HTML error page
        set_exception_handler(function (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
            exit;
        });
        register_shutdown_function(function () {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json'); }
                echo json_encode(['success' => false, 'error' => 'Fatal error: ' . $err['message']]);
            }
        });
    }
}

if (!function_exists('respond')) {
    function respond($data, int $code = 200): void {
        http_response_code($code);
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('error')) {
    function error(string $message, int $code = 400): void {
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => $message, 'message' => $message]);
        exit;
    }
}
