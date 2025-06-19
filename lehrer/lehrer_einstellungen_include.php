<?php
// Datenbankverbindung und Session-Daten nutzen
// $db, $teacher_id und $teacher sollten aus dashboard.php verfügbar sein
$school_id = $_SESSION['school_id'] ?? null;

// Falls $teacher nicht aus dashboard.php verfügbar ist, laden wir es
if (!isset($teacher)) {
    $stmt = $db->prepare("
        SELECT u.*, s.name as school_name
        FROM users u
        JOIN schools s ON u.school_id = s.id
        WHERE u.id = ?
    ");
    $stmt->execute([$teacher_id]);
    $teacher = $stmt->fetch();
}

// Prüfen ob Passwort bereits geändert wurde
$stmt = $db->prepare("SELECT first_login, password_set_by_admin, admin_password FROM users WHERE id = ?");
$stmt->execute([$teacher_id]);
$user_data = $stmt->fetch();
$password_changed = !($user_data['first_login'] ?? 1) || !($user_data['password_set_by_admin'] ?? 1);

// HINWEIS: Die Passwortänderung sollte in dashboard.php VOR der HTML-Ausgabe verarbeitet werden!
// Der folgende Code ist nur als Fallback gedacht, falls dies nicht geschieht.

// Erfolgs- oder Fehlermeldung
$message = '';
$message_type = '';

// Flash-Message abrufen falls vorhanden
$flash_message = null;
$flash_type = null;
if (isset($_SESSION['flash_message'])) {
    $flash_message = $_SESSION['flash_message'];
    $flash_type = $_SESSION['flash_type'];
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

// WICHTIG: Die Passwortänderung MUSS in dashboard.php VOR der HTML-Ausgabe erfolgen!
// Dieser Code hier ist deaktiviert, um "headers already sent" Fehler zu vermeiden.
// Siehe die Anleitung für dashboard.php weiter unten.
?>

<style>
/* Sunset Theme für Einstellungen */
.settings-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.settings-header {
    background: linear-gradient(135deg, #FF6B6B 0%, #FFE66D 100%);
    color: #2D3436;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 30px;
    box-shadow: 0 5px 15px rgba(255, 107, 107, 0.3);
}

.settings-header h2 {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
}

.settings-header p {
    margin: 10px 0 0;
    opacity: 0.8;
}

.settings-section {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 20px;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
    border: 1px solid rgba(255, 107, 107, 0.2);
}

.section-title {
    font-size: 1.3rem;
    font-weight: 600;
    color: #2D3436;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #FFE66D;
}

.password-status {
    background: linear-gradient(135deg, #FFEAA7 0%, #FFE66D 100%);
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.password-status.changed {
    background: linear-gradient(135deg, #A8E6CF 0%, #7FE3C1 100%);
}

.password-status-icon {
    font-size: 1.5rem;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-weight: 600;
    color: #2D3436;
    margin-bottom: 8px;
}

.form-input {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #FFE66D;
    border-radius: 8px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: #FFFEF5;
}

.form-input:focus {
    outline: none;
    border-color: #FF6B6B;
    box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.2);
}

.password-requirements {
    background: #FFF5E1;
    border: 1px solid #FFE66D;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}

.password-requirements h4 {
    margin: 0 0 10px;
    color: #2D3436;
    font-size: 0.9rem;
}

.requirement-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.requirement-list li {
    padding: 5px 0;
    color: #636E72;
    font-size: 0.9rem;
}

.requirement-list li:before {
    content: "✓ ";
    color: #FF6B6B;
    font-weight: bold;
    margin-right: 5px;
}

.btn-sunset {
    background: linear-gradient(135deg, #FF6B6B 0%, #FFE66D 100%);
    color: #2D3436;
    padding: 12px 30px;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 3px 10px rgba(255, 107, 107, 0.3);
}

.btn-sunset:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(255, 107, 107, 0.4);
}

.btn-sunset:active {
    transform: translateY(0);
}

.message {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: 500;
}

.message.success {
    background: #A8E6CF;
    color: #27AE60;
    border: 1px solid #7FE3C1;
}

.message.error {
    background: #FFCCCC;
    color: #E74C3C;
    border: 1px solid #FF9999;
}

.info-box {
    background: linear-gradient(135deg, #FFF5E1 0%, #FFFEF5 100%);
    border: 1px solid #FFE66D;
    border-radius: 8px;
    padding: 15px;
    margin-top: 20px;
}

.info-box p {
    margin: 0;
    color: #636E72;
    font-size: 0.9rem;
}

.password-display {
    font-size: 1.5rem;
    letter-spacing: 5px;
    color: #FF6B6B;
}

/* Responsive Design */
@media (max-width: 768px) {
    .settings-container {
        padding: 10px;
    }
    
    .settings-header {
        padding: 20px;
    }
    
    .settings-section {
        padding: 20px;
    }
    
    .btn-sunset {
        width: 100%;
    }
}
</style>

<div class="settings-container">
    <div class="settings-header">
        <h2>⚙️ Einstellungen</h2>
        <p>Verwalten Sie hier Ihre persönlichen Einstellungen und Sicherheitsoptionen</p>
    </div>

    <?php if (!empty($message)): ?>
        <div class="message <?= $message_type ?>">
            <?= $message ?>
        </div>
    <?php endif; ?>
    
    <?php if ($flash_message): ?>
        <div class="message <?= $flash_type ?>">
            <?= $flash_message ?>
        </div>
    <?php endif; ?>

    <div class="settings-section">
        <h3 class="section-title">🔐 Passwort-Verwaltung</h3>
        
        <div class="password-status <?= $password_changed ? 'changed' : '' ?>">
            <span class="password-status-icon"><?= $password_changed ? '✅' : '⚠️' ?></span>
            <div>
                <?php if ($password_changed): ?>
                    <strong>Passwort wurde geändert</strong><br>
                    <span class="password-display">***</span>
                <?php else: ?>
                    <strong>Standard-Passwort aktiv</strong><br>
                    <?php if (!empty($user_data['admin_password'])): ?>
                        <span style="font-size: 0.9rem; opacity: 0.8;">Admin-Passwort: <?= htmlspecialchars($user_data['admin_password']) ?></span>
                    <?php else: ?>
                        Bitte ändern Sie Ihr Passwort für mehr Sicherheit
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$password_changed): ?>
            <form method="POST" action="?page=einstellungen" id="passwordForm">
                <input type="hidden" name="action" value="change_password">
                
                <div class="password-requirements">
                    <h4>Passwort-Anforderungen:</h4>
                    <ul class="requirement-list">
                        <li>Mindestens 8 Zeichen lang</li>
                        <li>Mindestens 1 Großbuchstabe (A-Z)</li>
                        <li>Mindestens 1 Sonderzeichen (!@#$%^&*...)</li>
                    </ul>
                </div>

                <div class="form-group">
                    <label class="form-label" for="new_password">Neues Passwort</label>
                    <input type="password" 
                           class="form-input" 
                           id="new_password" 
                           name="new_password" 
                           required
                           minlength="8"
                           placeholder="Ihr neues Passwort">
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm_password">Passwort bestätigen</label>
                    <input type="password" 
                           class="form-input" 
                           id="confirm_password" 
                           name="confirm_password" 
                           required
                           minlength="8"
                           placeholder="Passwort wiederholen">
                </div>

                <button type="submit" class="btn-sunset">
                    🔒 Passwort ändern
                </button>
            </form>
        <?php else: ?>
            <div class="info-box">
                <p>
                    <strong>Hinweis:</strong> Ihr Passwort wurde erfolgreich geändert. 
                    Falls Sie es erneut ändern möchten, wenden Sie sich bitte an Ihren Schuladministrator.
                </p>
            </div>
        <?php endif; ?>
    </div>

    <div class="settings-section">
        <h3 class="section-title">👤 Profil-Informationen</h3>
        <div class="info-box">
            <p><strong>Name:</strong> <?= htmlspecialchars($teacher['name'] ?? 'Nicht angegeben') ?></p>
            <p><strong>E-Mail:</strong> <?= htmlspecialchars($teacher['email'] ?? 'Nicht angegeben') ?></p>
            <p><strong>Schule:</strong> <?= htmlspecialchars($teacher['school_name'] ?? 'Nicht angegeben') ?></p>
            <p><strong>Benutzertyp:</strong> Lehrer</p>
        </div>
    </div>
</div>

<script>
// Client-seitige Validierung
document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    // Validierungen
    const errors = [];
    
    if (newPassword.length < 8) {
        errors.push('Das Passwort muss mindestens 8 Zeichen lang sein.');
    }
    
    if (!/[A-Z]/.test(newPassword)) {
        errors.push('Das Passwort muss mindestens einen Großbuchstaben enthalten.');
    }
    
    if (!/[^a-zA-Z0-9]/.test(newPassword)) {
        errors.push('Das Passwort muss mindestens ein Sonderzeichen enthalten.');
    }
    
    if (newPassword !== confirmPassword) {
        errors.push('Die Passwörter stimmen nicht überein.');
    }
    
    if (errors.length > 0) {
        e.preventDefault();
        alert('Bitte beachten Sie:\n\n' + errors.join('\n'));
    }
});

// Live-Validierung während der Eingabe
const passwordInput = document.getElementById('new_password');
const confirmInput = document.getElementById('confirm_password');

if (passwordInput) {
    passwordInput.addEventListener('input', function() {
        const value = this.value;
        let isValid = true;
        
        // Prüfungen
        if (value.length < 8) isValid = false;
        if (!/[A-Z]/.test(value)) isValid = false;
        if (!/[^a-zA-Z0-9]/.test(value)) isValid = false;
        
        // Visuelles Feedback
        this.style.borderColor = isValid ? '#7FE3C1' : '#FFE66D';
    });
}

if (confirmInput) {
    confirmInput.addEventListener('input', function() {
        const match = this.value === passwordInput.value;
        this.style.borderColor = match ? '#7FE3C1' : '#FFE66D';
    });
}
</script>