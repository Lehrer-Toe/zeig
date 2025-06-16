<?php
require_once '../config.php';

// Lehrer-Zugriff prüfen
if (!isLoggedIn() || $_SESSION['user_type'] !== 'lehrer') {
    header('Location: ../index.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];
$page = isset($_GET['page']) ? $_GET['page'] : 'news';

// Schul-Lizenz prüfen
if (isset($_SESSION['school_id'])) {
    requireValidSchoolLicense($_SESSION['school_id']);
}

// Lehrerdaten und Schulinfo abrufen
$db = getDB();
$stmt = $db->prepare("
    SELECT u.*, s.name as school_name, s.school_type
    FROM users u
    JOIN schools s ON u.school_id = s.id
    WHERE u.id = ? AND u.user_type = 'lehrer'
");
$stmt->execute([$teacher_id]);
$teacher = $stmt->fetch();

if (!$teacher) {
    die('Lehrerdaten konnten nicht geladen werden.');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lehrer Dashboard - <?= htmlspecialchars($teacher['school_name']) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background-color: #1a1a1a;
            color: #e0e0e0;
            line-height: 1.6;
        }

        .header {
            background: linear-gradient(135deg, #5b67ca 0%, #7b85ff 100%);
            padding: 20px 40px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: white;
            font-size: 28px;
            font-weight: 600;
        }

        .header-info {
            display: flex;
            align-items: center;
            gap: 30px;
            color: white;
        }

        .school-info {
            text-align: right;
        }

        .school-name {
            font-weight: 600;
            font-size: 16px;
        }

        .school-type {
            font-size: 14px;
            opacity: 0.9;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logout-btn {
            background: #e74c3c;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            transition: background-color 0.2s;
        }

        .logout-btn:hover {
            background: #c0392b;
        }

        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 40px;
        }

        .nav-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
        }

        .nav-tab {
            padding: 12px 24px;
            background: #2d2d2d;
            color: #e0e0e0;
            text-decoration: none;
            border-radius: 8px 8px 0 0;
            border: 2px solid #333;
            border-bottom: none;
            transition: all 0.2s;
        }

        .nav-tab:hover {
            background: #3d3d3d;
        }

        .nav-tab.active {
            background: #5b67ca;
            border-color: #5b67ca;
            color: white;
        }

        .tab-content {
            background: #2d2d2d;
            padding: 30px;
            border-radius: 12px;
            min-height: 400px;
        }

        .welcome-message {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #3d3d3d;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #5b67ca;
            margin-bottom: 8px;
        }

        .stat-label {
            color: #b0b0b0;
            font-size: 14px;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .action-card {
            background: #3d3d3d;
            padding: 25px;
            border-radius: 12px;
            text-decoration: none;
            color: #e0e0e0;
            transition: transform 0.2s, box-shadow 0.2s;
            border: 2px solid transparent;
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(91, 103, 202, 0.3);
            border-color: #5b67ca;
        }

        .action-icon {
            font-size: 40px;
            margin-bottom: 15px;
            display: block;
        }

        .action-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .action-description {
            color: #b0b0b0;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 20px;
            }
            
            .header-content {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .nav-tabs {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>Lehrer Dashboard</h1>
            <div class="header-info">
                <div class="school-info">
                    <div class="school-name"><?= htmlspecialchars($teacher['school_name']) ?></div>
                    <div class="school-type"><?= htmlspecialchars($teacher['school_type']) ?></div>
                </div>
                <div class="user-info">
                    <span>👨‍🏫 <?= htmlspecialchars($teacher['name']) ?></span>
                    <a href="../logout.php" class="logout-btn">Abmelden</a>
                </div>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="nav-tabs">
            <a href="?page=news" class="nav-tab <?= $page === 'news' ? 'active' : '' ?>">📰 News</a>
            <a href="?page=klassen" class="nav-tab <?= $page === 'klassen' ? 'active' : '' ?>">👥 Meine Klassen</a>
            <a href="?page=bewertungen" class="nav-tab <?= $page === 'bewertungen' ? 'active' : '' ?>">⭐ Bewertungen</a>
            <a href="?page=gruppen" class="nav-tab <?= $page === 'gruppen' ? 'active' : '' ?>">👥 Gruppen</a>
            <a href="?page=berichte" class="nav-tab <?= $page === 'berichte' ? 'active' : '' ?>">📊 Berichte</a>
        </div>

        <div class="tab-content">
            <?php
            switch ($page) {
                case 'news':
                    echo '<div class="welcome-message">';
                    echo '<h2>Willkommen, ' . htmlspecialchars($teacher['name']) . '!</h2>';
                    echo '<p>Hier finden Sie aktuelle Informationen und können auf alle wichtigen Funktionen zugreifen.</p>';
                    echo '</div>';
                    
                    echo '<div class="stats-grid">';
                    echo '<div class="stat-card"><div class="stat-number">0</div><div class="stat-label">Aktive Klassen</div></div>';
                    echo '<div class="stat-card"><div class="stat-number">0</div><div class="stat-label">Schüler gesamt</div></div>';
                    echo '<div class="stat-card"><div class="stat-number">0</div><div class="stat-label">Bewertungen diese Woche</div></div>';
                    echo '<div class="stat-card"><div class="stat-number">0</div><div class="stat-label">Aktive Gruppen</div></div>';
                    echo '</div>';
                    
                    echo '<div class="quick-actions">';
                    echo '<a href="?page=klassen" class="action-card">';
                    echo '<span class="action-icon">👥</span>';
                    echo '<div class="action-title">Klassen verwalten</div>';
                    echo '<div class="action-description">Übersicht über Ihre Klassen und Schüler</div>';
                    echo '</a>';
                    
                    echo '<a href="?page=bewertungen" class="action-card">';
                    echo '<span class="action-icon">⭐</span>';
                    echo '<div class="action-title">Bewertungen erfassen</div>';
                    echo '<div class="action-description">Schülerstärken bewerten und dokumentieren</div>';
                    echo '</a>';
                    
                    echo '<a href="?page=gruppen" class="action-card">';
                    echo '<span class="action-icon">👥</span>';
                    echo '<div class="action-title">Gruppen erstellen</div>';
                    echo '<div class="action-description">Arbeitsgruppen für Projekte zusammenstellen</div>';
                    echo '</a>';
                    
                    echo '<a href="?page=berichte" class="action-card">';
                    echo '<span class="action-icon">📊</span>';
                    echo '<div class="action-title">Berichte generieren</div>';
                    echo '<div class="action-description">Auswertungen und Übersichten erstellen</div>';
                    echo '</a>';
                    echo '</div>';
                    break;
                    
                case 'klassen':
                    echo '<h2>Meine Klassen</h2>';
                    echo '<p>Hier werden Ihre zugewiesenen Klassen angezeigt.</p>';
                    echo '<div style="background: #3d3d3d; padding: 40px; text-align: center; border-radius: 12px; margin-top: 20px;">';
                    echo '<span style="font-size: 48px;">👥</span>';
                    echo '<h3 style="margin: 20px 0;">Noch keine Klassen zugewiesen</h3>';
                    echo '<p style="color: #b0b0b0;">Wenden Sie sich an Ihren Schuladministrator, um Klassen zugewiesen zu bekommen.</p>';
                    echo '</div>';
                    break;
                    
                case 'bewertungen':
                    echo '<h2>Bewertungen</h2>';
                    echo '<p>Erfassen und verwalten Sie Schülerbewertungen.</p>';
                    echo '<div style="background: #3d3d3d; padding: 40px; text-align: center; border-radius: 12px; margin-top: 20px;">';
                    echo '<span style="font-size: 48px;">⭐</span>';
                    echo '<h3 style="margin: 20px 0;">Bewertungsmodul</h3>';
                    echo '<p style="color: #b0b0b0;">Dieses Modul wird nach der Klassenzuweisung verfügbar.</p>';
                    echo '</div>';
                    break;
                    
                case 'gruppen':
                    echo '<h2>Gruppen</h2>';
                    echo '<p>Erstellen und verwalten Sie Arbeitsgruppen.</p>';
                    echo '<div style="background: #3d3d3d; padding: 40px; text-align: center; border-radius: 12px; margin-top: 20px;">';
                    echo '<span style="font-size: 48px;">👥</span>';
                    echo '<h3 style="margin: 20px 0;">Gruppenmodul</h3>';
                    echo '<p style="color: #b0b0b0;">Erstellen Sie Arbeitsgruppen basierend auf Schülerstärken.</p>';
                    echo '</div>';
                    break;
                    
                case 'berichte':
                    echo '<h2>Berichte</h2>';
                    echo '<p>Generieren Sie Auswertungen und Übersichten.</p>';
                    echo '<div style="background: #3d3d3d; padding: 40px; text-align: center; border-radius: 12px; margin-top: 20px;">';
                    echo '<span style="font-size: 48px;">📊</span>';
                    echo '<h3 style="margin: 20px 0;">Berichtsmodul</h3>';
                    echo '<p style="color: #b0b0b0;">Erstellen Sie detaillierte Berichte über Schülerleistungen.</p>';
                    echo '</div>';
                    break;
                    
                default:
                    echo '<h2>Seite nicht gefunden</h2>';
                    echo '<p>Die angeforderte Seite existiert nicht.</p>';
            }
            ?>
        </div>
    </div>
</body>
</html>
