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

        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .module-card {
            background: #3d3d3d;
            border: 2px solid transparent;
            border-radius: 12px;
            padding: 25px;
            text-decoration: none;
            color: #e0e0e0;
            transition: all 0.3s ease;
            display: block;
        }

        .module-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(91, 103, 202, 0.3);
            border-color: #5b67ca;
            text-decoration: none;
            color: #e0e0e0;
        }

        .module-card.disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .module-card.disabled:hover {
            transform: none;
            box-shadow: none;
            border-color: transparent;
        }

        .module-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .module-icon {
            font-size: 40px;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(91, 103, 202, 0.2);
            border-radius: 12px;
            border: 2px solid rgba(91, 103, 202, 0.3);
        }

        .module-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 5px;
            color: #5b67ca;
        }

        .module-subtitle {
            font-size: 14px;
            opacity: 0.8;
        }

        .module-description {
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 15px;
            opacity: 0.8;
        }

        .module-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 500;
        }

        .status-available {
            background: rgba(39, 174, 96, 0.2);
            color: #2ecc71;
            border: 1px solid rgba(39, 174, 96, 0.3);
        }

        .status-development {
            background: rgba(243, 156, 18, 0.2);
            color: #f39c12;
            border: 1px solid rgba(243, 156, 18, 0.3);
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

            .modules-grid {
                grid-template-columns: 1fr;
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
            <a href="?page=themen" class="nav-tab <?= $page === 'themen' ? 'active' : '' ?>">📚 Themen</a>
            <a href="?page=gruppen" class="nav-tab <?= $page === 'gruppen' ? 'active' : '' ?>">👥 Gruppen verwalten</a>
            <a href="?page=bewerten" class="nav-tab <?= $page === 'bewerten' ? 'active' : '' ?>">⭐ Schüler bewerten</a>
            <a href="?page=vorlagen" class="nav-tab <?= $page === 'vorlagen' ? 'active' : '' ?>">📋 Bewertungsvorlagen</a>
            <a href="?page=uebersicht" class="nav-tab <?= $page === 'uebersicht' ? 'active' : '' ?>">📊 Übersicht</a>
            <a href="?page=einstellungen" class="nav-tab <?= $page === 'einstellungen' ? 'active' : '' ?>">⚙️ Einstellungen</a>
        </div>

        <div class="tab-content">
            <?php
            switch ($page) {
                case 'news':
                    echo '<div class="welcome-message">';
                    echo '<h2>Willkommen, ' . htmlspecialchars($teacher['name']) . '!</h2>';
                    echo '<p>Hier finden Sie aktuelle Informationen und Neuigkeiten rund um das Bewertungssystem.</p>';
                    echo '</div>';
                    
                    echo '<div style="background: #3d3d3d; padding: 30px; border-radius: 12px;">';
                    echo '<h3 style="margin-bottom: 20px; color: #5b67ca;">📰 Aktuelle News</h3>';
                    echo '<div style="border-left: 4px solid #5b67ca; padding-left: 20px; margin-bottom: 20px;">';
                    echo '<h4 style="color: #e0e0e0; margin-bottom: 8px;">System erfolgreich eingerichtet</h4>';
                    echo '<p style="color: #b0b0b0; margin-bottom: 5px;">Das Bewertungssystem wurde erfolgreich für Ihre Schule konfiguriert.</p>';
                    echo '<small style="color: #888;">Heute</small>';
                    echo '</div>';
                    echo '<div style="border-left: 4px solid #27ae60; padding-left: 20px;">';
                    echo '<h4 style="color: #e0e0e0; margin-bottom: 8px;">Erste Schritte</h4>';
                    echo '<p style="color: #b0b0b0; margin-bottom: 5px;">Beginnen Sie mit der Erstellung von Themen und Bewertungsvorlagen.</p>';
                    echo '<small style="color: #888;">Heute</small>';
                    echo '</div>';
                    echo '</div>';
                    break;
                    
                case 'themen':
                    echo '<h2>📚 Themen verwalten</h2>';
                    echo '<p style="margin-bottom: 30px; color: #b0b0b0;">Hier können Sie Unterrichtsthemen erstellen und verwalten, die später für Bewertungen verwendet werden.</p>';
                    
                    echo '<div class="modules-grid">';
                    
                    // Link zur separaten Themen-Seite
                    echo '<a href="lehrer_themen.php" class="module-card">';
                    echo '<div class="module-header">';
                    echo '<div class="module-icon">📚</div>';
                    echo '<div>';
                    echo '<div class="module-title">Themen-Manager</div>';
                    echo '<div class="module-subtitle">Vollständige Themenverwaltung</div>';
                    echo '</div>';
                    echo '</div>';
                    echo '<div class="module-description">Erstellen, bearbeiten und verwalten Sie alle Projektthemen für Ihre Schüler. Ordnen Sie Fächer zu und organisieren Sie Ihre Unterrichtsinhalte.</div>';
                    echo '<div class="module-status">';
                    echo '<span class="status-badge status-available">✅ Verfügbar</span>';
                    echo '</div>';
                    echo '</a>';
                    
                    echo '</div>';
                    break;
                    
                case 'gruppen':
                    echo '<h2>👥 Gruppen verwalten</h2>';
                    echo '<p style="margin-bottom: 30px; color: #b0b0b0;">Erstellen und verwalten Sie Arbeitsgruppen für Ihre Klassen.</p>';
                    
                    echo '<div style="background: #3d3d3d; padding: 30px; border-radius: 12px; margin-bottom: 20px;">';
                    echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">';
                    echo '<h3 style="color: #5b67ca;">Aktive Gruppen</h3>';
                    echo '<button style="background: #5b67ca; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer;">+ Neue Gruppe</button>';
                    echo '</div>';
                    echo '<div style="text-align: center; padding: 40px; color: #888;">';
                    echo '<span style="font-size: 48px;">👥</span>';
                    echo '<h3 style="margin: 20px 0;">Noch keine Gruppen erstellt</h3>';
                    echo '<p>Erstellen Sie Arbeitsgruppen basierend auf Schülerstärken und Themen.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;
                    
                case 'bewerten':
                    echo '<h2>⭐ Schüler bewerten</h2>';
                    echo '<p style="margin-bottom: 30px; color: #b0b0b0;">Bewerten Sie Ihre Schüler anhand der definierten Kriterien und Themen.</p>';
                    
                    echo '<div style="background: #3d3d3d; padding: 30px; border-radius: 12px;">';
                    echo '<div style="text-align: center; padding: 40px; color: #888;">';
                    echo '<span style="font-size: 48px;">⭐</span>';
                    echo '<h3 style="margin: 20px 0;">Bewertungssystem in Vorbereitung</h3>';
                    echo '<p>Das Bewertungsmodul wird nach der Erstellung von Themen und Gruppen verfügbar sein.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;

                case 'vorlagen':
                    echo '<h2>📋 Bewertungsvorlagen</h2>';
                    echo '<p style="margin-bottom: 30px; color: #b0b0b0;">Erstellen und verwalten Sie Bewertungsvorlagen für wiederkehrende Aufgaben.</p>';
                    
                    echo '<div style="background: #3d3d3d; padding: 30px; border-radius: 12px;">';
                    echo '<div style="text-align: center; padding: 40px; color: #888;">';
                    echo '<span style="font-size: 48px;">📋</span>';
                    echo '<h3 style="margin: 20px 0;">Vorlagen-System in Entwicklung</h3>';
                    echo '<p>Erstellen Sie wiederverwendbare Bewertungsvorlagen für verschiedene Projekttypen.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;

                case 'uebersicht':
                    echo '<h2>📊 Übersicht</h2>';
                    echo '<p style="margin-bottom: 30px; color: #b0b0b0;">Statistische Auswertungen und Berichte zu Ihren Bewertungen.</p>';
                    
                    echo '<div style="background: #3d3d3d; padding: 30px; border-radius: 12px;">';
                    echo '<div style="text-align: center; padding: 40px; color: #888;">';
                    echo '<span style="font-size: 48px;">📊</span>';
                    echo '<h3 style="margin: 20px 0;">Statistiken werden erstellt</h3>';
                    echo '<p>Nach den ersten Bewertungen werden hier detaillierte Auswertungen angezeigt.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;

                case 'einstellungen':
                    echo '<h2>⚙️ Einstellungen</h2>';
                    echo '<p style="margin-bottom: 30px; color: #b0b0b0;">Persönliche Einstellungen und Präferenzen anpassen.</p>';
                    
                    echo '<div style="background: #3d3d3d; padding: 30px; border-radius: 12px;">';
                    echo '<div style="text-align: center; padding: 40px; color: #888;">';
                    echo '<span style="font-size: 48px;">⚙️</span>';
                    echo '<h3 style="margin: 20px 0;">Einstellungen in Vorbereitung</h3>';
                    echo '<p>Hier können Sie bald Ihre persönlichen Präferenzen anpassen.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;

                default:
                    echo '<h2>Seite nicht gefunden</h2>';
                    echo '<p>Die angeforderte Seite existiert nicht.</p>';
                    break;
            }
            ?>
        </div>
    </div>
</body>
</html>