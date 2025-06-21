<?php
require_once '../config.php';

// Superadmin-Zugriff prüfen
$user = requireSuperadmin();

// Sicherstellen, dass 'name' existiert
if (!isset($user['name']) || $user['name'] === null) {
    $user['name'] = $user['email'] ?? 'Super Administrator';
}

$errors = [];
$success = '';
$step = $_GET['step'] ?? 1;

// Statistiken sammeln
function getSchoolYearStats() {
    $db = getDB();
    $stats = [];
    
    // Schulen zählen
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM schools WHERE is_active = 1");
    $stmt->execute();
    $stats['schools'] = $stmt->fetch()['count'];
    
    // Klassen zählen
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM classes WHERE is_active = 1");
    $stmt->execute();
    $stats['classes'] = $stmt->fetch()['count'];
    
    // Schüler zählen
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM students s 
        JOIN classes c ON s.class_id = c.id 
        WHERE s.is_active = 1 AND c.is_active = 1
    ");
    $stmt->execute();
    $stats['students'] = $stmt->fetch()['count'];
    
    // Lehrer zählen
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE user_type = 'lehrer' AND is_active = 1");
    $stmt->execute();
    $stats['teachers'] = $stmt->fetch()['count'];
    
    return $stats;
}

// Schulen mit Details laden
function getSchoolsWithDetails() {
    $db = getDB();
    
    $sql = "SELECT s.*, 
                   COUNT(DISTINCT c.id) as class_count,
                   COUNT(DISTINCT st.id) as student_count
            FROM schools s 
            LEFT JOIN classes c ON s.id = c.school_id AND c.is_active = 1
            LEFT JOIN students st ON c.id = st.class_id AND st.is_active = 1
            WHERE s.is_active = 1
            GROUP BY s.id
            ORDER BY s.name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll();
}

// Neues Schuljahr verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Sicherheitsfehler. Bitte versuchen Sie es erneut.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'confirm':
                // Schritt 2: Bestätigung anzeigen
                $schoolYear = trim($_POST['school_year'] ?? '');
                $keepTeachers = isset($_POST['keep_teachers']);
                $excludedSchools = $_POST['excluded_schools'] ?? [];
                
                if (empty($schoolYear) || !preg_match('/^\d{4}\/\d{4}$/', $schoolYear)) {
                    $errors[] = 'Bitte geben Sie ein gültiges Schuljahr im Format JJJJ/JJJJ ein (z.B. 2024/2025).';
                } else {
                    $step = 2;
                    $_SESSION['new_school_year'] = [
                        'year' => $schoolYear,
                        'keep_teachers' => $keepTeachers,
                        'excluded_schools' => $excludedSchools
                    ];
                }
                break;
                
            case 'execute':
                // Schritt 3: Durchführung
                if (!isset($_SESSION['new_school_year'])) {
                    redirectWithMessage('schule_schuljahr.php', 'Sitzung abgelaufen. Bitte erneut versuchen.', 'error');
                }
                
                $confirmPhrase = $_POST['confirm_phrase'] ?? '';
                if ($confirmPhrase !== 'NEUES SCHULJAHR STARTEN') {
                    $errors[] = 'Die Bestätigungsphrase ist nicht korrekt.';
                    $step = 2;
                } else {
                    $db = getDB();
                    $yearData = $_SESSION['new_school_year'];
                    
                    try {
                        $db->beginTransaction();
                        
                        // Zähler für Log
                        $deletedClasses = 0;
                        $deletedStudents = 0;
                        $affectedSchools = 0;
                        
                        // Für jede Schule
                        $schoolStmt = $db->prepare("SELECT id, name FROM schools WHERE is_active = 1");
                        $schoolStmt->execute();
                        $schools = $schoolStmt->fetchAll();
                        
                        foreach ($schools as $school) {
                            // Überprüfen ob Schule ausgeschlossen ist
                            if (in_array($school['id'], $yearData['excluded_schools'])) {
                                continue;
                            }
                            
                            $affectedSchools++;
                            
                            // Schüler zählen und löschen
                            $countStmt = $db->prepare("
                                SELECT COUNT(*) as count 
                                FROM students s 
                                JOIN classes c ON s.class_id = c.id 
                                WHERE c.school_id = ?
                            ");
                            $countStmt->execute([$school['id']]);
                            $studentCount = $countStmt->fetch()['count'];
                            $deletedStudents += $studentCount;
                            
                            // Schüler löschen
                            $deleteStudentsStmt = $db->prepare("
                                DELETE s FROM students s 
                                JOIN classes c ON s.class_id = c.id 
                                WHERE c.school_id = ?
                            ");
                            $deleteStudentsStmt->execute([$school['id']]);
                            
                            // Klassen zählen
                            $countClassesStmt = $db->prepare("SELECT COUNT(*) as count FROM classes WHERE school_id = ?");
                            $countClassesStmt->execute([$school['id']]);
                            $classCount = $countClassesStmt->fetch()['count'];
                            $deletedClasses += $classCount;
                            
                            // Klassen löschen
                            $deleteClassesStmt = $db->prepare("DELETE FROM classes WHERE school_id = ?");
                            $deleteClassesStmt->execute([$school['id']]);
                            
                            // Optional: Schuljahr in schools Tabelle aktualisieren (falls Spalte existiert)
                            try {
                                $updateSchoolStmt = $db->prepare("UPDATE schools SET current_school_year = ? WHERE id = ?");
                                $updateSchoolStmt->execute([$yearData['year'], $school['id']]);
                            } catch (Exception $e) {
                                // Spalte existiert möglicherweise nicht - das ist ok
                            }
                        }
                        
                        // Log-Eintrag erstellen (falls log Tabelle existiert)
                        try {
                            $logStmt = $db->prepare("
                                INSERT INTO admin_logs (user_id, action, details, created_at) 
                                VALUES (?, 'new_school_year', ?, NOW())
                            ");
                            $logDetails = json_encode([
                                'school_year' => $yearData['year'],
                                'affected_schools' => $affectedSchools,
                                'deleted_classes' => $deletedClasses,
                                'deleted_students' => $deletedStudents,
                                'kept_teachers' => $yearData['keep_teachers'],
                                'excluded_schools' => count($yearData['excluded_schools'])
                            ]);
                            $logStmt->execute([$user['id'], $logDetails]);
                        } catch (Exception $e) {
                            // Log-Tabelle existiert möglicherweise nicht - das ist ok
                        }
                        
                        $db->commit();
                        
                        // Session bereinigen
                        unset($_SESSION['new_school_year']);
                        
                        // Erfolgsmeldung
                        $successMessage = sprintf(
                            "Neues Schuljahr %s erfolgreich gestartet! %d Schulen betroffen, %d Klassen und %d Schüler gelöscht.",
                            $yearData['year'],
                            $affectedSchools,
                            $deletedClasses,
                            $deletedStudents
                        );
                        
                        redirectWithMessage('dashboard.php', $successMessage, 'success');
                        
                    } catch (Exception $e) {
                        $db->rollBack();
                        error_log("Error starting new school year: " . $e->getMessage());
                        $errors[] = 'Fehler beim Starten des neuen Schuljahres: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'cancel':
                unset($_SESSION['new_school_year']);
                redirectWithMessage('dashboard.php', 'Vorgang abgebrochen.', 'warning');
                break;
        }
    }
}

// Aktuelle Statistiken laden
$stats = getSchoolYearStats();
$schools = getSchoolsWithDetails();

// Flash-Message
$flashMessage = getFlashMessage();

// Aktuelles Schuljahr berechnen (Vorschlag)
$currentMonth = (int)date('n');
$currentYear = (int)date('Y');
if ($currentMonth >= 8) {
    $suggestedYear = $currentYear . '/' . ($currentYear + 1);
} else {
    $suggestedYear = ($currentYear - 1) . '/' . $currentYear;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Neues Schuljahr - <?php echo APP_NAME; ?></title>
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
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
        }

        .warning-banner {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid rgba(239, 68, 68, 0.3);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            text-align: center;
        }

        .warning-banner h2 {
            color: #fca5a5;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .warning-banner p {
            color: #fecaca;
            line-height: 1.6;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.2);
            border-radius: 0.5rem;
            padding: 1rem;
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #ef4444;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .form-card {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            padding: 2rem;
            backdrop-filter: blur(10px);
        }

        .form-section {
            margin-bottom: 2rem;
        }

        .form-section h3 {
            color: #3b82f6;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-group input[type="text"],
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            color: white;
            font-size: 1rem;
        }

        .form-group input[type="text"]:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .schools-list {
            max-height: 300px;
            overflow-y: auto;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            padding: 1rem;
        }

        .school-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem;
            border-bottom: 1px solid rgba(100, 116, 139, 0.2);
        }

        .school-item:last-child {
            border-bottom: none;
        }

        .school-info {
            flex: 1;
        }

        .school-stats {
            font-size: 0.8rem;
            opacity: 0.7;
        }

        .confirmation-box {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .confirmation-box input {
            background: rgba(0, 0, 0, 0.3);
            border: 2px solid rgba(239, 68, 68, 0.5);
            color: #fca5a5;
            font-weight: bold;
            text-align: center;
        }

        .help-text {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        .error-list {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 2rem;
        }

        .error-list ul {
            color: #fca5a5;
            margin-left: 1.5rem;
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

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .btn-secondary {
            background: rgba(100, 116, 139, 0.2);
            color: #cbd5e1;
            border: 1px solid rgba(100, 116, 139, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(100, 116, 139, 0.3);
        }

        .progress-steps {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
        }

        .step {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.3);
            color: #64748b;
        }

        .step.active {
            background: rgba(59, 130, 246, 0.1);
            border-color: #3b82f6;
            color: #3b82f6;
        }

        .step:not(:last-child)::after {
            content: '→';
            margin-left: 1rem;
            color: #64748b;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .summary-table th,
        .summary-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid rgba(100, 116, 139, 0.2);
        }

        .summary-table th {
            color: #3b82f6;
            font-weight: 600;
        }

        .excluded {
            opacity: 0.5;
            text-decoration: line-through;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .progress-steps {
                flex-direction: column;
                align-items: stretch;
            }
            
            .step:not(:last-child)::after {
                content: none;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>🎓 Neues Schuljahr einläuten</h1>
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a> / Neues Schuljahr
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
                <h4>⚠️ Fehler:</h4>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo escape($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Progress Steps -->
        <div class="progress-steps">
            <div class="step <?php echo $step == 1 ? 'active' : ''; ?>">
                1️⃣ Einstellungen
            </div>
            <div class="step <?php echo $step == 2 ? 'active' : ''; ?>">
                2️⃣ Bestätigung
            </div>
            <div class="step <?php echo $step == 3 ? 'active' : ''; ?>">
                3️⃣ Durchführung
            </div>
        </div>

        <?php if ($step == 1): ?>
            <!-- Schritt 1: Einstellungen -->
            <div class="warning-banner">
                <h2>⚠️ Wichtiger Hinweis</h2>
                <p>
                    Diese Aktion löscht <strong>unwiderruflich</strong> alle Klassen und Schüler aus dem System!<br>
                    Bitte stellen Sie sicher, dass Sie vorher ein Backup erstellt haben.
                </p>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['classes']; ?></div>
                    <div class="stat-label">Klassen werden gelöscht</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['students']; ?></div>
                    <div class="stat-label">Schüler werden gelöscht</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['schools']; ?></div>
                    <div class="stat-label">Schulen betroffen</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['teachers']; ?></div>
                    <div class="stat-label">Lehrer im System</div>
                </div>
            </div>

            <form method="POST" action="" class="form-card">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="confirm">

                <div class="form-section">
                    <h3>📅 Schuljahr-Einstellungen</h3>
                    
                    <div class="form-group">
                        <label for="school_year">Neues Schuljahr</label>
                        <input type="text" id="school_year" name="school_year" 
                               placeholder="z.B. 2024/2025" 
                               value="<?php echo escape($_POST['school_year'] ?? $suggestedYear); ?>"
                               pattern="\d{4}/\d{4}" required>
                        <div class="help-text">Format: JJJJ/JJJJ (z.B. 2024/2025)</div>
                    </div>

                    <div class="checkbox-group">
                        <input type="checkbox" id="keep_teachers" name="keep_teachers" checked>
                        <label for="keep_teachers">Lehrer-Accounts behalten</label>
                    </div>
                    <div class="help-text">Empfohlen: Lehrer bleiben meist über mehrere Schuljahre</div>
                </div>

                <div class="form-section">
                    <h3>🏫 Schulen ausschließen (optional)</h3>
                    <p style="margin-bottom: 1rem; font-size: 0.9rem; opacity: 0.8;">
                        Wählen Sie Schulen aus, die vom neuen Schuljahr ausgenommen werden sollen:
                    </p>
                    
                    <div class="schools-list">
                        <?php foreach ($schools as $school): ?>
                            <div class="school-item">
                                <div class="school-info">
                                    <div><?php echo escape($school['name']); ?></div>
                                    <div class="school-stats">
                                        <?php echo $school['class_count']; ?> Klassen, 
                                        <?php echo $school['student_count']; ?> Schüler
                                    </div>
                                </div>
                                <div>
                                    <input type="checkbox" 
                                           id="exclude_<?php echo $school['id']; ?>" 
                                           name="excluded_schools[]" 
                                           value="<?php echo $school['id']; ?>">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="dashboard.php" class="btn btn-secondary">❌ Abbrechen</a>
                    <button type="submit" class="btn btn-primary">Weiter zur Bestätigung →</button>
                </div>
            </form>

        <?php elseif ($step == 2): ?>
            <!-- Schritt 2: Bestätigung -->
            <?php
            $yearData = $_SESSION['new_school_year'] ?? [];
            $excludedCount = count($yearData['excluded_schools'] ?? []);
            $affectedSchools = $stats['schools'] - $excludedCount;
            ?>
            
            <div class="form-card">
                <div class="form-section">
                    <h3>📋 Zusammenfassung</h3>
                    
                    <table class="summary-table">
                        <tr>
                            <th>Neues Schuljahr:</th>
                            <td><strong><?php echo escape($yearData['year']); ?></strong></td>
                        </tr>
                        <tr>
                            <th>Betroffene Schulen:</th>
                            <td><?php echo $affectedSchools; ?> von <?php echo $stats['schools']; ?></td>
                        </tr>
                        <tr>
                            <th>Zu löschende Klassen:</th>
                            <td style="color: #ef4444;"><?php echo $stats['classes']; ?></td>
                        </tr>
                        <tr>
                            <th>Zu löschende Schüler:</th>
                            <td style="color: #ef4444;"><?php echo $stats['students']; ?></td>
                        </tr>
                        <tr>
                            <th>Lehrer behalten:</th>
                            <td><?php echo $yearData['keep_teachers'] ? 'Ja ✅' : 'Nein ❌'; ?></td>
                        </tr>
                    </table>
                </div>

                <?php if ($excludedCount > 0): ?>
                    <div class="form-section">
                        <h3>🚫 Ausgeschlossene Schulen</h3>
                        <ul>
                            <?php 
                            foreach ($schools as $school) {
                                if (in_array($school['id'], $yearData['excluded_schools'])) {
                                    echo '<li>' . escape($school['name']) . '</li>';
                                }
                            }
                            ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="execute">
                    
                    <div class="confirmation-box">
                        <h4 style="color: #fca5a5; margin-bottom: 1rem;">
                            ⚠️ Letzte Sicherheitsabfrage
                        </h4>
                        <p style="margin-bottom: 1rem;">
                            Diese Aktion kann nicht rückgängig gemacht werden!<br>
                            Geben Sie zur Bestätigung folgende Phrase ein:
                        </p>
                        <p style="font-size: 1.2rem; font-weight: bold; color: #ef4444; margin-bottom: 1rem;">
                            NEUES SCHULJAHR STARTEN
                        </p>
                        <input type="text" name="confirm_phrase" 
                               placeholder="Bestätigungsphrase eingeben" 
                               autocomplete="off" required>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="action" value="cancel" class="btn btn-secondary">
                            ← Zurück
                        </button>
                        <button type="submit" class="btn btn-danger">
                            🚀 Neues Schuljahr starten
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Warnung beim Verlassen der Seite
        window.addEventListener('beforeunload', function (e) {
            if (document.querySelector('form')) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // Schuljahr-Format validieren
        document.getElementById('school_year')?.addEventListener('input', function(e) {
            const value = e.target.value;
            const regex = /^\d{4}\/\d{4}$/;
            
            if (value.length === 9) {
                if (!regex.test(value)) {
                    e.target.setCustomValidity('Bitte Format JJJJ/JJJJ verwenden');
                } else {
                    const years = value.split('/');
                    const year1 = parseInt(years[0]);
                    const year2 = parseInt(years[1]);
                    
                    if (year2 !== year1 + 1) {
                        e.target.setCustomValidity('Das zweite Jahr muss genau ein Jahr nach dem ersten sein');
                    } else {
                        e.target.setCustomValidity('');
                    }
                }
            }
        });
    </script>
</body>
</html>