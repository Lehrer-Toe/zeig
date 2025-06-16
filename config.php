<?php
session_start();

// App-Konfiguration
define('APP_NAME', 'Zeig, was du kannst!');
define('APP_VERSION', '1.0.0');

// Datenbank-Konfiguration
define('DB_HOST', 'localhost');
define('DB_NAME', 'school_evaluation');
define('DB_USER', 'root');
define('DB_PASS', '');

// PDO-Verbindung
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
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
        case 'admin':
            $stmt = $pdo->prepare("SELECT id, email, 'admin' as user_type, school_id FROM admins WHERE id = ?");
            break;
        case 'teacher':
            $stmt = $pdo->prepare("SELECT id, email, 'teacher' as user_type, school_id FROM teachers WHERE id = ?");
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
        case 'admin':
            header('Location: admin/dashboard.php');
            break;
        case 'teacher':
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
    $stmt = $pdo->prepare("SELECT id, email, password FROM superadmins WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'user_type' => 'superadmin',
            'school_id' => null
        ];
    }
    
    // Check Admin
    $stmt = $pdo->prepare("SELECT id, email, password, school_id FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'user_type' => 'admin',
            'school_id' => $user['school_id']
        ];
    }
    
    // Check Teacher
    $stmt = $pdo->prepare("SELECT id, email, password, school_id FROM teachers WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'user_type' => 'teacher',
            'school_id' => $user['school_id']
        ];
    }
    
    // Check Student
    $stmt = $pdo->prepare("SELECT id, email, password, school_id FROM students WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'user_type' => 'student',
            'school_id' => $user['school_id']
        ];
    }
    
    return false;
}

function loginUser($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_type'] = $user['user_type'];
    $_SESSION['school_id'] = $user['school_id'];
    $_SESSION['login_time'] = time();
}

function logout() {
    session_destroy();
    header('Location: index.php');
    exit();
}

function getSchoolById($schoolId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
    $stmt->execute([$schoolId]);
    return $stmt->fetch();
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../index.php');
        exit();
    }
}

function requireUserType($allowedTypes) {
    requireLogin();
    
    if (!is_array($allowedTypes)) {
        $allowedTypes = [$allowedTypes];
    }
    
    if (!in_array($_SESSION['user_type'], $allowedTypes)) {
        header('Location: ../index.php');
        exit();
    }
}

// Formatierungsfunktionen
function formatDate($date) {
    return date('d.m.Y', strtotime($date));
}

function formatDateTime($datetime) {
    return date('d.m.Y H:i', strtotime($datetime));
}

// Sicherheitsfunktionen
function generatePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    return substr(str_shuffle($chars), 0, $length);
}

function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}
?>