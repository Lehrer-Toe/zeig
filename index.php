<?php
require_once 'config.php';

// Redirect wenn bereits eingeloggt
if (isLoggedIn()) {
    $user = getCurrentUser();
    redirectByUserType($user['user_type']);
}

$error = '';
$success = '';

// Login verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Sicherheitsfehler. Bitte versuchen Sie es erneut.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            $error = 'Bitte geben Sie E-Mail und Passwort ein.';
        } else {
            $user = authenticateUser($email, $password);
            if ($user) {
                // Check if school is active (for non-superadmins)
                if ($user['user_type'] !== 'superadmin' && $user['school_id']) {
                    $school = getSchoolById($user['school_id']);
                    if (!$school || !$school['is_active'] || $school['license_until'] < date('Y-m-d')) {
                        $error = 'Zugang zur Schule ist deaktiviert oder die Lizenz ist abgelaufen.';
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
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login – <?php echo APP_NAME; ?></title>
  <style>
    /* Box-Sizing für alle Elemente */
    *, *::before, *::after {
      box-sizing: border-box;
    }

    html, body {
      margin: 0;
      padding: 0;
      width: 100%;
      height: 100%;
      background: #0f172a;
      overflow: hidden;
      font-family: 'Segoe UI', sans-serif;
    }

    canvas {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      z-index: 0;
      display: block;
    }

    .login-wrapper {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: 380px;
      padding: 2.5rem;
      background: rgba(0, 0, 0, 0.7);
      border-radius: 2rem;
      box-shadow: 0 0 25px rgba(0,0,0,0.6);
      z-index: 1;
      color: white;
    }

    .login-wrapper h2 {
      margin: 0 0 1.8rem;
      text-align: center;
      color: #3b82f6;
      font-size: 1.8rem;
    }

    .input-group {
      position: relative;
      margin-bottom: 1.5rem;
      width: 100%;
    }

    .input-group input {
      width: 100%;
      padding: 0.9rem 2.5rem;
      background: rgba(255,255,255,0.1);
      border: 1px solid rgba(255,255,255,0.2);
      border-radius: 2rem;
      color: white;
      font-size: 1rem;
      outline: none;
      transition: all 0.3s ease;
    }

    .input-group input:focus {
      background: rgba(255,255,255,0.15);
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .input-group input::placeholder {
      color: #cbd5e1;
    }

    .input-group i,
    .input-group .show-password {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      font-style: normal;
      font-size: 1rem;
      color: #cbd5e1;
      pointer-events: none;
    }

    .input-group i {
      left: 1rem;
    }

    .input-group .show-password {
      right: 1rem;
      cursor: pointer;
      pointer-events: auto;
      transition: color 0.3s ease;
    }

    .input-group .show-password:hover {
      color: white;
    }

    .login-wrapper button {
      width: 100%;
      padding: 0.9rem;
      background: linear-gradient(135deg, #3b82f6, #1d4ed8);
      border: none;
      border-radius: 2rem;
      color: white;
      font-size: 1rem;
      font-weight: bold;
      cursor: pointer;
      transition: all 0.3s ease;
      margin-top: 1rem;
    }

    .login-wrapper button:hover {
      background: linear-gradient(135deg, #1d4ed8, #1e40af);
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }

    .login-wrapper button:active {
      transform: translateY(0);
    }

    .error-message {
      background: rgba(239, 68, 68, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.3);
      color: #fca5a5;
      padding: 0.8rem 1.2rem;
      border-radius: 1rem;
      margin-bottom: 1rem;
      font-size: 0.9rem;
      text-align: center;
    }

    .success-message {
      background: rgba(34, 197, 94, 0.1);
      border: 1px solid rgba(34, 197, 94, 0.3);
      color: #86efac;
      padding: 0.8rem 1.2rem;
      border-radius: 1rem;
      margin-bottom: 1rem;
      font-size: 0.9rem;
      text-align: center;
    }

    .app-title {
      text-align: center;
      margin-bottom: 2rem;
      color: white;
      font-size: 1.1rem;
      opacity: 0.9;
    }

    .footer {
      position: absolute;
      bottom: 2rem;
      left: 50%;
      transform: translateX(-50%);
      color: rgba(255,255,255,0.5);
      font-size: 0.8rem;
      text-align: center;
    }
  </style>
</head>
<body>

<canvas id="bgCanvas"></canvas>

<div class="login-wrapper">
  <div class="app-title"><?php echo APP_NAME; ?></div>
  <h2>Anmelden</h2>
  
  <?php if ($error): ?>
    <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  
  <?php if ($success): ?>
    <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>
  
  <form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    
    <div class="input-group">
      <i>📧</i>
      <input type="email" name="email" placeholder="E-Mail" required 
             value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
    </div>
    
    <div class="input-group">
      <i>🔒</i>
      <input type="password" id="passwordInput" name="password" placeholder="Passwort" required>
      <span class="show-password" onclick="togglePassword()">👁️</span>
    </div>
    
    <button type="submit">ANMELDEN</button>
  </form>
</div>

<div class="footer">
  © 2025 <?php echo APP_NAME; ?> - Version <?php echo APP_VERSION; ?>
</div>

<script>
  function togglePassword() {
    const inp = document.getElementById('passwordInput');
    const icon = document.querySelector('.show-password');
    if (inp.type === 'password') {
      inp.type = 'text';
      icon.textContent = '🙈';
    } else {
      inp.type = 'password';
      icon.textContent = '👁️';
    }
  }

  const canvas = document.getElementById('bgCanvas');
  const ctx = canvas.getContext('2d');

  function resize() {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    drawPattern();
  }

  function drawPattern() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    // Gradient background
    const gradient = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
    gradient.addColorStop(0, '#0f172a');
    gradient.addColorStop(1, '#1e293b');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    
    // Animated particles
    ctx.strokeStyle = 'rgba(59, 130, 246, 0.1)';
    ctx.lineWidth = 1;
    for (let i = 0; i < 100; i++) {
      const x1 = Math.random() * canvas.width;
      const y1 = Math.random() * canvas.height;
      const x2 = x1 + (Math.random() * 80 - 40);
      const y2 = y1 + (Math.random() * 80 - 40);
      ctx.beginPath();
      ctx.moveTo(x1, y1);
      ctx.lineTo(x2, y2);
      ctx.stroke();
    }
  }

  window.addEventListener('resize', resize);
  resize();
  
  // Redraw pattern every 5 seconds for subtle animation
  setInterval(drawPattern, 5000);
</script>

</body>
</html>