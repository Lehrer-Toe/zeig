<?php
require_once '../config.php';
require_once '../php/db.php';

// Schuladmin-Zugriff prüfen
$user = requireSchuladmin();
requireValidSchoolLicense($user['school_id']);

$db = getDB();
$school_id = $user['school_id'];

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Flash-Message
$flashMessage = getFlashMessage();

// Das Bereinigungsskript von vorhin hier einbinden
include 'includes/cleanup_functions.php';

$errors = [];
$success = '';

// Formularverarbeitung
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Sicherheitsfehler.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'cleanup_orphaned':
                $result = cleanupOrphanedAssignments($school_id, $db);
                if ($result['success']) {
                    $success = "Bereinigung erfolgreich: {$result['cleaned_examiner']} Prüferzuordnungen und {$result['cleaned_assigned']} Zuweisungen korrigiert.";
                } else {
                    $errors[] = 'Fehler bei der Bereinigung: ' . $result['error'];
                }
                break;
                
            case 'delete_teacher_safe':
                $teacher_id = (int)($_POST['teacher_id'] ?? 0);
                if ($teacher_id > 0) {
                    $result = deleteTeacherSafely($teacher_id, $school_id, $db);
                    if ($result['success']) {
                        $success = $result['message'];
                    } else {
                        $errors[] = $result['message'];
                    }
                }
                break;
        }
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Verschiedene Probleme identifizieren
$problems = [];

// 1. Verwaiste Lehrerzuordnungen
$stmt = $db->prepare("
    SELECT COUNT(*) as count,
           GROUP_CONCAT(DISTINCT s.first_name, ' ', s.last_name SEPARATOR ', ') as affected_students
    FROM group_students gs
    LEFT JOIN users u ON gs.examiner_teacher_id = u.id
    JOIN groups g ON gs.group_id = g.id
    JOIN students s ON gs.student_id = s.id
    WHERE gs.examiner_teacher_id IS NOT NULL 
    AND u.id IS NULL 
    AND g.school_id = ?
");
$stmt->execute([$school_id]);
$orphaned_teachers = $stmt->fetch();

if ($orphaned_teachers['count'] > 0) {
    $problems[] = [
        'type' => 'orphaned_teachers',
        'severity' => 'high',
        'title' => 'Verwaiste Lehrerzuordnungen',
        'count' => $orphaned_teachers['count'],
        'description' => "Schüler sind gelöschten Lehrern zugeordnet",
        'affected' => $orphaned_teachers['affected_students']
    ];
}

// 2. Gruppen ohne aktive Schüler
$stmt = $db->prepare("
    SELECT g.id, g.name, COUNT(gs.student_id) as student_count
    FROM groups g
    LEFT JOIN group_students gs ON g.id = gs.group_id
    WHERE g.school_id = ? AND g.is_active = 1
    GROUP BY g.id
    HAVING student_count = 0
");
$stmt->execute([$school_id]);
$empty_groups = $stmt->fetchAll();

if (!empty($empty_groups)) {
    $problems[] = [
        'type' => 'empty_groups',
        'severity' => 'medium',
        'title' => 'Leere Gruppen',
        'count' => count($empty_groups),
        'description' => "Aktive Gruppen ohne Schüler",
        'affected' => implode(', ', array_column($empty_groups, 'name'))
    ];
}

// 3. Schüler ohne Prüfer
$stmt = $db->prepare("
    SELECT COUNT(*) as count,
           GROUP_CONCAT(DISTINCT s.first_name, ' ', s.last_name SEPARATOR ', ') as affected_students
    FROM group_students gs
    JOIN groups g ON gs.group_id = g.id
    JOIN students s ON gs.student_id = s.id
    WHERE gs.examiner_teacher_id IS NULL
    AND g.school_id = ? AND g.is_active = 1
");
$stmt->execute([$school_id]);
$students_without_examiner = $stmt->fetch();

if ($students_without_examiner['count'] > 0) {
    $problems[] = [
        'type' => 'no_examiner',
        'severity' => 'medium',
        'title' => 'Schüler ohne Prüfer',
        'count' => $students_without_examiner['count'],
        'description' => "Schüler in Gruppen, aber ohne zugewiesenen Prüfer",
        'affected' => $students_without_examiner['affected_students']
    ];
}

// 4. Bewertungen ohne Template
$stmt = $db->prepare("
    SELECT COUNT(*) as count
    FROM ratings r
    JOIN users u ON r.teacher_id = u.id
    LEFT JOIN rating_templates rt ON r.template_id = rt.id
    WHERE u.school_id = ? AND rt.id IS NULL
");
$stmt->execute([$school_id]);
$ratings_without_template = $stmt->fetch();

if ($ratings_without_template['count'] > 0) {
    $problems[] = [
        'type' => 'no_template',
        'severity' => 'low',
        'title' => 'Bewertungen ohne Vorlage',
        'count' => $ratings_without_template['count'],
        'description' => "Bewertungen verweisen auf gelöschte Vorlagen",
        'affected' => ''
    ];
}

// Statistiken
$stmt = $db->prepare("
    SELECT 
        (SELECT COUNT(*) FROM users WHERE school_id = ? AND user_type = 'lehrer' AND is_active = 1) as active_teachers,
        (SELECT COUNT(*) FROM groups WHERE school_id = ? AND is_active = 1) as active_groups,
        (SELECT COUNT(*) FROM group_students gs JOIN groups g ON gs.group_id = g.id WHERE g.school_id = ?) as total_assignments,
        (SELECT COUNT(*) FROM ratings r JOIN users u ON r.teacher_id = u.id WHERE u.school_id = ?) as total_ratings
");
$stmt->execute([$school_id, $school_id, $school_id, $school_id]);
$stats = $stmt->fetch();

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Datenbereinigung - <?php echo APP_NAME; ?></title>
    <style>
        /* Gleiche Styles wie admin_klassen.php */
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
            margin-top: 0.25rem;
        }

        .breadcrumb a {
            color: #3b82f6;
            text-decoration: none;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .card {
            background: rgba(0, 0, 0, 0.4);
            border-radius: 1rem;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(100, 116, 139, 0.2);
        }

        .card-title {
            color: #3b82f6;
            font-size: 1.3rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-item {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 0.5rem;
            padding: 1rem;
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #3b82f6;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-top: 0.5rem;
        }

        .problems-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .problem-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 0.5rem;
            padding: 1rem;
            border-left: 4px solid #ef4444;
        }

        .problem-item.medium {
            border-left-color: #f59e0b;
        }

        .problem-item.low {
            border-left-color: #6b7280;
        }

        .problem-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .problem-count {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
        }

        .problem-description {
            opacity: 0.8;
            margin-bottom: 0.5rem;
        }

        .problem-affected {
            font-size: 0.8rem;
            background: rgba(0, 0, 0, 0.3);
            padding: 0.5rem;
            border-radius: 0.25rem;
            opacity: 0.7;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }

        .btn-secondary {
            background: rgba(100, 116, 139, 0.2);
            color: #cbd5e1;
            border: 1px solid rgba(100, 116, 139, 0.3);
        }

        .no-problems {
            text-align: center;
            padding: 3rem;
            opacity: 0.7;
        }

        .no-problems .icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #22c55e;
        }

        .flash-message {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 2rem;
            font-weight: 500;
        }

        .flash-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
        }

        .flash-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .tool-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 1rem;
            padding: 1.5rem;
            border: 1px solid rgba(100, 116, 139, 0.2);
        }

        .tool-title {
            color: #3b82f6;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .tool-description {
            opacity: 0.8;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>🔧 Datenbereinigung</h1>
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a> / Datenbereinigung
            </div>
        </div>
        <a href="dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
    </div>

    <div class="container">
        <?php if ($flashMessage): ?>
            <div class="flash-message flash-<?php echo $flashMessage['type']; ?>">
                <?php echo escape($flashMessage['message']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="flash-message flash-error">
                <h4>⚠️ Fehler:</h4>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo escape($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="flash-message flash-success">
                <?php echo escape($success); ?>
            </div>
        <?php endif; ?>

        <!-- Übersichts-Dashboard -->
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-number"><?php echo $stats['active_teachers']; ?></div>
                <div class="stat-label">Aktive Lehrer</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $stats['active_groups']; ?></div>
                <div class="stat-label">Aktive Gruppen</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $stats['total_assignments']; ?></div>
                <div class="stat-label">Schülerzuordnungen</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $stats['total_ratings']; ?></div>
                <div class="stat-label">Bewertungen</div>
            </div>
        </div>

        <div class="dashboard-grid">
            <!-- Gefundene Probleme -->
            <div class="card">
                <h2 class="card-title">⚠️ Gefundene Probleme</h2>
                
                <?php if (empty($problems)): ?>
                    <div class="no-problems">
                        <div class="icon">✅</div>
                        <h3>Keine Probleme gefunden!</h3>
                        <p>Alle Daten sind konsistent.</p>
                    </div>
                <?php else: ?>
                    <div class="problems-list">
                        <?php foreach ($problems as $problem): ?>
                            <div class="problem-item <?php echo $problem['severity']; ?>">
                                <div class="problem-title">
                                    <?php echo escape($problem['title']); ?>
                                    <span class="problem-count"><?php echo $problem['count']; ?></span>
                                </div>
                                <div class="problem-description">
                                    <?php echo escape($problem['description']); ?>
                                </div>
                                <?php if ($problem['affected']): ?>
                                    <div class="problem-affected">
                                        Betroffen: <?php echo escape(substr($problem['affected'], 0, 100)); ?>
                                        <?php if (strlen($problem['affected']) > 100): ?>...<?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Bereinigungstools -->
            <div class="card">
                <h2 class="card-title">🛠️ Bereinigungstools</h2>
                
                <div class="tools-grid">
                    <div class="tool-card">
                        <div class="tool-title">Verwaiste Zuordnungen</div>
                        <div class="tool-description">
                            Entfernt alle Verweise auf gelöschte Lehrer automatisch.
                        </div>
                        <form method="POST" onsubmit="return confirm('Alle verwaisten Zuordnungen bereinigen?')">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="cleanup_orphaned">
                            <button type="submit" class="btn btn-warning">
                                🧹 Bereinigen
                            </button>
                        </form>
                    </div>
                    
                    <div class="tool-card">
                        <div class="tool-title">Lehrer sicher löschen</div>
                        <div class="tool-description">
                            Löscht einen Lehrer und bereinigt alle Zuordnungen automatisch.
                        </div>
                        <a href="admin_lehrer.php" class="btn btn-danger">
                            🗑️ Zur Lehrerverwaltung
                        </a>
                    </div>
                    
                    <div class="tool-card">
                        <div class="tool-title">Datenintegrität prüfen</div>
                        <div class="tool-description">
                            Überprüft alle Verweise zwischen Tabellen auf Konsistenz.
                        </div>
                        <button class="btn btn-secondary" onclick="window.location.reload()">
                            🔍 Erneut prüfen
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Zusätzliche Tools -->
        <div class="card">
            <h2 class="card-title">🔗 Weitere Tools</h2>
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <a href="admin_data_management.php" class="btn btn-secondary">
                    💾 Datenmanagement
                </a>
                <a href="admin_klassen.php" class="btn btn-secondary">
                    🏫 Klassenverwaltung
                </a>
                <a href="admin_lehrer.php" class="btn btn-secondary">
                    👨‍🏫 Lehrerverwaltung
                </a>
            </div>
        </div>
    </div>
</body>
</html>