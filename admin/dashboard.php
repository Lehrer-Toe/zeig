<?php
require_once '../config.php';

// Überprüfung ob Benutzer eingeloggt und Admin ist
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'schuladmin') {
    header("Location: ../index.php");
    exit();
}

$adminId = $_SESSION['user_id'];
$schoolId = $_SESSION['school_id'];
$db = getDB();

// Schulinformationen abrufen
$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

if (!$school) {
    die('Schule nicht gefunden.');
}

// Statistiken
// Anzahl Klassen
$stmt = $db->prepare("SELECT COUNT(*) as count FROM classes WHERE school_id = ? AND is_active = 1");
$stmt->execute([$schoolId]);
$classCount = $stmt->fetch()['count'];

// Anzahl Lehrer
$stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE school_id = ? AND user_type = 'lehrer' AND is_active = 1");
$stmt->execute([$schoolId]);
$teacherCount = $stmt->fetch()['count'];

// Anzahl Schüler (nur aus aktiven Klassen)
$stmt = $db->prepare("
    SELECT COUNT(*) as count 
    FROM students s 
    JOIN classes c ON s.class_id = c.id 
    WHERE s.school_id = ? 
    AND s.is_active = 1 
    AND c.is_active = 1
");
$stmt->execute([$schoolId]);
$studentCount = $stmt->fetch()['count'];

// Anzahl Bewertungen
$stmt = $db->prepare("SELECT COUNT(*) as count FROM ratings r JOIN students s ON r.student_id = s.id WHERE s.school_id = ?");
$stmt->execute([$schoolId]);
$ratingCount = $stmt->fetch()['count'];

// Flash Messages
$flashMessage = null;
$flashType = null;
if (isset($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

// Lizenzstatus prüfen
$licenseExpired = false;
$licenseWarning = false;
$daysRemaining = 0;

if ($school['license_until']) {
    $licenseDate = new DateTime($school['license_until']);
    $today = new DateTime();
    $interval = $today->diff($licenseDate);
    $daysRemaining = $interval->days;
    
    if ($today > $licenseDate) {
        $licenseExpired = true;
    } elseif ($daysRemaining <= 30) {
        $licenseWarning = true;
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo htmlspecialchars($school['name']); ?></title>
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info span {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.5rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-secondary {
            background: rgba(100, 116, 139, 0.2);
            color: #cbd5e1;
            border: 1px solid rgba(100, 116, 139, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(100, 116, 139, 0.3);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .welcome-section {
            margin-bottom: 2rem;
        }

        .welcome-title {
            font-size: 2rem;
            font-weight: 700;
            color: #60a5fa;
            margin-bottom: 0.5rem;
        }

        .welcome-text {
            font-size: 1.1rem;
            opacity: 0.8;
            line-height: 1.6;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .stat-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .stat-icon {
            font-size: 1.5rem;
        }

        .stat-title {
            font-size: 0.9rem;
            opacity: 0.7;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #3b82f6;
            margin-bottom: 0.5rem;
        }

        .stat-subtitle {
            font-size: 0.8rem;
            opacity: 0.6;
        }

        .license-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
            padding: 1rem;
            border-radius: 1rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .license-expired {
            background: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .module-card {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(100, 116, 139, 0.2);
            border-radius: 1rem;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .module-card:hover {
            border-color: #3b82f6;
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            text-decoration: none;
            color: inherit;
        }

        .module-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .module-icon {
            font-size: 2rem;
            width: 3rem;
            height: 3rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(59, 130, 246, 0.1);
            border-radius: 0.75rem;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .module-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #3b82f6;
            margin-bottom: 0.25rem;
        }

        .module-subtitle {
            font-size: 0.9rem;
            opacity: 0.7;
        }

        .module-description {
            font-size: 0.9rem;
            line-height: 1.5;
            opacity: 0.8;
            margin-bottom: 1rem;
        }

        .module-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-active {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .status-coming-soon {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
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
    </style>
</head>
<body>
    <div class="header">
        <h1>🎓 Admin Dashboard - <?php echo htmlspecialchars($school['name']); ?></h1>
        <div class="user-info">
            <span>👋 Admin</span>
            <a href="../logout.php" class="btn btn-secondary">🚪 Abmelden</a>
        </div>
    </div>

    <div class="container">
        <?php if ($flashMessage): ?>
            <div class="flash-message flash-<?php echo $flashType; ?>">
                <?php echo htmlspecialchars($flashMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($licenseExpired): ?>
            <div class="license-warning license-expired">
                <span style="font-size: 1.5rem;">⚠️</span>
                <div>
                    <strong>Lizenz abgelaufen!</strong> Ihre Lizenz ist am <?php echo date('d.m.Y', strtotime($school['license_until'])); ?> abgelaufen.
                    Bitte kontaktieren Sie den Support zur Verlängerung.
                </div>
            </div>
        <?php elseif ($licenseWarning): ?>
            <div class="license-warning">
                <span style="font-size: 1.5rem;">⏰</span>
                <div>
                    <strong>Lizenz läuft bald ab!</strong> Ihre Lizenz läuft in <?php echo $daysRemaining; ?> Tagen ab (<?php echo date('d.m.Y', strtotime($school['license_until'])); ?>).
                    Verlängern Sie rechtzeitig.
                </div>
            </div>
        <?php endif; ?>

        <div class="welcome-section">
            <h2 class="welcome-title">Willkommen im Verwaltungsbereich</h2>
            <p class="welcome-text">
                Hier können Sie alle wichtigen Aspekte Ihrer Schule verwalten. 
                Nutzen Sie die Module unten, um Klassen zu erstellen, Lehrer zu verwalten und Bewertungen zu organisieren.
            </p>
        </div>

        <!-- Statistiken -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-icon">🏫</span>
                    <span class="stat-title">Klassen</span>
                </div>
                <div class="stat-number"><?php echo $classCount; ?></div>
                <div class="stat-subtitle">von max. <?php echo $school['max_classes']; ?> Klassen</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-icon">👨‍🏫</span>
                    <span class="stat-title">Lehrer</span>
                </div>
                <div class="stat-number"><?php echo $teacherCount; ?></div>
                <div class="stat-subtitle">registrierte Lehrkräfte</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-icon">🎓</span>
                    <span class="stat-title">Schüler</span>
                </div>
                <div class="stat-number"><?php echo $studentCount; ?></div>
                <div class="stat-subtitle">in aktiven Klassen</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-icon">⭐</span>
                    <span class="stat-title">Bewertungen</span>
                </div>
                <div class="stat-number"><?php echo $ratingCount; ?></div>
                <div class="stat-subtitle">erstellte Bewertungen</div>
            </div>
        </div>

        <!-- Module -->
        <div class="modules-grid">
            <a href="admin_klassen.php" class="module-card">
                <div class="module-header">
                    <div class="module-icon">🏫</div>
                    <div>
                        <div class="module-title">Klassenverwaltung</div>
                        <div class="module-subtitle">Klassen & Schüler</div>
                    </div>
                </div>
                <div class="module-description">
                    Erstellen und verwalten Sie Schulklassen, fügen Sie Schüler hinzu und organisieren Sie die Klassenstruktur.
                </div>
                <div class="module-status">
                    <span class="status-badge status-active">Verfügbar</span>
                </div>
            </a>

            <a href="admin_lehrer.php" class="module-card">
                <div class="module-header">
                    <div class="module-icon">👨‍🏫</div>
                    <div>
                        <div class="module-title">Lehrerverwaltung</div>
                        <div class="module-subtitle">Lehrkräfte & Zuordnungen</div>
                    </div>
                </div>
                <div class="module-description">
                    Verwalten Sie Lehreraccounts, weisen Sie Klassen zu und organisieren Sie Berechtigungen.
                </div>
                <div class="module-status">
                    <span class="status-badge status-active">Verfügbar</span>
                </div>
            </a>

            <a href="admin_faecher.php" class="module-card">
                <div class="module-header">
                    <div class="module-icon">📚</div>
                    <div>
                        <div class="module-title">Fächerverwaltung</div>
                        <div class="module-subtitle">Schulfächer & Curricula</div>
                    </div>
                </div>
                <div class="module-description">
                    Definieren Sie Schulfächer, erstellen Sie Lehrpläne und ordnen Sie Fächer den Klassen zu.
                </div>
                <div class="module-status">
                    <span class="status-badge status-active">Verfügbar</span>
                </div>
            </a>

            <a href="admin_staerken.php" class="module-card">
                <div class="module-header">
                    <div class="module-icon">💪</div>
                    <div>
                        <div class="module-title">Stärkenverwaltung</div>
                        <div class="module-subtitle">Kompetenz-Profile</div>
                    </div>
                </div>
                <div class="module-description">
                    Verwalten Sie Kompetenzbereiche und Stärkenprofile für eine differenzierte Bewertung.
                </div>
                <div class="module-status">
                    <span class="status-badge status-coming-soon">Geplant</span>
                </div>
            </a>

            <a href="admin_schreiben.php" class="module-card">
                <div class="module-header">
                    <div class="module-icon">📝</div>
                    <div>
                        <div class="module-title">Dokumentvorlagen</div>
                        <div class="module-subtitle">Zeugnisse & Berichte</div>
                    </div>
                </div>
                <div class="module-description">
                    Verwalten Sie Dokumentvorlagen mit Platzhaltern für automatisierte Zeugniserstellung.
                </div>
                <div class="module-status">
                    <span class="status-badge status-active">Verfügbar</span>
                </div>
            </a>

            <a href="admin_data_management.php" class="module-card">
                <div class="module-header">
                    <div class="module-icon">💾</div>
                    <div>
                        <div class="module-title">Datenmanagement</div>
                        <div class="module-subtitle">Export, Import & Bereinigung</div>
                    </div>
                </div>
                <div class="module-description">
                    Exportieren Sie Schuldaten, erstellen Sie Backups oder bereinigen Sie Daten für eine Neueinrichtung.
                </div>
                <div class="module-status">
                    <span class="status-badge status-active">Verfügbar</span>
                </div>
            </a>

            <a href="admin_uebersicht.php" class="module-card">
                <div class="module-header">
                    <div class="module-icon">📊</div>
                    <div>
                        <div class="module-title">Gesamtübersicht</div>
                        <div class="module-subtitle">Analytics & Trends</div>
                    </div>
                </div>
                <div class="module-description">
                    Analysieren Sie Leistungsdaten, verfolgen Sie Trends und erhalten Sie detaillierte Einblicke.
                </div>
                <div class="module-status">
                    <span class="status-badge status-coming-soon">Geplant</span>
                </div>
            </a>

            <a href="admin_news.php" class="module-card">
                <div class="module-header">
                    <div class="module-icon">📢</div>
                    <div>
                        <div class="module-title">Mitteilungen</div>
                        <div class="module-subtitle">News & Updates</div>
                    </div>
                </div>
                <div class="module-description">
                    Verwalten Sie Schulnachrichten, senden Sie Mitteilungen und informieren Sie über wichtige Updates.
                </div>
                <div class="module-status">
                    <span class="status-badge status-coming-soon">Geplant</span>
                </div>
            </a>
        </div>
    </div>
</body>
</html>