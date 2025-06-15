<?php
/**
 * Authentifizierungsfunktionen für "Zeig, was du kannst"
 */

/**
 * User authentifizieren
 */
function authenticateUser($email, $password) {
    $user = getUserByEmail($email);
    
    if ($user && password_verify($password, $user['password_hash'])) {
        // Last login update
        $db = getDB();
        $stmt = $db->prepare("UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        return $user;
    }
    
    return false;
}

/**
 * User einloggen (Session erstellen)
 */
function loginUser($user) {
    // Session regenerieren für Sicherheit
    session_regenerate_id(true);
    
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['user_type'] = $user['user_type'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['school_id'] = $user['school_id'];
    $_SESSION['first_login'] = $user['first_login'];
    $_SESSION['login_time'] = time();
    
    // Session in Datenbank speichern
    $db = getDB();
    $sessionId = session_id();
    $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    try {
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
        error_log("Error saving session: " . $e->getMessage());
    }
}

/**
 * User ausloggen
 */
function logoutUser() {
    if (isset($_SESSION['user_id'])) {
        // Session aus Datenbank löschen
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM user_sessions WHERE id = ?");
        $stmt->execute([session_id()]);
    }
    
    // Session-Variablen löschen
    $_SESSION = array();
    
    // Session-Cookie löschen
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Session zerstören
    session_destroy();
}

/**
 * Prüfen ob User eingeloggt ist
 */
function isLoggedIn() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['login_time'])) {
        return false;
    }
    
    // Session-Timeout prüfen
    if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
        logoutUser();
        return false;
    }
    
    // Session in Datenbank prüfen
    $db = getDB();
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM user_sessions 
        WHERE id = ? AND user_id = ? AND expires_at > NOW()
    ");
    $stmt->execute([session_id(), $_SESSION['user_id']]);
    $result = $stmt->fetch();
    
    if ($result['count'] == 0) {
        logoutUser();
        return false;
    }
    
    return true;
}

/**
 * Aktuellen User aus Session holen
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'email' => $_SESSION['email'],
        'user_type' => $_SESSION['user_type'],
        'name' => $_SESSION['name'],
        'school_id' => $_SESSION['school_id'],
        'first_login' => $_SESSION['first_login']
    ];
}

/**
 * Weiterleitung basierend auf User-Typ
 */
function redirectByUserType($userType) {
    switch ($userType) {
        case 'superadmin':
            header('Location: ' . BASE_URL . '/superadmin/dashboard.php');
            break;
        case 'schuladmin':
            header('Location: ' . BASE_URL . '/admin/dashboard.php');
            break;
        case 'lehrer':
            header('Location: ' . BASE_URL . '/lehrer/dashboard.php');
            break;
        default:
            header('Location: ' . BASE_URL . '/index.php');
    }
    exit;
}

/**
 * Zugriff prüfen - nur für bestimmte User-Typen
 */
function requireUserType($allowedTypes) {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
    
    $user = getCurrentUser();
    
    if (!in_array($user['user_type'], (array)$allowedTypes)) {
        http_response_code(403);
        die('Zugriff verweigert. Sie haben keine Berechtigung für diese Seite.');
    }
    
    return $user;
}

/**
 * Superadmin-Zugriff prüfen
 */
function requireSuperadmin() {
    return requireUserType('superadmin');
}

/**
 * Schuladmin-Zugriff prüfen
 */
function requireSchuladmin() {
    return requireUserType(['superadmin', 'schuladmin']);
}

/**
 * Lehrer-Zugriff prüfen
 */
function requireLehrer() {
    return requireUserType(['superadmin', 'schuladmin', 'lehrer']);
}

/**
 * Schul-Lizenz prüfen
 */
function requireValidSchoolLicense($schoolId = null) {
    $user = getCurrentUser();
    
    if ($user['user_type'] === 'superadmin') {
        return true; // Superadmin hat immer Zugriff
    }
    
    $schoolId = $schoolId ?? $user['school_id'];
    
    if (!$schoolId) {
        die('Keine Schule zugeordnet.');
    }
    
    if (!checkSchoolLicense($schoolId)) {
        die('Der Zugang zu dieser Schule ist deaktiviert oder die Lizenz ist abgelaufen.');
    }
    
    return true;
}

/**
 * CSRF Token prüfen
 */
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Neue CSRF Token generieren
 */
function generateCSRFToken() {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

/**
 * Session-Cleanup (sollte per Cronjob regelmäßig ausgeführt werden)
 */
function cleanupExpiredSessions() {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM user_sessions WHERE expires_at < NOW()");
    return $stmt->execute();
}

/**
 * Passwort-Stärke prüfen
 */
function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = "Passwort muss mindestens " . PASSWORD_MIN_LENGTH . " Zeichen lang sein.";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Passwort muss mindestens einen Kleinbuchstaben enthalten.";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Passwort muss mindestens einen Großbuchstaben enthalten.";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Passwort muss mindestens eine Zahl enthalten.";
    }
    
    return $errors;
}

/**
 * Login-Versuche limitieren (einfache Implementierung)
 */
function checkLoginAttempts($email) {
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    
    $attempts = $_SESSION['login_attempts'][$email] ?? ['count' => 0, 'last_attempt' => 0];
    
    // Reset nach 15 Minuten
    if (time() - $attempts['last_attempt'] > 900) {
        $_SESSION['login_attempts'][$email] = ['count' => 0, 'last_attempt' => time()];
        return true;
    }
    
    return $attempts['count'] < 5;
}

function recordLoginAttempt($email, $success = false) {
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    
    if ($success) {
        unset($_SESSION['login_attempts'][$email]);
    } else {
        $attempts = $_SESSION['login_attempts'][$email] ?? ['count' => 0, 'last_attempt' => time()];
        $attempts['count']++;
        $attempts['last_attempt'] = time();
        $_SESSION['login_attempts'][$email] = $attempts;
    }
}
?>