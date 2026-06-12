<?php
// ============================================================
// Maventech Admin Panel - Configuration
// Edit DB credentials and app settings below
// ============================================================

// ---------- Database ----------
define('DB_HOST', 'localhost');
define('DB_NAME', 'maventech_admin');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ---------- Application ----------
define('APP_NAME', 'Maventech Admin');
define('COMPANY_NAME', 'Maventech Software');
define('COMPANY_STATEMENT_NAME', 'MAVENTECH SOFTWARE'); // exact name on customer's card statement
define('COMPANY_LOGO_URL', 'assets/img/logo.svg');
define('SUPPORT_EMAIL', 'support@maventech.com');
define('SUPPORT_PHONE', '+1 (800) 555-1234');

// ---------- URLs ----------
// Update BASE_URL to match your deployment path (no trailing slash)
define('BASE_URL', 'http://localhost/maventech-php');

// ---------- Email ----------
define('MAIL_FROM_EMAIL', 'no-reply@maventech.com');
define('MAIL_FROM_NAME', 'Maventech Software');

// ---------- Security ----------
define('SESSION_LIFETIME', 60 * 60 * 2); // 2 hours
define('ENCRYPTION_KEY', 'change-this-32-byte-secret-key!!'); // change to a 32-byte string

// ---------- Misc ----------
define('TAX_RATE', 0.0);          // 0 = no tax; set to 0.18 for 18% etc.
define('CURRENCY', 'USD');
define('CURRENCY_SYMBOL', '$');
date_default_timezone_set('UTC');

// ============================================================
// PDO connection
// ============================================================
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

// ============================================================
// Session
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(SESSION_LIFETIME);
    session_start();
}

require_once __DIR__ . '/functions.php';
