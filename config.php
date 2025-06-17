<?php
session_start();

// App-Konfiguration
define('APP_NAME', 'Zeig, was du kannst!');
define('APP_VERSION', '1.0.0');

// BASE_URL automatisch ermitteln
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$baseUrl = $protocol . $host . $scriptDir;

// Trailing slash entfernen falls vorhanden
define('BASE_URL', rtrim($baseUrl, '/'));

// ROOT_PATH für Backup-Funktionen definieren
define('ROOT_PATH', __DIR__);

// Datenbank-Konfiguration
define('DB_HOST', 'localhost');
define('DB_NAME', 'd043fe53');
define('DB_USER', 'd043fe53');
define('DB_PASS', '@Madita2011');
define('DB_CHARSET', 'utf8mb4');

// Session und Security Konfiguration
define('SESSION_LIFETIME', 3600 * 2); // 2 Stunden
define('PASSWORD_MIN_LENGTH', 8);

// FUNCTIONS.PHP LADEN
$functionsPath = __DIR__ . '/php/functions.php';
if (file_exists($functionsPath)) {
    require_once $functionsPath;
} else {
    // Fallback: Kritische Funktionen direkt definieren falls functions.php fehlt
    if (!function_exists('escape')) {
        function escape($string) {
            if ($string === null || $string === '') {
                return '';
            }
            return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
        }
    }
    
    if (!function_exists('sendJsonResponse')) {
        function sendJsonResponse($data, $status = 200) {
            http_response_code($status);
            header('Content-Type: application/json');
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    if (!function_exists('sendSuccessResponse')) {
        function sendSuccessResponse($message = 'Erfolgreich', $data = null) {
            $response = ['success' => true, 'message' => $message];
            if ($data !== null) {
                $response['data'] = $data;
            }
            sendJsonResponse($response);
        }
    }
    
    if (!function_exists('sendErrorResponse')) {
        function sendErrorResponse($message = 'Ein Fehler ist aufgetreten', $status = 400) {
            sendJsonResponse(['success' => false, 'message' => $message], $status);
        }
    }
    
    if (!function_exists('redirectWithMessage')) {
        function redirectWithMessage($url, $message, $type = 'info') {
            $_SESSION['flash_message'] = $message;
            $_SESSION['flash_type'] = $type;
            header('Location: ' . $url);
            exit;
        }
    }
    
    if (!function_exists('getFlashMessage')) {
        function getFlashMessage() {
            if (isset($_SESSION['flash_message'])) {
                $message = $_SESSION['flash_message'];
                $type = $_SESSION['flash_type'] ?? 'info';
                
                unset($_SESSION['flash_message']);
                unset($_SESSION['flash_type']);
                
                return ['message' => $message, 'type' => $type];
            }
            
            return null;
        }
    }
}

// PDO-Verbindung mit Error Handling
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Detaillierte Fehlermeldung für Debugging
    error_log("Database connection failed: " . $e->getMessage());
    die("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
}

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Hilfsfunktionen
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_type']);
}

function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    global $pdo;
    
    switch ($_SESSION['user_type']) {
        case 'superadmin':
            $stmt = $pdo->prepare("SELECT id, email, 'superadmin' as user_type, NULL as school_id, name FROM users WHERE id = ? AND user_type = 'superadmin'");
            break;
        case 'schuladmin':
            $stmt = $pdo->prepare("SELECT id, email, 'schuladmin' as user_type, school_id, name FROM users WHERE id = ? AND user_type = 'schuladmin'");
            break;
        case 'lehrer':
            $stmt = $pdo->prepare("SELECT id, email, 'lehrer' as user_type, school_id, name FROM users WHERE id = ? AND user_type = 'lehrer'");
            break;
        case 'student':
            $stmt = $pdo->prepare("SELECT id, email, 'student' as user_type, school_id FROM students WHERE id = ?");
            break;
        default:
            return null;
    }
    
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function redirectByUserType($userType) {
    switch ($userType) {
        case 'superadmin':
            header('Location: superadmin/dashboard.php');
            break;
        case 'schuladmin':
            header('Location: admin/dashboard.php');
            break;
        case 'lehrer':
            header('Location: lehrer/dashboard.php');
            break;
        case 'student':
            header('Location: student/dashboard.php');
            break;
        default:
            header('Location: index.php');
    }
    exit();
}

function authenticateUser($email, $password) {
    global $pdo;
    
    // Check Superadmin
    try {
        $stmt = $pdo->prepare("SELECT id, email, password_hash as password, name FROM users WHERE email = ? AND user_type = 'superadmin' AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            return ['id' => $user['id'], 'email' => $user['email'], 'user_type' => 'superadmin', 'school_id' => null, 'name' => $user['name']];
        }
    } catch (Exception $e) {
        error_log("Superadmin check failed: " . $e->getMessage());
    }
    
    // Check normale Users (schuladmin, lehrer)
    $stmt = $pdo->prepare("SELECT id, email, password_hash, user_type, school_id, name FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        return [
            'id' => $user['id'], 
            'email' => $user['email'], 
            'user_type' => $user['user_type'], 
            'school_id' => $user['school_id'],
            'name' => $user['name']
        ];
    }
    
    return false;
}

function loginUser($user) {
    // Session regenerieren für Sicherheit
    session_regenerate_id(true);
    
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_type'] = $user['user_type'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['name'] = $user['name'];
    if (isset($user['school_id'])) {
        $_SESSION['school_id'] = $user['school_id'];
    }
    $_SESSION['login_time'] = time();
}

function logoutUser() {
    // Session-Variablen löschen
    $_SESSION = array();
    
    // Session-Cookie löschen
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Session zerstören
    session_destroy();
    
    // Neue Session starten für Flash Messages
    session_start();
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../index.php');
        exit();
    }
}

function requireSchuladmin() {
    requireLogin();
    $user = getCurrentUser();
    if (!$user || $user['user_type'] !== 'schuladmin') {
        header('Location: ../index.php');
        exit();
    }
    return $user;
}

function requireTeacher() {
    requireLogin();
    $user = getCurrentUser();
    if (!$user || $user['user_type'] !== 'lehrer') {
        header('Location: ../index.php');
        exit();
    }
    return $user;
}

function requireSuperadmin() {
    requireLogin();
    $user = getCurrentUser();
    if (!$user || $user['user_type'] !== 'superadmin') {
        header('Location: ../index.php');
        exit();
    }
    return $user;
}

// Funktionen für Database Helper
function getDB() {
    global $pdo;
    return $pdo;
}

function getUserByEmail($email) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    return $stmt->fetch();
}

function getUserById($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getSchoolById($schoolId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
    $stmt->execute([$schoolId]);
    return $stmt->fetch();
}

function requireValidSchoolLicense($schoolId) {
    $school = getSchoolById($schoolId);
    if (!$school || !$school['is_active']) {
        die('Schule ist nicht aktiv.');
    }
    if ($school['license_until'] < date('Y-m-d')) {
        die('Schullizenz ist abgelaufen.');
    }
    return $school;
}

// CSRF Token Funktionen
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generateCSRFToken() {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

// Flash Message Funktionen (falls nicht in functions.php definiert)
if (!function_exists('setFlashMessage')) {
    function setFlashMessage($message, $type = 'info') {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }
}

// Klassen-Funktionen
function canCreateClass($schoolId) {
    $school = getSchoolById($schoolId);
    if (!$school) return false;
    
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM classes WHERE school_id = ? AND is_active = 1");
    $stmt->execute([$schoolId]);
    $currentCount = $stmt->fetch()['count'];
    
    return $currentCount < $school['max_classes'];
}

function checkSchoolLicense($schoolId) {
    $school = getSchoolById($schoolId);
    return $school && $school['is_active'] && $school['license_until'] >= date('Y-m-d');
}

// Schüler-Funktionen
function getClassStudentsSimple($classId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, first_name, last_name 
        FROM students 
        WHERE class_id = ? AND is_active = 1
        ORDER BY last_name, first_name
    ");
    $stmt->execute([$classId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fehlerbehandlung für Development
if (!defined('PRODUCTION')) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}
?>