<?php
/**
 * Erweiterte Authentifizierungsfunktionen für "Zeig, was du kannst"
 * Mit Session-Timeout, Session-Begrenzung und Brute-Force-Schutz
 */

if (!function_exists('authenticateUser')) {
    /**
     * User authentifizieren mit erweiterten Sicherheitschecks
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
}

if (!function_exists('loginUser')) {
    /**
     * User einloggen mit erweitertem Session-Management
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
        $_SESSION['last_activity'] = time();
        
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
        
        // Session in Datenbank speichern mit Begrenzung
        $db = getDB();
        $sessionId = session_id();
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        try {
            // Alte Sessions begrenzen BEVOR neue Session erstellt wird
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
            
            // Login-Meldung für benutzer
            if (defined('MAX_CONCURRENT_SESSIONS') && MAX_CONCURRENT_SESSIONS > 0) {
                $_SESSION['flash_message'] = "Erfolgreich angemeldet. Sie haben maximal " . MAX_CONCURRENT_SESSIONS . " gleichzeitige Sessions.";
                $_SESSION['flash_type'] = 'success';
            }
            
        } catch (Exception $e) {
            error_log("Error saving session: " . $e->getMessage());
        }
    }
}

if (!function_exists('logoutUser')) {
    /**
     * User ausloggen mit vollständiger Session-Bereinigung
     */
    function logoutUser() {
        if (isset($_SESSION['user_id'])) {
            // Session aus Datenbank löschen
            $db = getDB();
            try {
                $stmt = $db->prepare("DELETE FROM user_sessions WHERE id = ?");
                $stmt->execute([session_id()]);
            } catch (Exception $e) {
                error_log("Logout error: " . $e->getMessage());
            }
        }
        
        // Flash-Messages und CSRF-Token zwischenspeichern
        $flashMessage = $_SESSION['flash_message'] ?? null;
        $flashType = $_SESSION['flash_type'] ?? null;
        $csrfToken = $_SESSION['csrf_token'] ?? null;
        
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
        
        // Wichtige Daten wiederherstellen
        if ($flashMessage) {
            $_SESSION['flash_message'] = $flashMessage;
            $_SESSION['flash_type'] = $flashType;
        }
        if ($csrfToken) {
            $_SESSION['csrf_token'] = $csrfToken;
        } else {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }
}

if (!function_exists('isLoggedIn')) {
    /**
     * Prüfen ob User eingeloggt ist (mit erweiterten Timeout-Checks)
     */
    function isLoggedIn() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['login_time'])) {
            return false;
        }
        
        // Session-Inaktivitäts-Timeout prüfen
        $lastActivity = $_SESSION['last_activity'] ?? $_SESSION['login_time'];
        if (defined('SESSION_INACTIVITY_TIMEOUT') && (time() - $lastActivity) > SESSION_INACTIVITY_TIMEOUT) {
            logoutUser();
            $_SESSION['flash_message'] = 'Ihre Session ist aufgrund von Inaktivität abgelaufen.';
            $_SESSION['flash_type'] = 'warning';
            return false;
        }
        
        // Absoluter Session-Timeout prüfen
        if (defined('SESSION_LIFETIME') && (time() - $_SESSION['login_time']) > SESSION_LIFETIME) {
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
        
        // Gelegentlich abgelaufene Sessions aufräumen (5% Chance)
        if (rand(1, 100) <= 5) {
            cleanupExpiredSessions();
        }
        
        return true;
    }
}

// ===== BRUTE-FORCE-SCHUTZ FUNKTIONEN =====

if (!function_exists('checkBruteForceProtection')) {
    /**
     * Brute-Force-Schutz prüfen
     */
    function checkBruteForceProtection($ip, $email = null) {
        $db = getDB();
        
        try {
            // Account-Sperrung prüfen
            $stmt = $db->prepare("
                SELECT locked_until, attempts_count, reason 
                FROM account_lockouts 
                WHERE (identifier = ? AND identifier_type = 'ip') 
                   OR (identifier = ? AND identifier_type = 'email')
                   AND locked_until > NOW()
                ORDER BY locked_until DESC 
                LIMIT 1
            ");
            $stmt->execute([$ip, $email]);
            $lockout = $stmt->fetch();
            
            if ($lockout) {
                $remainingTime = strtotime($lockout['locked_until']) - time();
                $minutes = ceil($remainingTime / 60);
                return [
                    'blocked' => true,
                    'require_captcha' => false,
                    'message' => "Zu viele fehlgeschlagene Login-Versuche. Versuchen Sie es in {$minutes} Minute(n) erneut.",
                    'remaining_time' => $remainingTime
                ];
            }
            
            // Fehlversuche der letzten 15 Minuten zählen
            $stmt = $db->prepare("
                SELECT COUNT(*) as failed_attempts 
                FROM login_attempts 
                WHERE (ip_address = ? OR email = ?) 
                  AND success = 0 
                  AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ");
            $stmt->execute([$ip, $email]);
            $failedAttempts = $stmt->fetchColumn();
            
            // CAPTCHA ab 3 Fehlversuchen
            $requireCaptcha = $failedAttempts >= 3;
            
            // Sperrung ab 5 Fehlversuchen
            if ($failedAttempts >= 5) {
                createAccountLockout($ip, $email, $failedAttempts);
                return [
                    'blocked' => true,
                    'require_captcha' => false,
                    'message' => "Account temporär gesperrt. Zu viele fehlgeschlagene Login-Versuche.",
                    'remaining_time' => 300 // 5 Minuten default
                ];
            }
            
            return [
                'blocked' => false,
                'require_captcha' => $requireCaptcha,
                'message' => '',
                'failed_attempts' => $failedAttempts
            ];
            
        } catch (Exception $e) {
            error_log("Brute force check error: " . $e->getMessage());
            return [
                'blocked' => false,
                'require_captcha' => false,
                'message' => '',
                'failed_attempts' => 0
            ];
        }
    }
}

if (!function_exists('createAccountLockout')) {
    /**
     * Account-Sperrung erstellen
     */
    function createAccountLockout($ip, $email, $attempts) {
        $db = getDB();
        
        try {
            // Progressive Sperrzeit: 5 Min, 15 Min, 30 Min, 1h, 2h
            $lockoutMinutes = min(5 * pow(2, max(0, $attempts - 5)), 120);
            $lockoutUntil = date('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));
            
            // IP-Sperrung
            $stmt = $db->prepare("
                INSERT INTO account_lockouts (identifier, identifier_type, locked_until, attempts_count, reason) 
                VALUES (?, 'ip', ?, ?, 'Brute Force Protection')
                ON DUPLICATE KEY UPDATE 
                locked_until = VALUES(locked_until), 
                attempts_count = VALUES(attempts_count)
            ");
            $stmt->execute([$ip, $lockoutUntil, $attempts]);
            
            // E-Mail-Sperrung (falls vorhanden)
            if ($email) {
                $stmt = $db->prepare("
                    INSERT INTO account_lockouts (identifier, identifier_type, locked_until, attempts_count, reason) 
                    VALUES (?, 'email', ?, ?, 'Brute Force Protection')
                    ON DUPLICATE KEY UPDATE 
                    locked_until = VALUES(locked_until), 
                    attempts_count = VALUES(attempts_count)
                ");
                $stmt->execute([$email, $lockoutUntil, $attempts]);
            }
            
            error_log("Account lockout created for IP {$ip} and email {$email} for {$lockoutMinutes} minutes");
            
        } catch (Exception $e) {
            error_log("Create lockout error: " . $e->getMessage());
        }
    }
}

if (!function_exists('logLoginAttempt')) {
    /**
     * Login-Versuch protokollieren
     */
    function logLoginAttempt($ip, $email, $success, $reason = null) {
        $db = getDB();
        
        try {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $stmt = $db->prepare("
                INSERT INTO login_attempts (ip_address, email, success, user_agent, reason) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$ip, $email, $success ? 1 : 0, $userAgent, $reason]);
            
            // Alte Einträge bereinigen (älter als 24h) - gelegentlich
            if (rand(1, 100) === 1) {
                $stmt = $db->prepare("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                $stmt->execute();
                
                // Abgelaufene Account-Lockouts bereinigen
                $stmt = $db->prepare("DELETE FROM account_lockouts WHERE locked_until < NOW()");
                $stmt->execute();
            }
            
        } catch (Exception $e) {
            error_log("Log login attempt error: " . $e->getMessage());
        }
    }
}

if (!function_exists('calculateLoginDelay')) {
    /**
     * Progressive Login-Verzögerung berechnen
     */
    function calculateLoginDelay($ip, $email) {
        $db = getDB();
        
        try {
            // Fehlversuche der letzten 5 Minuten
            $stmt = $db->prepare("
                SELECT COUNT(*) as recent_attempts 
                FROM login_attempts 
                WHERE (ip_address = ? OR email = ?) 
                  AND success = 0 
                  AND attempt_time > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ");
            $stmt->execute([$ip, $email]);
            $recentAttempts = $stmt->fetchColumn();
            
            // Progressive Verzögerung: 0s, 1s, 2s, 3s, 5s
            if ($recentAttempts <= 1) return 0;
            if ($recentAttempts == 2) return 1;
            if ($recentAttempts == 3) return 2;
            if ($recentAttempts == 4) return 3;
            return 5; // Max 5 Sekunden
            
        } catch (Exception $e) {
            error_log("Calculate delay error: " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('clearLoginAttempts')) {
    /**
     * Login-Versuche nach erfolgreichem Login löschen
     */
    function clearLoginAttempts($ip, $email) {
        $db = getDB();
        
        try {
            // Fehlgeschlagene Versuche löschen
            $stmt = $db->prepare("
                DELETE FROM login_attempts 
                WHERE (ip_address = ? OR email = ?) AND success = 0
            ");
            $stmt->execute([$ip, $email]);
            
            // Account-Sperrungen aufheben
            $stmt = $db->prepare("
                DELETE FROM account_lockouts 
                WHERE identifier IN (?, ?) 
            ");
            $stmt->execute([$ip, $email]);
            
        } catch (Exception $e) {
            error_log("Clear login attempts error: " . $e->getMessage());
        }
    }
}

if (!function_exists('getLoginAttemptStatistics')) {
    /**
     * Login-Attempt-Statistiken für Admin-Dashboard
     */
    function getLoginAttemptStatistics($hours = 24) {
        $db = getDB();
        
        try {
            // Gesamte Versuche
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_attempts,
                    SUM(success) as successful_attempts,
                    COUNT(*) - SUM(success) as failed_attempts
                FROM login_attempts 
                WHERE attempt_time > DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stmt->execute([$hours]);
            $totals = $stmt->fetch();
            
            // Top Failed IPs
            $stmt = $db->prepare("
                SELECT ip_address, COUNT(*) as failed_count
                FROM login_attempts 
                WHERE success = 0 
                  AND attempt_time > DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY ip_address 
                ORDER BY failed_count DESC 
                LIMIT 10
            ");
            $stmt->execute([$hours]);
            $topFailedIPs = $stmt->fetchAll();
            
            // Aktuelle Sperrungen
            $stmt = $db->prepare("
                SELECT identifier, identifier_type, locked_until, attempts_count, reason
                FROM account_lockouts 
                WHERE locked_until > NOW()
                ORDER BY locked_until DESC
            ");
            $stmt->execute();
            $currentLockouts = $stmt->fetchAll();
            
            // Versuche pro Stunde
            $stmt = $db->prepare("
                SELECT 
                    HOUR(attempt_time) as hour,
                    COUNT(*) as attempts,
                    SUM(success) as successful
                FROM login_attempts 
                WHERE attempt_time > DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY HOUR(attempt_time)
                ORDER BY hour
            ");
            $stmt->execute([$hours]);
            $hourlyStats = $stmt->fetchAll();
            
            return [
                'totals' => $totals,
                'top_failed_ips' => $topFailedIPs,
                'current_lockouts' => $currentLockouts,
                'hourly_stats' => $hourlyStats
            ];
            
        } catch (Exception $e) {
            error_log("Login statistics error: " . $e->getMessage());
            return [
                'totals' => ['total_attempts' => 0, 'successful_attempts' => 0, 'failed_attempts' => 0],
                'top_failed_ips' => [],
                'current_lockouts' => [],
                'hourly_stats' => []
            ];
        }
    }
}

if (!function_exists('unlockAccount')) {
    /**
     * Account-Sperrung manuell aufheben (für Admins)
     */
    function unlockAccount($identifier, $identifierType = null) {
        $db = getDB();
        
        try {
            if ($identifierType) {
                $stmt = $db->prepare("DELETE FROM account_lockouts WHERE identifier = ? AND identifier_type = ?");
                $result = $stmt->execute([$identifier, $identifierType]);
            } else {
                $stmt = $db->prepare("DELETE FROM account_lockouts WHERE identifier = ?");
                $result = $stmt->execute([$identifier]);
            }
            
            if ($result) {
                error_log("Manual account unlock for: {$identifier} ({$identifierType})");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Unlock account error: " . $e->getMessage());
            return false;
        }
    }
}

// ===== BESTEHENDE SESSION-MANAGEMENT FUNKTIONEN =====

if (!function_exists('limitUserSessions')) {
    /**
     * Begrenzte Sessions pro User - alte Sessions bei Überschreitung löschen
     */
    function limitUserSessions($userId, $currentSessionId) {
        if (!defined('MAX_CONCURRENT_SESSIONS') || MAX_CONCURRENT_SESSIONS <= 0) {
            return; // Feature deaktiviert
        }
        
        $db = getDB();
        try {
            // Anzahl aktiver Sessions für diesen User prüfen
            $stmt = $db->prepare("
                SELECT id, created_at
                FROM user_sessions 
                WHERE user_id = ? AND expires_at > NOW()
                ORDER BY created_at ASC
            ");
            $stmt->execute([$userId]);
            $sessions = $stmt->fetchAll();
            
            // Wenn zu viele Sessions, älteste löschen (außer der aktuellen)
            if (count($sessions) >= MAX_CONCURRENT_SESSIONS) {
                $sessionsToDelete = [];
                $deletedCount = 0;
                
                foreach ($sessions as $session) {
                    if ($session['id'] !== $currentSessionId && $deletedCount < (count($sessions) - MAX_CONCURRENT_SESSIONS + 1)) {
                        $sessionsToDelete[] = $session['id'];
                        $deletedCount++;
                    }
                }
                
                if (!empty($sessionsToDelete)) {
                    $placeholders = str_repeat('?,', count($sessionsToDelete) - 1) . '?';
                    $stmt = $db->prepare("DELETE FROM user_sessions WHERE id IN ($placeholders)");
                    $stmt->execute($sessionsToDelete);
                    
                    // Log für Admin
                    error_log("Deleted " . count($sessionsToDelete) . " old sessions for user " . $userId);
                }
            }
        } catch (Exception $e) {
            error_log("Session limit error: " . $e->getMessage());
        }
    }
}

if (!function_exists('cleanupExpiredSessions')) {
    /**
     * Session-Aufräumung - abgelaufene Sessions löschen
     */
    function cleanupExpiredSessions() {
        $db = getDB();
        try {
            $stmt = $db->prepare("DELETE FROM user_sessions WHERE expires_at < NOW()");
            $deletedRows = $stmt->execute();
            
            if ($deletedRows) {
                error_log("Cleaned up expired sessions");
            }
        } catch (Exception $e) {
            error_log("Session cleanup error: " . $e->getMessage());
        }
    }
}

// ===== BESTEHENDE FUNKTIONEN (unverändert) =====

if (!function_exists('changePassword')) {
    /**
     * Passwort ändern
     */
    function changePassword($userId, $newPassword) {
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $db = getDB();
        $stmt = $db->prepare("
            UPDATE users 
            SET password_hash = ?, first_login = 0, password_set_by_admin = 0, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        
        return $stmt->execute([$passwordHash, $userId]);
    }
}

if (!function_exists('requireAdmin')) {
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
}

if (!function_exists('requireSchuladmin')) {
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
}

if (!function_exists('requireLehrer')) {
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
}

if (!function_exists('requireValidSchoolLicense')) {
    /**
     * Schullizenz prüfen
     */
    function requireValidSchoolLicense($schoolId) {
        $school = getSchoolById($schoolId);
        
        if (!$school || !$school['is_active']) {
            die('Schule nicht aktiv oder Lizenz abgelaufen.');
        }
        
        if ($school['license_valid_until'] && strtotime($school['license_valid_until']) < time()) {
            die('Schullizenz abgelaufen.');
        }
        
        return $school;
    }
}

// ===== WEITERE SESSION-MANAGEMENT FUNKTIONEN =====

if (!function_exists('getActiveSessionsForUser')) {
    /**
     * Aktive Sessions für einen User abrufen (für Admin-Interface)
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
}

if (!function_exists('terminateUserSession')) {
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
}

if (!function_exists('terminateAllOtherUserSessions')) {
    /**
     * Alle anderen Sessions eines Users beenden (außer der aktuellen)
     */
    function terminateAllOtherUserSessions($userId) {
        $db = getDB();
        try {
            $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ? AND id != ?");
            $count = $stmt->execute([$userId, session_id()]);
            
            if ($count) {
                $_SESSION['flash_message'] = "Alle anderen Sessions wurden beendet.";
                $_SESSION['flash_type'] = 'success';
            }
            
            return $count;
        } catch (Exception $e) {
            error_log("Terminate all sessions error: " . $e->getMessage());
            return false;
        }
    }
}