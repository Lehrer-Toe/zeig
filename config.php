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

// Session und Security Konfiguration - ERWEITERT
define('SESSION_LIFETIME', 1800); // 30 Minuten (statt 2 Stunden)
define('SESSION_INACTIVITY_TIMEOUT', 1800); // 30 Minuten Inaktivität
define('MAX_CONCURRENT_SESSIONS', 3); // Max. 3 gleichzeitige Sessions pro User
define('PASSWORD_MIN_LENGTH', 8);

// Basis Security Headers (korrigiert - ohne problematische Permissions-Policy Features)
if (!headers_sent()) {
    // Clickjacking-Schutz
    header("X-Frame-Options: DENY");
    
    // MIME-Type Sniffing verhindern
    header("X-Content-Type-Options: nosniff");
    
    // XSS-Filter für ältere Browser
    header("X-XSS-Protection: 1; mode=block");
    
    // Referrer Policy
    header("Referrer-Policy: strict-origin-when-cross-origin");
    
    // Entferne Server-Informationen
    header_remove("X-Powered-By");
    
    // Korrigierte Permissions-Policy (ohne nicht unterstützte Features)
    $permissions = [
        "geolocation=()",
        "microphone=()",
        "camera=()",
        "payment=()",
        "usb=()",
        "magnetometer=()",
        "gyroscope=()",
        "accelerometer=()",
        "autoplay=()",
        "encrypted-media=()",
        "picture-in-picture=()",
        "sync-xhr=(self)",
        "fullscreen=(self)"
    ];
    header("Permissions-Policy: " . implode(', ', $permissions));
    
    // Einfache Content-Security-Policy
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self';");
}

// WICHTIG: db.php suchen und laden
$dbLoaded = false;
$possiblePaths = [
    __DIR__ . '/db.php',
    __DIR__ . '/php/db.php',
    dirname(__DIR__) . '/db.php',
    dirname(__DIR__) . '/php/db.php'
];

foreach ($possiblePaths as $dbPath) {
    if (file_exists($dbPath)) {
        require_once $dbPath;
        $dbLoaded = true;
        break;
    }
}

// NUR wenn db.php NICHT geladen wurde, Fallback definieren
if (!$dbLoaded) {
    class Database {
        private static $instance = null;
        private $pdo;
        
        private function __construct() {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];
                $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                die("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
            }
        }
        
        public static function getInstance() {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        public function getConnection() {
            return $this->pdo;
        }
    }
    
    function getDB() {
        return Database::getInstance()->getConnection();
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
}

// Functions.php laden
$functionsPath = __DIR__ . '/php/functions.php';
if (!file_exists($functionsPath)) {
    $functionsPath = dirname(__DIR__) . '/php/functions.php';
}
if (file_exists($functionsPath)) {
    require_once $functionsPath;
}

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== ERWEITERTE SESSION-SICHERHEIT =====

/**
 * Session-Aufräumung - abgelaufene Sessions löschen
 */
function cleanupExpiredSessions() {
    $db = getDB();
    try {
        $stmt = $db->prepare("DELETE FROM user_sessions WHERE expires_at < NOW()");
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Session cleanup error: " . $e->getMessage());
    }
}

/**
 * Begrenzte Sessions pro User - alte Sessions bei Überschreitung löschen
 */
function limitUserSessions($userId, $currentSessionId) {
    $db = getDB();
    try {
        // Anzahl aktiver Sessions für diesen User prüfen
        $stmt = $db->prepare("
            SELECT COUNT(*) as session_count, 
                   GROUP_CONCAT(id ORDER BY created_at ASC) as session_ids
            FROM user_sessions 
            WHERE user_id = ? AND expires_at > NOW()
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        if ($result['session_count'] >= MAX_CONCURRENT_SESSIONS) {
            // Älteste Sessions löschen (außer der aktuellen)
            $sessionIds = explode(',', $result['session_ids']);
            $sessionsToDelete = array_diff($sessionIds, [$currentSessionId]);
            
            // Nur die ältesten löschen, sodass MAX_CONCURRENT_SESSIONS - 1 übrig bleiben
            $deleteCount = count($sessionsToDelete) - (MAX_CONCURRENT_SESSIONS - 1);
            if ($deleteCount > 0) {
                $sessionsToDeleteLimited = array_slice($sessionsToDelete, 0, $deleteCount);
                $placeholders = str_repeat('?,', count($sessionsToDeleteLimited) - 1) . '?';
                $stmt = $db->prepare("DELETE FROM user_sessions WHERE id IN ($placeholders)");
                $stmt->execute($sessionsToDeleteLimited);
            }
        }
    } catch (Exception $e) {
        error_log("Session limit error: " . $e->getMessage());
    }
}

/**
 * Erweiterte isLoggedIn-Funktion mit Session-Timeout
 */
function isLoggedIn() {
    // Basis-Check
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['login_time'])) {
        return false;
    }
    
    // Session-Timeout prüfen (Inaktivität)
    $lastActivity = $_SESSION['last_activity'] ?? $_SESSION['login_time'];
    if (time() - $lastActivity > SESSION_INACTIVITY_TIMEOUT) {
        logoutUser();
        $_SESSION['flash_message'] = 'Ihre Session ist aufgrund von Inaktivität abgelaufen.';
        $_SESSION['flash_type'] = 'warning';
        return false;
    }
    
    // Absolute Session-Lifetime prüfen
    if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
        logoutUser();
        $_SESSION['flash_message'] = 'Ihre Session ist abgelaufen. Bitte melden Sie sich erneut an.';
        $_SESSION['flash_type'] = 'warning';
        return false;
    }
    
    // Session in Datenbank prüfen
    $db = getDB();
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM user_sessions 
            WHERE id = ? AND user_id = ? AND expires_at > NOW()
        ");
        $stmt->execute([session_id(), $_SESSION['user_id']]);
        $result = $stmt->fetch();
        
        if ($result['count'] == 0) {
            logoutUser();
            $_SESSION['flash_message'] = 'Ihre Session wurde aus Sicherheitsgründen beendet.';
            $_SESSION['flash_type'] = 'warning';
            return false;
        }
    } catch (Exception $e) {
        error_log("Session validation error: " . $e->getMessage());
        return false;
    }
    
    // Aktivitätszeit aktualisieren
    $_SESSION['last_activity'] = time();
    
    // Session-Aufräumung gelegentlich durchführen (1% Chance)
    if (rand(1, 100) === 1) {
        cleanupExpiredSessions();
    }
    
    return true;
}

/**
 * Erweiterte loginUser-Funktion mit Session-Management
 */
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
    $_SESSION['last_activity'] = time();
    
    // Session in Datenbank speichern mit Begrenzung
    $db = getDB();
    $sessionId = session_id();
    $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    try {
        // Alte Sessions begrenzen
        limitUserSessions($user['id'], $sessionId);
        
        // Neue Session speichern
        $stmt = $db->prepare("
            INSERT INTO user_sessions (id, user_id, expires_at, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            expires_at = VALUES(expires_at), 
            ip_address = VALUES(ip_address), 
            user_agent = VALUES(user_agent)
        ");
        $stmt->execute([$sessionId, $user['id'], $expiresAt, $ipAddress, $userAgent]);
    } catch (Exception $e) {
        error_log("Login session error: " . $e->getMessage());
    }
}

/**
 * Erweiterte logoutUser-Funktion
 */
function logoutUser() {
    // Session aus Datenbank löschen
    if (isset($_SESSION['user_id'])) {
        $db = getDB();
        try {
            $stmt = $db->prepare("DELETE FROM user_sessions WHERE id = ?");
            $stmt->execute([session_id()]);
        } catch (Exception $e) {
            error_log("Logout session error: " . $e->getMessage());
        }
    }
    
    // Session-Variablen löschen (außer Flash-Messages)
    $flashMessage = $_SESSION['flash_message'] ?? null;
    $flashType = $_SESSION['flash_type'] ?? null;
    $csrfToken = $_SESSION['csrf_token'] ?? null;
    
    $_SESSION = array();
    
    // Flash-Messages und CSRF-Token wiederherstellen
    if ($flashMessage) {
        $_SESSION['flash_message'] = $flashMessage;
        $_SESSION['flash_type'] = $flashType;
    }
    if ($csrfToken) {
        $_SESSION['csrf_token'] = $csrfToken;
    }
    
    // Session-Cookie löschen
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Session zerstören und neu starten
    session_destroy();
    session_start();
    
    // CSRF-Token neu generieren
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== BESTEHENDE FUNKTIONEN (unverändert) =====

function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $db = getDB();
    
    switch ($_SESSION['user_type']) {
        case 'superadmin':
            $stmt = $db->prepare("SELECT id, email, 'superadmin' as user_type, NULL as school_id, name FROM users WHERE id = ? AND user_type = 'superadmin'");
            break;
        case 'schuladmin':
            $stmt = $db->prepare("SELECT id, email, 'schuladmin' as user_type, school_id, name FROM users WHERE id = ? AND user_type = 'schuladmin'");
            break;
        case 'lehrer':
            $stmt = $db->prepare("SELECT id, email, 'lehrer' as user_type, school_id, name FROM users WHERE id = ? AND user_type = 'lehrer'");
            break;
        case 'student':
            $stmt = $db->prepare("SELECT id, email, 'student' as user_type, school_id FROM students WHERE id = ?");
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
    $db = getDB();
    
    // Check Superadmin
    try {
        $stmt = $db->prepare("SELECT id, email, password_hash as password, name FROM users WHERE email = ? AND user_type = 'superadmin' AND is_active = 1");
        $stmt->execute([$email]);
        $superadmin = $stmt->fetch();
        
        if ($superadmin && password_verify($password, $superadmin['password'])) {
            return [
                'id' => $superadmin['id'], 
                'email' => $superadmin['email'], 
                'user_type' => 'superadmin', 
                'school_id' => null,
                'name' => $superadmin['name']
            ];
        }
    } catch (Exception $e) {
        error_log("Superadmin auth error: " . $e->getMessage());
    }
    
    // Check normale Users (schuladmin, lehrer)
    $stmt = $db->prepare("SELECT id, email, password_hash, user_type, school_id, name FROM users WHERE email = ? AND is_active = 1");
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

// CSRF Token Funktionen
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generateCSRFToken() {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

// Zusätzliche Helper-Funktionen
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

// Neue Sicherheitsfunktionen

/**
 * Session-Informationen für Admin-Dashboard
 */
function getActiveSessionsForUser($userId) {
    $db = getDB();
    try {
        $stmt = $db->prepare("
            SELECT id, ip_address, user_agent, created_at, expires_at,
                   CASE WHEN id = ? THEN 1 ELSE 0 END as is_current
            FROM user_sessions 
            WHERE user_id = ? AND expires_at > NOW()
            ORDER BY created_at DESC
        ");
        $stmt->execute([session_id(), $userId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Get sessions error: " . $e->getMessage());
        return [];
    }
}

/**
 * Bestimmte Session terminieren (außer der aktuellen)
 */
function terminateUserSession($sessionId, $userId) {
    // Nicht die eigene Session löschen
    if ($sessionId === session_id()) {
        return false;
    }
    
    $db = getDB();
    try {
        $stmt = $db->prepare("DELETE FROM user_sessions WHERE id = ? AND user_id = ?");
        return $stmt->execute([$sessionId, $userId]);
    } catch (Exception $e) {
        error_log("Terminate session error: " . $e->getMessage());
        return false;
    }
}

/**
 * Alle anderen Sessions eines Users beenden (außer der aktuellen)
 */
function terminateAllOtherUserSessions($userId) {
    $db = getDB();
    try {
        $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ? AND id != ?");
        return $stmt->execute([$userId, session_id()]);
    } catch (Exception $e) {
        error_log("Terminate all sessions error: " . $e->getMessage());
        return false;
    }
}