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
      background: #999999;
      overflow: hidden;
      font-family: 'Segoe UI', sans-serif;
      position: relative;
    }

    /* Flüssiger animierter Hintergrund */
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
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: 420px;
      padding: 3rem;
      background: rgba(255, 255, 255, 0.95);
      border-radius: 20px;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
      z-index: 2;
      color: #001133;
      backdrop-filter: blur(10px);
      border: 2px solid rgba(255, 153, 0, 0.3);
      /* Explizit alle Animationen entfernen */
      animation: none !important;
      transition: none !important;
    }

    .login-wrapper h2 {
      margin: 0 0 2rem;
      text-align: center;
      color: #002b45;
      font-size: 2rem;
      font-weight: 700;
    }

    .input-group {
      position: relative;
      margin-bottom: 1.8rem;
      width: 100%;
    }

    .input-group input {
      width: 100%;
      padding: 1rem 3rem;
      background: rgba(255, 255, 255, 0.9);
      border: 2px solid rgba(0, 43, 69, 0.2);
      border-radius: 999px;
      color: #001133;
      font-size: 1rem;
      outline: none;
      transition: all 0.3s ease;
      font-weight: 500;
    }

    .input-group input:focus {
      background: rgba(255, 255, 255, 1);
      border-color: #ff9900;
      box-shadow: 0 0 0 4px rgba(255, 153, 0, 0.1);
      transform: translateY(-1px);
    }

    .input-group input::placeholder {
      color: #666;
    }

    .input-group i,
    .input-group .show-password {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      font-style: normal;
      font-size: 1.2rem;
      color: #002b45;
      pointer-events: none;
    }

    .input-group i {
      left: 1.2rem;
    }

    .input-group .show-password {
      right: 1.2rem;
      cursor: pointer;
      pointer-events: auto;
      transition: all 0.3s ease;
    }

    .input-group .show-password:hover {
      color: #ff9900;
      transform: translateY(-50%) scale(1.1);
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

    .app-title {
      text-align: center;
      margin-bottom: 2.5rem;
      color: #002b45;
      font-size: 1.2rem;
      font-weight: 600;
      opacity: 0.8;
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

      .header-wave {
        height: 120px;
      }

      .login-wrapper h2 {
        font-size: 1.6rem;
      }
    }

    /* Subtle floating animation */
    @keyframes float {
      0%, 100% { transform: translate(-50%, -50%) translateY(0px); }
      50% { transform: translate(-50%, -50%) translateY(-10px); }
    }

    .login-wrapper {
      animation: float 6s ease-in-out infinite;
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

    // Blob-Farben zufällig setzen
    const blobs = document.querySelectorAll('.liquid-bg::before, .liquid-bg::after, .liquid-bg .blob1, .liquid-bg .blob2, .liquid-bg .blob3');
    
    // CSS-Variablen für Blob-Farben setzen
    const root = document.documentElement;
    
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

  // CSS für Bewegungsanimationen (ohne Farbwechsel)
  const style = document.createElement('style');
  style.textContent = `
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
  document.head.appendChild(style);

  // Beim Laden der Seite initialisieren
  randomizeColors();
  createFloatingElements();
</script>

</body>
</html>