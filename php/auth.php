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
    
    // WICHTIG: can_create_groups für Lehrer setzen
    if ($user['user_type'] === 'lehrer') {
        // Prüfen ob die Spalte existiert und den Wert laden
        $db = getDB();
        try {
            $stmt = $db->prepare("SELECT can_create_groups FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $result = $stmt->fetch();
            $_SESSION['can_create_groups'] = isset($result['can_create_groups']) ? (int)$result['can_create_groups'] : 0;
        } catch (Exception $e) {
            // Falls Spalte nicht existiert, default auf 0
            $_SESSION['can_create_groups'] = 0;
        }
    } else {
        $_SESSION['can_create_groups'] = 0;
    }
    
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
    
    // Login-Zeit aktualisieren
    $_SESSION['login_time'] = time();
    
    return true;
}

/**
 * Passwort ändern
 */
function changePassword($userId, $newPassword) {
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    $db = getDB();
    $stmt = $db->prepare("
        UPDATE users 
        SET password_hash = ?, first_login = 0, updated_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    
    return $stmt->execute([$passwordHash, $userId]);
}

/**
 * Admin-Zugriff prüfen
 */
function requireAdmin() {
    if (!isLoggedIn() || $_SESSION['user_type'] !== 'superadmin') {
        header('Location: /index.php');
        exit();
    }
    
    return getUserById($_SESSION['user_id']);
}

/**
 * Schuladmin-Zugriff prüfen
 */
function requireSchuladmin() {
    if (!isLoggedIn() || $_SESSION['user_type'] !== 'schuladmin') {
        header('Location: /index.php');
        exit();
    }
    
    return getUserById($_SESSION['user_id']);
}

/**
 * Lehrer-Zugriff prüfen
 */
function requireLehrer() {
    if (!isLoggedIn() || $_SESSION['user_type'] !== 'lehrer') {
        header('Location: /index.php');
        exit();
    }
    
    return getUserById($_SESSION['user_id']);
}

/**
 * Schullizenz prüfen
 */
function requireValidSchoolLicense($schoolId) {
    $school = getSchoolById($schoolId);
    
    if (!$school || !$school['is_active']) {
        die('Schule nicht aktiv oder Lizenz abgelaufen.');
    }
    
    if ($school['license_valid_until'] && strtotime($school['license_valid_until']) < time()) {
        die('Schullizenz abgelaufen. Bitte kontaktieren Sie den Administrator.');
    }
    
    return true;
}
?>