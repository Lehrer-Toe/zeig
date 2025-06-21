<?php
require_once 'config.php';

// Redirect wenn bereits eingeloggt
if (isLoggedIn()) {
    $user = getCurrentUser();
    redirectByUserType($user['user_type']);
}

$error = '';
$success = '';
$showCaptcha = false;
$requireCaptcha = false;

// Flash-Messages anzeigen
if (isset($_SESSION['flash_message'])) {
    if ($_SESSION['flash_type'] === 'error') {
        $error = $_SESSION['flash_message'];
    } else {
        $success = $_SESSION['flash_message'];
    }
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// IP-Adresse ermitteln
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $clientIp = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
}

// Brute-Force-Status prüfen BEVOR das Formular verarbeitet wird
$bruteForceCheck = checkBruteForceProtection($clientIp);
if ($bruteForceCheck['blocked']) {
    $error = $bruteForceCheck['message'];
    $showCaptcha = false; // Kein CAPTCHA wenn komplett gesperrt
} else {
    $showCaptcha = $bruteForceCheck['require_captcha'];
    $requireCaptcha = $showCaptcha;
}

// CAPTCHA generieren wenn benötigt
if ($showCaptcha && !isset($_SESSION['captcha_answer'])) {
    generateMathCaptcha();
}

// Login verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bruteForceCheck['blocked']) {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Sicherheitsfehler. Bitte versuchen Sie es erneut.';
        logLoginAttempt($clientIp, null, false, 'CSRF-Fehler');
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $captchaAnswer = $_POST['captcha'] ?? '';
        
        // Basis-Validierung
        if (empty($email) || empty($password)) {
            $error = 'Bitte geben Sie E-Mail und Passwort ein.';
            logLoginAttempt($clientIp, $email, false, 'Leere Felder');
        } 
        // CAPTCHA-Validierung wenn erforderlich
        elseif ($requireCaptcha && !validateCaptcha($captchaAnswer)) {
            $error = 'Das CAPTCHA wurde nicht korrekt gelöst. Bitte versuchen Sie es erneut.';
            logLoginAttempt($clientIp, $email, false, 'CAPTCHA-Fehler');
            generateMathCaptcha(); // Neues CAPTCHA generieren
        } 
        else {
            // Progressive Verzögerung vor Authentifizierung
            $delay = calculateLoginDelay($clientIp, $email);
            if ($delay > 0) {
                sleep($delay);
            }
            
            // Benutzer authentifizieren
            $user = authenticateUserWithProtection($email, $password, $clientIp);
            if ($user) {
                // Erfolgreicher Login
                logLoginAttempt($clientIp, $email, true, 'Erfolgreich');
                clearLoginAttempts($clientIp, $email);
                
                // Schullizenz prüfen (für non-superadmins)
                if ($user['user_type'] !== 'superadmin' && $user['school_id']) {
                    $school = getSchoolById($user['school_id']);
                    if (!$school || !$school['is_active'] || $school['license_until'] < date('Y-m-d')) {
                        $error = 'Zugang zur Schule ist deaktiviert oder die Lizenz ist abgelaufen.';
                        logLoginAttempt($clientIp, $email, false, 'Schule inaktiv');
                    } else {
                        loginUser($user);
                        redirectByUserType($user['user_type']);
                    }
                } else {
                    loginUser($user);
                    redirectByUserType($user['user_type']);
                }
            } else {
                $error = 'Ungültige Anmeldedaten.';
                logLoginAttempt($clientIp, $email, false, 'Falsche Credentials');
                
                // CAPTCHA anzeigen nach mehreren Fehlversuchen
                $newCheck = checkBruteForceProtection($clientIp, $email);
                $showCaptcha = $newCheck['require_captcha'];
                $requireCaptcha = $showCaptcha;
                
                if ($showCaptcha) {
                    generateMathCaptcha();
                }
            }
        }
    }
}

// CAPTCHA neu generieren bei Bedarf
if ($showCaptcha && !isset($_SESSION['captcha_answer'])) {
    generateMathCaptcha();
}

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
                'message' => "Zu viele fehlgeschlagene Login-Versuche. Versuchen Sie es in {$minutes} Minute(n) erneut."
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
                'message' => "Account temporär gesperrt. Zu viele fehlgeschlagene Login-Versuche."
            ];
        }
        
        return [
            'blocked' => false,
            'require_captcha' => $requireCaptcha,
            'message' => ''
        ];
        
    } catch (Exception $e) {
        error_log("Brute force check error: " . $e->getMessage());
        return [
            'blocked' => false,
            'require_captcha' => false,
            'message' => ''
        ];
    }
}

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
        
        // Alte Einträge bereinigen (älter als 24h)
        if (rand(1, 100) === 1) {
            $stmt = $db->prepare("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $stmt->execute();
        }
        
    } catch (Exception $e) {
        error_log("Log login attempt error: " . $e->getMessage());
    }
}

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

/**
 * Erweiterte Authentifizierung mit Schutz
 */
function authenticateUserWithProtection($email, $password, $ip) {
    // Existierende authenticateUser-Funktion verwenden
    return authenticateUser($email, $password);
}

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

/**
 * Mathematisches CAPTCHA generieren
 */
function generateMathCaptcha() {
    $num1 = rand(1, 10);
    $num2 = rand(1, 10);
    $operation = rand(0, 1) ? '+' : '-';
    
    if ($operation === '+') {
        $answer = $num1 + $num2;
        $question = "{$num1} + {$num2}";
    } else {
        // Sicherstellen, dass Ergebnis positiv ist
        if ($num1 < $num2) {
            $temp = $num1;
            $num1 = $num2;
            $num2 = $temp;
        }
        $answer = $num1 - $num2;
        $question = "{$num1} - {$num2}";
    }
    
    $_SESSION['captcha_question'] = $question;
    $_SESSION['captcha_answer'] = $answer;
}

/**
 * CAPTCHA validieren
 */
function validateCaptcha($userAnswer) {
    if (!isset($_SESSION['captcha_answer']) || empty($userAnswer)) {
        return false;
    }
    
    $isValid = (int)$userAnswer === (int)$_SESSION['captcha_answer'];
    
    // CAPTCHA nach Validierung löschen
    unset($_SESSION['captcha_question'], $_SESSION['captcha_answer']);
    
    return $isValid;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo APP_NAME; ?> - Login</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background: linear-gradient(135deg, #999999 0%, #ff9900 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow: hidden;
    }

    /* Erweiterte Liquid Background Animation */
    .liquid-bg {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      z-index: 0;
      overflow: hidden;
    }

    .liquid-bg::before,
    .liquid-bg::after,
    .liquid-bg .blob1,
    .liquid-bg .blob2,
    .liquid-bg .blob3 {
      content: '';
      position: absolute;
      border-radius: 50%;
      filter: blur(60px);
      animation-timing-function: ease-in-out;
      animation-iteration-count: infinite;
      animation-direction: alternate;
    }

    .liquid-bg::before {
      width: 120%;
      height: 90%;
      top: 10%;
      left: -30%;
      animation: float1 45s infinite ease-in-out;
    }

    .liquid-bg::after {
      width: 100%;
      height: 110%;
      top: -20%;
      right: -30%;
      animation: float2 55s infinite ease-in-out;
    }

    .liquid-bg .blob1 {
      width: 80%;
      height: 70%;
      top: 50%;
      left: 60%;
      animation: float3 65s infinite ease-in-out;
    }

    .liquid-bg .blob2 {
      width: 90%;
      height: 60%;
      top: 5%;
      left: 10%;
      animation: float4 50s infinite ease-in-out;
    }

    .liquid-bg .blob3 {
      width: 70%;
      height: 80%;
      top: 30%;
      left: -10%;
      animation: float5 60s infinite ease-in-out;
    }

    @keyframes float1 {
      0%, 100% { transform: translate(0, 0) rotate(0deg) scale(1); }
      16% { transform: translate(15px, -20px) rotate(40deg) scale(1.05); }
      33% { transform: translate(40px, -50px) rotate(120deg) scale(1.2); }
      50% { transform: translate(20px, -30px) rotate(180deg) scale(1.1); }
      66% { transform: translate(-30px, 40px) rotate(240deg) scale(0.8); }
      83% { transform: translate(-15px, 20px) rotate(320deg) scale(0.9); }
    }

    @keyframes float2 {
      0%, 100% { transform: translate(0, 0) rotate(0deg) scale(1); }
      25% { transform: translate(-30px, 35px) rotate(90deg) scale(1.15); }
      50% { transform: translate(-60px, 70px) rotate(180deg) scale(1.3); }
      75% { transform: translate(-40px, 50px) rotate(270deg) scale(1.1); }
    }

    @keyframes float3 {
      0%, 100% { transform: translate(0, 0) scale(1); }
      12% { transform: translate(-20px, -15px) scale(1.1); }
      25% { transform: translate(-70px, -40px) scale(1.4); }
      37% { transform: translate(-50px, -25px) scale(1.25); }
      50% { transform: translate(-35px, -10px) scale(1.1); }
      62% { transform: translate(20px, 10px) scale(0.9); }
      75% { transform: translate(60px, 30px) scale(0.7); }
      87% { transform: translate(40px, 20px) scale(0.85); }
    }

    @keyframes float4 {
      0%, 100% { transform: translate(0, 0) rotate(0deg); }
      20% { transform: translate(30px, 25px) rotate(60deg); }
      40% { transform: translate(60px, 45px) rotate(120deg); }
      60% { transform: translate(80px, 60px) rotate(180deg); }
      80% { transform: translate(50px, 40px) rotate(240deg); }
    }

    @keyframes float5 {
      0%, 100% { transform: translate(0, 0) scale(1) rotate(0deg); }
      14% { transform: translate(10px, -25px) scale(1.1) rotate(40deg); }
      28% { transform: translate(30px, -80px) scale(1.5) rotate(120deg); }
      43% { transform: translate(20px, -60px) scale(1.3) rotate(180deg); }
      57% { transform: translate(-10px, -20px) scale(1.1) rotate(220deg); }
      71% { transform: translate(-50px, 60px) scale(0.6) rotate(280deg); }
      85% { transform: translate(-30px, 40px) scale(0.8) rotate(320deg); }
    }

    .login-wrapper {
      position: relative;
      z-index: 2;
      width: 100%;
      max-width: 420px;
      padding: 3rem;
      background: rgba(255, 255, 255, 0.95);
      border-radius: 25px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
      backdrop-filter: blur(10px);
      border: 2px solid rgba(255, 255, 255, 0.3);
    }

    .app-title {
      text-align: center;
      margin-bottom: 2.5rem;
      color: #002b45;
      font-size: 1.2rem;
      font-weight: 600;
      opacity: 0.8;
    }

    .login-wrapper h2 {
      text-align: center;
      margin-bottom: 2rem;
      color: #002b45;
      font-size: 2rem;
      font-weight: 700;
    }

    .input-group {
      position: relative;
      margin-bottom: 1.5rem;
    }

    .input-group input {
      width: 100%;
      padding: 1rem 1rem 1rem 3.5rem;
      border: 2px solid rgba(0, 43, 69, 0.2);
      border-radius: 25px;
      font-size: 1rem;
      background: rgba(255, 255, 255, 0.9);
      transition: all 0.3s ease;
      color: #002b45;
    }

    .input-group input:focus {
      outline: none;
      border-color: #ff9900;
      box-shadow: 0 0 0 3px rgba(255, 153, 0, 0.2);
      background: rgba(255, 255, 255, 1);
    }

    .input-group input::placeholder {
      color: rgba(0, 43, 69, 0.5);
    }

    .input-group i {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      font-style: normal;
      font-size: 1.2rem;
      color: #002b45;
      pointer-events: none;
      left: 1.2rem;
    }

    .input-group .show-password {
      right: 1.2rem;
      left: auto;
      cursor: pointer;
      pointer-events: auto;
      transition: all 0.3s ease;
    }

    .input-group .show-password:hover {
      color: #ff9900;
      transform: translateY(-50%) scale(1.1);
    }

    /* CAPTCHA Styles */
    .captcha-group {
      background: rgba(0, 43, 69, 0.05);
      border: 2px solid rgba(255, 153, 0, 0.3);
      border-radius: 15px;
      padding: 1rem;
      margin-bottom: 1.5rem;
      text-align: center;
    }

    .captcha-question {
      font-size: 1.2rem;
      font-weight: bold;
      color: #002b45;
      margin-bottom: 0.5rem;
    }

    .captcha-label {
      font-size: 0.9rem;
      color: #666;
      margin-bottom: 0.5rem;
    }

    .captcha-input {
      width: 80px !important;
      text-align: center;
      font-size: 1.1rem;
      font-weight: bold;
      margin: 0 auto;
      padding: 0.5rem !important;
    }

    .security-warning {
      background: rgba(255, 153, 0, 0.1);
      border: 2px solid rgba(255, 153, 0, 0.3);
      color: #cc7a00;
      padding: 1rem;
      border-radius: 15px;
      margin-bottom: 1.5rem;
      font-size: 0.9rem;
      text-align: center;
      font-weight: 500;
    }

    .login-wrapper button {
      width: 100%;
      padding: 1rem;
      background: #ffffff;
      border: 2px solid #ff9900;
      border-radius: 999px;
      color: #001133;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.3s ease;
      margin-top: 1.5rem;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .login-wrapper button:hover {
      background: #ff9900;
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(255, 153, 0, 0.3);
    }

    .login-wrapper button:active {
      transform: translateY(0);
    }

    .login-wrapper button:disabled {
      background: #ccc;
      border-color: #ccc;
      cursor: not-allowed;
      transform: none;
    }

    .error-message {
      background: rgba(231, 76, 60, 0.1);
      border: 2px solid rgba(231, 76, 60, 0.3);
      color: #c0392b;
      padding: 1rem 1.5rem;
      border-radius: 15px;
      margin-bottom: 1.5rem;
      font-size: 0.9rem;
      text-align: center;
      font-weight: 500;
    }

    .success-message {
      background: rgba(46, 204, 113, 0.1);
      border: 2px solid rgba(46, 204, 113, 0.3);
      color: #27ae60;
      padding: 1rem 1.5rem;
      border-radius: 15px;
      margin-bottom: 1.5rem;
      font-size: 0.9rem;
      text-align: center;
      font-weight: 500;
    }

    .footer {
      position: absolute;
      bottom: 1.5rem;
      left: 50%;
      transform: translateX(-50%);
      color: rgba(255, 255, 255, 0.9);
      font-size: 0.85rem;
      text-align: center;
      font-weight: 500;
      text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
      z-index: 3;
      backdrop-filter: blur(5px);
      padding: 0.5rem 1rem;
      border-radius: 20px;
      background: rgba(0, 0, 0, 0.1);
    }

    /* Responsive Design */
    @media (max-width: 480px) {
      .login-wrapper {
        width: 90%;
        padding: 2rem;
      }

      .login-wrapper h2 {
        font-size: 1.6rem;
      }
    }

    /* Subtle floating animation für Login-Box */
    @keyframes float-login {
      0%, 100% { transform: translateY(0px); }
      50% { transform: translateY(-5px); }
    }

    .login-wrapper {
      animation: float-login 6s ease-in-out infinite;
    }
  </style>
</head>
<body>

<div class="liquid-bg">
  <div class="blob1"></div>
  <div class="blob2"></div>
  <div class="blob3"></div>
</div>

<div class="login-wrapper">
  <div class="app-title"><?php echo APP_NAME; ?></div>
  <h2>🔐 Anmelden</h2>
  
  <?php if ($error): ?>
    <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  
  <?php if ($success): ?>
    <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>
  
  <?php if ($showCaptcha && !$bruteForceCheck['blocked']): ?>
    <div class="security-warning">
      ⚠️ Erhöhte Sicherheit aktiv: Bitte lösen Sie das CAPTCHA
    </div>
  <?php endif; ?>
  
  <?php if (!$bruteForceCheck['blocked']): ?>
  <form method="POST" action="" id="loginForm">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    
    <div class="input-group">
      <i>📧</i>
      <input type="email" name="email" placeholder="E-Mail" required 
             value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
    </div>
    
    <div class="input-group">
      <i>🔒</i>
      <input type="password" name="password" id="password" placeholder="Passwort" required>
      <i class="show-password" onclick="togglePassword()">👁️</i>
    </div>
    
    <?php if ($showCaptcha): ?>
    <div class="captcha-group">
      <div class="captcha-question">
        🔢 <?php echo $_SESSION['captcha_question'] ?? ''; ?> = ?
      </div>
      <div class="captcha-label">Lösen Sie die Rechenaufgabe:</div>
      <input type="number" name="captcha" class="captcha-input" placeholder="?" required autocomplete="off">
    </div>
    <?php endif; ?>
    
    <button type="submit" <?php echo $bruteForceCheck['blocked'] ? 'disabled' : ''; ?>>
      🚀 Anmelden
    </button>
  </form>
  <?php endif; ?>
</div>

<div class="footer">
  <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?> • Sicher & Geschützt
</div>

<script>
function togglePassword() {
  const passwordField = document.getElementById('password');
  const eyeIcon = document.querySelector('.show-password');
  
  if (passwordField.type === 'password') {
    passwordField.type = 'text';
    eyeIcon.textContent = '🙈';
  } else {
    passwordField.type = 'password';
    eyeIcon.textContent = '👁️';
  }
}

// Form-Validierung
document.getElementById('loginForm')?.addEventListener('submit', function(e) {
  const email = document.querySelector('input[name="email"]').value;
  const password = document.querySelector('input[name="password"]').value;
  
  if (!email || !password) {
    e.preventDefault();
    alert('Bitte füllen Sie alle Felder aus.');
    return false;
  }
  
  <?php if ($showCaptcha): ?>
  const captcha = document.querySelector('input[name="captcha"]').value;
  if (!captcha) {
    e.preventDefault();
    alert('Bitte lösen Sie das CAPTCHA.');
    return false;
  }
  <?php endif; ?>
  
  // Button deaktivieren um Doppel-Submissions zu verhindern
  const submitBtn = this.querySelector('button[type="submit"]');
  submitBtn.disabled = true;
  submitBtn.textContent = '⏳ Anmeldung läuft...';
});

// Auto-Focus auf erstes leeres Feld
document.addEventListener('DOMContentLoaded', function() {
  const emailField = document.querySelector('input[name="email"]');
  const passwordField = document.querySelector('input[name="password"]');
  const captchaField = document.querySelector('input[name="captcha"]');
  
  if (emailField && !emailField.value) {
    emailField.focus();
  } else if (passwordField && !passwordField.value) {
    passwordField.focus();
  } else if (captchaField) {
    captchaField.focus();
  }
});

// Schutz vor zu schnellen Submissions
let lastSubmitTime = 0;
document.getElementById('loginForm')?.addEventListener('submit', function(e) {
  const now = Date.now();
  if (now - lastSubmitTime < 2000) { // 2 Sekunden Pause
    e.preventDefault();
    alert('Bitte warten Sie einen Moment zwischen den Anmeldeversuchen.');
    return false;
  }
  lastSubmitTime = now;
});

// === ERWEITERTE BLOB-ANIMATION ===

// Theme-Farben definieren
const themeColors = [
  { primary: '#ff9900', secondary: '#ffcc66' },
  { primary: '#002b45', secondary: '#063b52' },
  { primary: '#999999', secondary: '#cccccc' },
  { primary: '#063b52', secondary: '#002b45' },
  { primary: '#ffaa33', secondary: '#ff9900' }
];

const transparentColors = [
  'rgba(255, 153, 0, 0.7)',
  'rgba(0, 43, 69, 0.7)',
  'rgba(153, 153, 153, 0.7)',
  'rgba(6, 59, 82, 0.7)',
  'rgba(255, 170, 51, 0.6)'
];

// Zufällige Farbverteilung beim Laden
function randomizeColors() {
  // Zufällige Hintergrundfarbe
  const bgColors = ['#999999', '#888888', '#aaaaaa', '#777777', '#bbbbbb'];
  document.body.style.background = bgColors[Math.floor(Math.random() * bgColors.length)];

  // Zufällige Farben für die Blobs
  const color1 = themeColors[Math.floor(Math.random() * themeColors.length)];
  const color2 = themeColors[Math.floor(Math.random() * themeColors.length)];
  const color3 = themeColors[Math.floor(Math.random() * themeColors.length)];
  const color4 = themeColors[Math.floor(Math.random() * themeColors.length)];
  const color5 = transparentColors[Math.floor(Math.random() * transparentColors.length)];

  // Dynamische CSS-Regeln erstellen
  const style = document.createElement('style');
  style.textContent = `
    .liquid-bg::before {
      background: radial-gradient(circle, ${color1.primary}, ${color1.secondary});
    }
    .liquid-bg::after {
      background: radial-gradient(circle, ${color2.primary}, ${color2.secondary});
    }
    .liquid-bg .blob1 {
      background: radial-gradient(circle, ${color3.primary} 30%, transparent 70%);
    }
    .liquid-bg .blob2 {
      background: radial-gradient(circle, ${color4.primary}, ${color4.secondary});
    }
    .liquid-bg .blob3 {
      background: radial-gradient(circle, ${color5}, transparent);
    }
  `;
  document.head.appendChild(style);
}

// Zusätzliche statische flüssige Elemente hinzufügen
function createFloatingElements() {
  const container = document.querySelector('.liquid-bg');
  for (let i = 0; i < 6; i++) {
    const element = document.createElement('div');
    const size = 80 + Math.random() * 120;
    const duration = 40 + Math.random() * 30;
    const delay = Math.random() * 20;
    
    // Zufällige Farbe aus dem Theme
    const randomColor = transparentColors[Math.floor(Math.random() * transparentColors.length)];
    
    element.style.cssText = `
      position: absolute;
      width: ${size}px;
      height: ${size}px;
      background: radial-gradient(circle, ${randomColor}, transparent);
      border-radius: 50%;
      filter: blur(40px);
      left: ${Math.random() * 100}%;
      top: ${Math.random() * 100}%;
      animation: liquidFloat${i % 3} ${duration}s ease-in-out infinite ${delay}s;
    `;
    container.appendChild(element);
  }
}

// CSS für zusätzliche Bewegungsanimationen
const additionalAnimations = document.createElement('style');
additionalAnimations.textContent = `
  @keyframes liquidFloat0 {
    0%, 100% { transform: translate(0, 0) scale(1); }
    12% { transform: translate(20px, -30px) scale(1.1); }
    25% { transform: translate(60px, -80px) scale(1.3); }
    37% { transform: translate(40px, -60px) scale(1.2); }
    50% { transform: translate(-10px, -20px) scale(1.05); }
    62% { transform: translate(-30px, 30px) scale(0.9); }
    75% { transform: translate(-50px, 60px) scale(0.7); }
    87% { transform: translate(-25px, 35px) scale(0.85); }
  }
  
  @keyframes liquidFloat1 {
    0%, 100% { transform: translate(0, 0) scale(1) rotate(0deg); }
    16% { transform: translate(-20px, 15px) scale(1.1) rotate(45deg); }
    33% { transform: translate(-50px, 35px) scale(1.25) rotate(90deg); }
    50% { transform: translate(-80px, 50px) scale(1.4) rotate(180deg); }
    66% { transform: translate(-60px, 35px) scale(1.2) rotate(270deg); }
    83% { transform: translate(-30px, 20px) scale(1.05) rotate(315deg); }
  }
  
  @keyframes liquidFloat2 {
    0%, 100% { transform: translate(0, 0) scale(1); }
    14% { transform: translate(15px, -25px) scale(0.9); }
    28% { transform: translate(40px, -70px) scale(0.8); }
    43% { transform: translate(25px, -50px) scale(0.85); }
    57% { transform: translate(-20px, -10px) scale(1.1); }
    71% { transform: translate(-70px, -30px) scale(1.3); }
    85% { transform: translate(-45px, -20px) scale(1.15); }
  }
`;
document.head.appendChild(additionalAnimations);

// Beim Laden der Seite initialisieren
randomizeColors();
createFloatingElements();
</script>

</body>
</html>