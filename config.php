<?php
session_start();

// App-Konfiguration
define('APP_NAME', 'Zeig, was du kannst!');
define('APP_VERSION', '1.0.0');

// Datenbank-Konfiguration
define('DB_HOST', 'localhost');
define('DB_NAME', 'd043fe53');  // Korrigierter Datenbankname
define('DB_USER', 'd043fe53');
define('DB_PASS', '@Madita2011');
define('DB_CHARSET', 'utf8mb4');  // Hinzugefügte Charset-Definition

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
            $stmt = $pdo->prepare("SELECT id, email, 'superadmin' as user_type, NULL as school_id FROM superadmins WHERE id = ?");
            break;
        case 'schuladmin':  // Korrigierter user_type Name
            $stmt = $pdo->prepare("SELECT id, email, 'schuladmin' as user_type, school_id FROM users WHERE id = ? AND user_type = 'schuladmin'");
            break;
        case 'lehrer':  // Korrigierter user_type Name
            $stmt = $pdo->prepare("SELECT id, email, 'lehrer' as user_type, school_id FROM users WHERE id = ? AND user_type = 'lehrer'");
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
        case 'schuladmin':  // Korrigierter user_type Name
            header('Location: admin/dashboard.php');
            break;
        case 'lehrer':  // Korrigierter user_type Name
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
    
    // Check Superadmin (falls superadmins Tabelle existiert)
    try {
        $stmt = $pdo->prepare("SELECT id, email, password_hash as password FROM users WHERE email = ? AND user_type = 'superadmin' AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            return ['id' => $user['id'], 'email' => $user['email'], 'user_type' => 'superadmin', 'school_id' => null];
        }
    } catch (Exception $e) {
        error_log("Superadmin check failed: " . $e->getMessage());
    }
    
    // Check normale Users (schuladmin, lehrer)
    $stmt = $pdo->prepare("SELECT id, email, password_hash, user_type, school_id FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        return [
            'id' => $user['id'], 
            'email' => $user['email'], 
            'user_type' => $user['user_type'], 
            'school_id' => $user['school_id']
        ];
    }
    
    return false;
}

function loginUser($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_type'] = $user['user_type'];
    $_SESSION['user_email'] = $user['email'];
    if (isset($user['school_id'])) {
        $_SESSION['school_id'] = $user['school_id'];
    }
}

function logoutUser() {
    session_destroy();
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

// Fehlerbehandlung für Development
if (!defined('PRODUCTION')) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}
?>
