<?php
require_once '../config.php';

// Superadmin-Zugriff prüfen
$user = requireSuperadmin();

$isEdit = isset($_GET['id']);
$school = null;
$errors = [];
$success = '';

// Bearbeitungsmodus
if ($isEdit) {
    $schoolId = (int)$_GET['id'];
    $school = getSchoolById($schoolId);
    
    if (!$school) {
        redirectWithMessage('dashboard.php', 'Schule nicht gefunden.', 'error');
    }
}

// Formular verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Sicherheitsfehler. Bitte versuchen Sie es erneut.';
    } else {
        // Validierung
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'location' => trim($_POST['location'] ?? ''),
            'contact_phone' => trim($_POST['contact_phone'] ?? ''),
            'contact_email' => trim($_POST['contact_email'] ?? ''),
            'contact_person' => trim($_POST['contact_person'] ?? ''),
            'school_type' => $_POST['school_type'] ?? 'Realschule',
            'school_type_custom' => $_POST['school_type'] === 'Sonstige' ? trim($_POST['school_type_custom'] ?? '') : null,
            'license_until' => formatDateForDB($_POST['license_until'] ?? ''),
            'max_classes' => max(1, (int)($_POST['max_classes'] ?? 3)),
            'max_students_per_class' => max(1, (int)($_POST['max_students_per_class'] ?? 32)),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'admin_email' => trim($_POST['admin_email'] ?? ''),
            'admin_name' => trim($_POST['admin_name'] ?? ''),
            'admin_password' => $_POST['admin_password'] ?? ''
        ];
        
        // Pflichtfelder prüfen
        if (empty($data['name'])) {
            $errors[] = 'Schulname ist erforderlich.';
        }
        
        if (empty($data['location'])) {
            $errors[] = 'Ort ist erforderlich.';
        }
        
        if (empty($data['contact_email']) || !validateEmail($data['contact_email'])) {
            $errors[] = 'Gültige Kontakt-E-Mail ist erforderlich.';
        }
        
        if (empty($data['contact_person'])) {
            $errors[] = 'Kontaktperson ist erforderlich.';
        }
        
        if (!$data['license_until']) {
            $errors[] = 'Gültiges Lizenzdatum ist erforderlich.';
        }
        
        if ($data['max_classes'] < 1 || $data['max_classes'] > 100) {
            $errors[] = 'Anzahl Klassen muss zwischen 1 und 100 liegen.';
        }
        
        if ($data['max_students_per_class'] < 1 || $data['max_students_per_class'] > 50) {
            $errors[] = 'Maximale Schüler pro Klasse muss zwischen 1 und 50 liegen.';
        }
        
        if (empty($data['admin_email']) || !validateEmail($data['admin_email'])) {
            $errors[] = 'Gültige Admin-E-Mail ist erforderlich.';
        }
        
        if (empty($data['admin_name'])) {
            $errors[] = 'Admin-Name ist erforderlich.';
        }
        
        // Passwort nur bei neuer Schule oder wenn explizit geändert
        if (!$isEdit || !empty($data['admin_password'])) {
            if (empty($data['admin_password'])) {
                $errors[] = 'Admin-Passwort ist erforderlich.';
            } elseif (strlen($data['admin_password']) < PASSWORD_MIN_LENGTH) {
                $errors[] = 'Admin-Passwort muss mindestens ' . PASSWORD_MIN_LENGTH . ' Zeichen lang sein.';
            }
        }
        
        // E-Mail-Duplikate prüfen
        if (!$isEdit || $data['admin_email'] !== $school['admin_email']) {
            $existingUser = getUserByEmail($data['admin_email']);
            if ($existingUser) {
                $errors[] = 'Diese E-Mail-Adresse wird bereits verwendet.';
            }
        }
        
        // Schule erstellen/aktualisieren
        if (empty($errors)) {
            if ($isEdit) {
                if (updateSchool($schoolId, $data)) {
                    redirectWithMessage('dashboard.php', 'Schule erfolgreich aktualisiert.', 'success');
                } else {
                    $errors[] = 'Fehler beim Aktualisieren der Schule.';
                }
            } else {
                $newSchoolId = createSchool($data);
                if ($newSchoolId) {
                    redirectWithMessage('dashboard.php', 'Schule erfolgreich erstellt.', 'success');
                } else {
                    $errors[] = 'Fehler beim Erstellen der Schule.';
                }
            }
        }
    }
}

// Flash-Message
$flashMessage = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isEdit ? 'Schule bearbeiten' : 'Neue Schule'; ?> - <?php echo APP_NAME; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: #e2e8f0;
            min-height: 100vh;
        }

        .header {
            background: rgba(0, 0, 0, 0.3);
            border-bottom: 1px solid rgba(59, 130, 246, 0.2);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(10px);
        }

        .header h1 {
            color: #3b82f6;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .breadcrumb {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .breadcrumb a {
            color: #3b82f6;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem;
        }

        .form-card {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            padding: 2rem;
            backdrop-filter: blur(10px);
        }

        .form-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .form-header h2 {
            color: #3b82f6;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .form-header p {
            opacity: 0.8;
        }

        .form-section {
            margin-bottom: 2rem;
        }

        .form-section h3 {
            color: #3b82f6;
            font-size: 1.2rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-row.three-col {
            grid-template-columns: 1fr 1fr 1fr;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #cbd5e1;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.75rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            color: white;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            background: rgba(0, 0, 0, 0.4);
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: #64748b;
        }

        .form-group select option {
            background: #1e293b;
            color: white;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin: 0;
        }

        .radio-group {
            display: flex;
            gap: 2rem;
            margin-top: 0.5rem;
            flex-wrap: wrap;
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .radio-option input[type="radio"] {
            width: auto;
            margin: 0;
        }

        .custom-input {
            margin-top: 0.5rem;
            display: none;
        }

        .custom-input.show {
            display: block;
        }

        .input-group {
            position: relative;
        }

        .input-suffix {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 0.9rem;
            pointer-events: none;
        }

        .input-group input {
            padding-right: 3rem;
        }

        .password-display {
            background: rgba(100, 116, 139, 0.2);
            color: #cbd5e1;
            font-family: monospace;
            font-size: 1.2rem;
            letter-spacing: 0.1em;
            cursor: default;
        }

        .error-list {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 2rem;
        }

        .error-list h4 {
            color: #fca5a5;
            margin-bottom: 0.5rem;
        }

        .error-list ul {
            color: #fca5a5;
            margin-left: 1.5rem;
        }

        .success-message {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 2rem;
            text-align: center;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }

        .btn {
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 0.5rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            min-width: 150px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-secondary {
            background: rgba(100, 116, 139, 0.2);
            color: #cbd5e1;
            border: 1px solid rgba(100, 116, 139, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(100, 116, 139, 0.3);
        }

        .required {
            color: #f87171;
        }

        .help-text {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        .info-badge {
            background: rgba(59, 130, 246, 0.1);
            color: #93c5fd;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            margin-left: 0.5rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .form-card {
                padding: 1.5rem;
            }
            
            .form-row,
            .form-row.three-col {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .radio-group {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1><?php echo $isEdit ? '✏️ Schule bearbeiten' : '➕ Neue Schule'; ?></h1>
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a> / <?php echo $isEdit ? 'Schule bearbeiten' : 'Neue Schule'; ?>
            </div>
        </div>
    </div>

    <div class="container">
        <?php if ($flashMessage): ?>
            <div class="flash-message flash-<?php echo $flashMessage['type']; ?>">
                <?php echo escape($flashMessage['message']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="error-list">
                <h4>⚠️ Bitte korrigieren Sie folgende Fehler:</h4>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo escape($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <div class="form-header">
                <h2><?php echo $isEdit ? 'Schule bearbeiten' : 'Neue Schule anlegen'; ?></h2>
                <p>Geben Sie alle erforderlichen Informationen für die Schule ein.</p>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <!-- Schulinformationen -->
                <div class="form-section">
                    <h3>🏫 Schulinformationen</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Schulname <span class="required">*</span></label>
                            <input type="text" id="name" name="name" required
                                   value="<?php echo escape($_POST['name'] ?? $school['name'] ?? ''); ?>"
                                   placeholder="z.B. Realschule Musterstadt">
                        </div>
                        
                        <div class="form-group">
                            <label for="location">Ort <span class="required">*</span></label>
                            <input type="text" id="location" name="location" required
                                   value="<?php echo escape($_POST['location'] ?? $school['location'] ?? ''); ?>"
                                   placeholder="z.B. Musterstadt">
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label for="school_type">Schulart</label>
                        <div class="radio-group">
                            <div class="radio-option">
                                <input type="radio" id="realschule" name="school_type" value="Realschule" 
                                       <?php echo (!isset($_POST['school_type']) && (!$school || $school['school_type'] === 'Realschule')) || 
                                                 ($_POST['school_type'] ?? '') === 'Realschule' ? 'checked' : ''; ?>
                                       onchange="toggleCustomSchoolType()">
                                <label for="realschule">Realschule</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" id="gemeinschaftsschule" name="school_type" value="Gemeinschaftsschule"
                                       <?php echo ($_POST['school_type'] ?? $school['school_type'] ?? '') === 'Gemeinschaftsschule' ? 'checked' : ''; ?>
                                       onchange="toggleCustomSchoolType()">
                                <label for="gemeinschaftsschule">Gemeinschaftsschule</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" id="sonstige" name="school_type" value="Sonstige"
                                       <?php echo ($_POST['school_type'] ?? $school['school_type'] ?? '') === 'Sonstige' ? 'checked' : ''; ?>
                                       onchange="toggleCustomSchoolType()">
                                <label for="sonstige">Sonstige</label>
                            </div>
                        </div>
                        <div class="custom-input <?php echo ($_POST['school_type'] ?? $school['school_type'] ?? '') === 'Sonstige' ? 'show' : ''; ?>" id="customSchoolTypeInput">
                            <input type="text" name="school_type_custom" placeholder="Eigene Schulart eingeben"
                                   value="<?php echo escape($_POST['school_type_custom'] ?? $school['school_type_custom'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <!-- Kontaktinformationen -->
                <div class="form-section">
                    <h3>📞 Kontaktinformationen</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="contact_person">Kontaktperson <span class="required">*</span></label>
                            <input type="text" id="contact_person" name="contact_person" required
                                   value="<?php echo escape($_POST['contact_person'] ?? $school['contact_person'] ?? ''); ?>"
                                   placeholder="z.B. Max Mustermann">
                        </div>
                        
                        <div class="form-group">
                            <label for="contact_phone">Telefon</label>
                            <input type="tel" id="contact_phone" name="contact_phone"
                                   value="<?php echo escape($_POST['contact_phone'] ?? $school['contact_phone'] ?? ''); ?>"
                                   placeholder="z.B. +49 123 456789">
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label for="contact_email">Kontakt-E-Mail <span class="required">*</span></label>
                        <input type="email" id="contact_email" name="contact_email" required
                               value="<?php echo escape($_POST['contact_email'] ?? $school['contact_email'] ?? ''); ?>"
                               placeholder="z.B. kontakt@schule.de">
                    </div>
                </div>

                <!-- Lizenz und Kapazitäten -->
                <div class="form-section">
                    <h3>📝 Lizenz und Kapazitäten</h3>
                    
                    <div class="form-row three-col">
                        <div class="form-group">
                            <label for="license_until">Lizenz bis <span class="required">*</span></label>
                            <input type="date" id="license_until" name="license_until" required
                                   value="<?php echo escape($_POST['license_until'] ?? $school['license_until'] ?? ''); ?>">
                            <div class="help-text">Das Datum, bis zu dem die Schullizenz gültig ist</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="max_classes">
                                Maximale Klassen <span class="required">*</span>
                                <span class="info-badge">Standard: 3</span>
                            </label>
                            <div class="input-group">
                                <input type="number" id="max_classes" name="max_classes" required 
                                       min="1" max="100" 
                                       value="<?php echo escape($_POST['max_classes'] ?? $school['max_classes'] ?? '3'); ?>">
                                <span class="input-suffix">Klassen</span>
                            </div>
                            <div class="help-text">Anzahl der Klassen, die diese Schule anlegen darf</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="max_students_per_class">
                                Max. Schüler/Klasse <span class="required">*</span>
                                <span class="info-badge">Standard: 32</span>
                            </label>
                            <div class="input-group">
                                <input type="number" id="max_students_per_class" name="max_students_per_class" required 
                                       min="1" max="50" 
                                       value="<?php echo escape($_POST['max_students_per_class'] ?? $school['max_students_per_class'] ?? '32'); ?>">
                                <span class="input-suffix">Schüler</span>
                            </div>
                            <div class="help-text">Klassenteiler - maximale Schüleranzahl pro Klasse</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div class="checkbox-group">
                            <input type="checkbox" id="is_active" name="is_active" 
                                   <?php echo (!isset($_POST['is_active']) && (!$school || $school['is_active'])) || 
                                             isset($_POST['is_active']) ? 'checked' : ''; ?>>
                            <label for="is_active">Schule ist aktiv</label>
                        </div>
                        <div class="help-text">Deaktivierte Schulen können sich nicht anmelden</div>
                    </div>
                </div>

                <!-- Admin-Informationen -->
                <div class="form-section">
                    <h3>👤 Schuladministrator</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="admin_name">Admin-Name <span class="required">*</span></label>
                            <input type="text" id="admin_name" name="admin_name" required
                                   value="<?php echo escape($_POST['admin_name'] ?? $school['admin_name'] ?? ''); ?>"
                                   placeholder="z.B. Max Mustermann">
                        </div>
                        
                        <div class="form-group">
                            <label for="admin_email">Admin-E-Mail <span class="required">*</span></label>
                            <input type="email" id="admin_email" name="admin_email" required
                                   value="<?php echo escape($_POST['admin_email'] ?? $school['admin_email'] ?? ''); ?>"
                                   placeholder="z.B. admin@schule.de">
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label for="admin_password">
                            Admin-Passwort 
                            <?php if (!$isEdit): ?>
                                <span class="required">*</span>
                            <?php endif; ?>
                        </label>
                        
                        <?php if ($isEdit && $school): ?>
                            <!-- Im Bearbeitungsmodus: Passwort als *** anzeigen -->
                            <div style="margin-bottom: 0.5rem;">
                                <input type="text" class="password-display" value="********" readonly>
                                <div class="help-text">Aktuelles Passwort (verborgen)</div>
                            </div>
                            <input type="password" id="admin_password" name="admin_password" 
                                   placeholder="Neues Passwort eingeben (leer lassen zum Beibehalten)">
                            <div class="help-text">
                                Lassen Sie das Feld leer, um das aktuelle Passwort beizubehalten.
                                Bei einer Änderung muss der Schuladmin das Passwort beim nächsten Login erneut ändern.
                            </div>
                        <?php else: ?>
                            <!-- Beim Erstellen: Normales Passwort-Feld -->
                            <input type="password" id="admin_password" name="admin_password" required
                                   placeholder="Mindestens 8 Zeichen">
                            <div class="help-text">
                                Der Schuladmin muss das Passwort beim ersten Login ändern.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="dashboard.php" class="btn btn-secondary">❌ Abbrechen</a>
                    <button type="submit" class="btn btn-primary">
                        <?php echo $isEdit ? '💾 Speichern' : '➕ Erstellen'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleCustomSchoolType() {
            const sonstigeRadio = document.getElementById('sonstige');
            const customInput = document.getElementById('customSchoolTypeInput');
            
            if (sonstigeRadio.checked) {
                customInput.classList.add('show');
                customInput.querySelector('input').focus();
            } else {
                customInput.classList.remove('show');
                customInput.querySelector('input').value = '';
            }
        }

        // Zahlen-Inputs validieren
        document.getElementById('max_classes').addEventListener('input', function() {
            const value = parseInt(this.value);
            if (value < 1) this.value = 1;
            if (value > 100) this.value = 100;
        });

        document.getElementById('max_students_per_class').addEventListener('input', function() {
            const value = parseInt(this.value);
            if (value < 1) this.value = 1;
            if (value > 50) this.value = 50;
        });

        // Form-Validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const maxClasses = parseInt(document.getElementById('max_classes').value);
            const maxStudents = parseInt(document.getElementById('max_students_per_class').value);
            
            if (maxClasses < 1 || maxClasses > 100) {
                alert('Anzahl Klassen muss zwischen 1 und 100 liegen.');
                e.preventDefault();
                return;
            }
            
            if (maxStudents < 1 || maxStudents > 50) {
                alert('Maximale Schüler pro Klasse muss zwischen 1 und 50 liegen.');
                e.preventDefault();
                return;
            }
        });
    </script>
</body>
</html>