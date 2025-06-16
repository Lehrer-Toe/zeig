<?php
// Sicherheitsprüfung
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    exit('Keine Berechtigung');
}
?>

<div class="module-container">
    <h2>Einstellungen</h2>
    
    <div class="settings-form">
        <h3>Persönliche Daten</h3>
        <form method="post" action="update_teacher_settings.php" class="form-section">
            <div class="form-group">
                <label>Vorname</label>
                <input type="text" name="first_name" value="<?= htmlspecialchars($teacher['first_name']) ?>" required>
            </div>
            
            <div class="form-group">
                <label>Nachname</label>
                <input type="text" name="last_name" value="<?= htmlspecialchars($teacher['last_name']) ?>" required>
            </div>
            
            <div class="form-group">
                <label>E-Mail</label>
                <input type="email" name="email" value="<?= htmlspecialchars($teacher['email']) ?>" required>
            </div>
            
            <button type="submit" class="btn btn-primary">Änderungen speichern</button>
        </form>
        
        <h3>Passwort ändern</h3>
        <form method="post" action="update_teacher_password.php" class="form-section">
            <div class="form-group">
                <label>Aktuelles Passwort</label>
                <input type="password" name="current_password" required>
            </div>
            
            <div class="form-group">
                <label>Neues Passwort</label>
                <input type="password" name="new_password" required>
            </div>
            
            <div class="form-group">
                <label>Neues Passwort bestätigen</label>
                <input type="password" name="confirm_password" required>
            </div>
            
            <button type="submit" class="btn btn-primary">Passwort ändern</button>
        </form>
        
        <h3>Benachrichtigungen</h3>
        <form method="post" action="update_teacher_notifications.php" class="form-section">
            <div class="checkbox-group">
                <label>
                    <input type="checkbox" name="email_notifications" checked>
                    E-Mail-Benachrichtigungen aktivieren
                </label>
            </div>
            
            <div class="checkbox-group">
                <label>
                    <input type="checkbox" name="new_student_notification" checked>
                    Bei neuen Schülern benachrichtigen
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
        </form>
    </div>
</div>

<style>
.module-container {
    max-width: 800px;
}

.module-container h2 {
    color: #fff;
    margin-bottom: 30px;
    font-size: 24px;
}

.settings-form h3 {
    color: #fff;
    margin-top: 30px;
    margin-bottom: 20px;
    font-size: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid #444;
}

.settings-form h3:first-child {
    margin-top: 0;
}

.form-section {
    margin-bottom: 40px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #bbb;
    font-size: 14px;
}

.form-group input {
    width: 100%;
    padding: 12px 15px;
    background: #333;
    border: 1px solid #444;
    border-radius: 5px;
    color: #fff;
    font-size: 16px;
    transition: all 0.3s ease;
}

.form-group input:focus {
    outline: none;
    border-color: #5b67ca;
    background: #3a3a3a;
}

.checkbox-group {
    margin-bottom: 15px;
}

.checkbox-group label {
    display: flex;
    align-items: center;
    color: #bbb;
    cursor: pointer;
}

.checkbox-group input[type="checkbox"] {
    width: auto;
    margin-right: 10px;
    cursor: pointer;
}

.btn {
    padding: 12px 30px;
    border: none;
    border-radius: 5px;
    font-size: 16px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-primary {
    background: #5b67ca;
    color: white;
}

.btn-primary:hover {
    background: #4a56b9;
    transform: translateY(-1px);
}

@media (max-width: 768px) {
    .form-group input {
        font-size: 16px; /* Verhindert Zoom auf iOS */
    }
}
</style>