<?php
/**
 * Hauptkonfigurationsdatei für "Zeig, was du kannst"
 * Konfiguriert für Root-Installation
 */

// Session Konfiguration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Auf 1 setzen wenn HTTPS verfügbar
session_start();

// Zeitzone setzen
date_default_timezone_set('Europe/Berlin');

// Datenbankverbindung
define('DB_HOST', 'localhost');
define('DB_NAME', 'd043fe53');
define('DB_USER', 'd043fe53');
define('DB_PASS', '@Madita2011');
define('DB_CHARSET', 'utf8mb4');

// Anwendungs-Konstanten
define('APP_NAME', 'Zeig, was du kannst!');
define('APP_VERSION', '1.0.0');
define('BASE_URL', ''); // Leer für Root-Installation

// Pfad-Konstanten
define('ROOT_PATH', __DIR__);
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('PHP_PATH', ROOT_PATH . '/php');
define('ASSETS_PATH', ROOT_PATH . '/assets');

// Security
define('PASSWORD_MIN_LENGTH', 8);
define('SESSION_LIFETIME', 3600 * 8); // 8 Stunden

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Autoloader für eigene Klassen
spl_autoload_register(function ($class) {
    $file = PHP_PATH . '/classes/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Gemeinsame Funktionen laden
require_once PHP_PATH . '/functions.php';
require_once PHP_PATH . '/db.php';
require_once PHP_PATH . '/auth.php';

// CSRF Token generieren falls nicht vorhanden
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>