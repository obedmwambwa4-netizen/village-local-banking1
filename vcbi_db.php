<?php
// ============================================================
// VCBI Mobile Money — its OWN database connection.
// Separate from api/config.php so the main app is never affected.
// Save as: C:\xampp\htdocs\localbanking\api\vcbi_db.php
// (no blank lines before the opening php tag, no closing tag)
// ============================================================
if (!function_exists('getVcbiDB')) {
    function getVcbiDB(): PDO {
        static $pdo = null;
        if ($pdo === null) {
            $pdo = new PDO(
                'mysql:host=localhost;dbname=vcbi_banking;charset=utf8mb4',
                'root',   // XAMPP default user
                '',       // XAMPP default password (empty)
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
