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
            background: linear-gradient(to bottom, #999999 0%, #ff9900 100%);
            color: #001133;
            line-height: 1.6;
            min-height: 100vh;
        }

        .header {
            height: 180px;
            background: linear-gradient(135deg, #002b45 0%, #063b52 100%);
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 8px;
            background: #ff9900;
        }

        .header-content {
            text-align: center;
            z-index: 2;
        }

        .header h1 {
            color: white;
            font-size: 36px;
            font-weight: 600;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .header-info {
            color: rgba(255,255,255,0.9);
            margin-top: 10px;
            font-size: 16px;
        }

        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 60px 40px;
            position: relative;
        }

        .nav-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 40px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .nav-tab {
            padding: 15px 30px;
            background: rgba(255, 255, 255, 0.9);
            color: #001133;
            text-decoration: none;
            border-radius: 999px;
            border: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }

        .nav-tab:hover {
            background: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
        }

        .nav-tab.active {
            background: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
        }

        .tab-content {
            background: rgba(255, 255, 255, 0.95);
            padding: 40px;
            border-radius: 20px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            min-height: 500px;
        }

        .welcome-message {
            background: linear-gradient(135deg, #002b45, #063b52);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
        }

        .welcome-message h2 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .welcome-message p {
            font-size: 16px;
            opacity: 0.9;
        }

        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
        }

        .module-card {
            background: rgba(255, 255, 255, 0.8);
            border: 2px solid transparent;
            border-radius: 15px;
            padding: 30px;
            text-decoration: none;
            color: #001133;
            transition: all 0.3s ease;
            display: block;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        }

        .module-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
            border-color: #ff9900;
            background: rgba(255, 255, 255, 0.95);
            text-decoration: none;
            color: #001133;
        }

        .module-card.disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .module-card.disabled:hover {
            transform: none;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            border-color: transparent;
        }

        .module-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .module-icon {
            font-size: 48px;
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #002b45, #063b52);
            border-radius: 15px;
            color: white;
        }

        .module-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 5px;
            color: #002b45;
        }

        .module-subtitle {
            font-size: 14px;
            opacity: 0.7;
        }

        .module-description {
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 20px;
            opacity: 0.8;
        }

        .module-status {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-available {
            background: rgba(34, 197, 94, 0.2);
            color: #15803d;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .status-development {
            background: rgba(255, 153, 0, 0.2);
            color: #d97706;
            border: 1px solid rgba(255, 153, 0, 0.3);
        }

        .action-button {
            background: #ffffff;
            color: #001133;
            border: 2px solid #ff9900;
            padding: 15px 30px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            margin: 20px auto;
            text-align: center;
        }

        .action-button:hover {
            background: #ff9900;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 153, 0, 0.3);
        }

        .logout-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(231, 76, 60, 0.9);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 999px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }

        .content-section {
            background: rgba(255, 255, 255, 0.9);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        }

        .content-section h3 {
            margin-bottom: 20px;
            color: #002b45;
            font-size: 24px;
        }

        .news-item {
            border-left: 4px solid #ff9900;
            padding-left: 20px;
            margin-bottom: 20px;
        }

        .news-item h4 {
            color: #002b45;
            margin-bottom: 8px;
            font-size: 18px;
        }

        .news-item p {
            color: #666;
            margin-bottom: 5px;
        }

        .news-item small {
            color: #999;
            font-size: 12px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: #666;
        }

        .empty-state-icon {
            font-size: 72px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            margin: 20px 0;
            color: #002b45;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 30px 20px;
            }
            
            .header h1 {
                font-size: 28px;
            }
            
            .nav-tabs {
                flex-direction: column;
                align-items: center;
            }

            .modules-grid {
                grid-template-columns: 1fr;
            }

            .tab-content {
                padding: 20px;
            }

            .logout-btn {
                position: relative;
                top: auto;
                right: auto;
                margin: 10px auto;
                display: block;
                width: fit-content;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1><?= htmlspecialchars($teacher['school_name']) ?></h1>
            <div class="header-info">
                <div>👨‍🏫 <?= htmlspecialchars($teacher['name']) ?></div>
            </div>
        </div>
        <a href="../logout.php" class="logout-btn">Abmelden</a>
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
                    
                    echo '<div class="content-section">';
                    echo '<h3>📰 Aktuelle News</h3>';
                    echo '<div class="news-item">';
                    echo '<h4>System erfolgreich eingerichtet</h4>';
                    echo '<p>Das Bewertungssystem wurde erfolgreich für Ihre Schule konfiguriert.</p>';
                    echo '<small>Heute</small>';
                    echo '</div>';
                    echo '<div class="news-item">';
                    echo '<h4>Erste Schritte</h4>';
                    echo '<p>Beginnen Sie mit der Erstellung von Themen und Bewertungsvorlagen.</p>';
                    echo '<small>Heute</small>';
                    echo '</div>';
                    echo '</div>';
                    echo '<div style="text-align: center;">';
                    echo '<a href="?page=themen" class="action-button">Jetzt starten →</a>';
                    echo '</div>';
                    break;
                    
                case 'themen':
                    // Prüfen ob die lehrer_themen.php existiert
                    if (file_exists('lehrer_themen.php')) {
                        // Die Datei direkt einbinden
                        include 'lehrer_themen.php';
                    } else {
                        // Fallback, falls die Datei nicht gefunden wird
                        echo '<div class="content-section">';
                        echo '<div class="empty-state">';
                        echo '<span class="empty-state-icon">⚠️</span>';
                        echo '<h3>Themen-Modul nicht gefunden</h3>';
                        echo '<p>Die Datei lehrer_themen.php konnte nicht geladen werden.</p>';
                        echo '<a href="lehrer_themen.php" class="action-button">Zur separaten Themen-Seite →</a>';
                        echo '</div>';
                        echo '</div>';
                    }
                    break;
                    
                case 'gruppen':
                    echo '<h2 style="text-align: center; color: #002b45; margin-bottom: 30px;">👥 Gruppen verwalten</h2>';
                    echo '<p style="text-align: center; margin-bottom: 30px; color: #666;">Erstellen und verwalten Sie Arbeitsgruppen für Ihre Klassen.</p>';
                    
                    echo '<div class="content-section">';
                    echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">';
                    echo '<h3>Aktive Gruppen</h3>';
                    echo '<button class="action-button">+ Neue Gruppe</button>';
                    echo '</div>';
                    echo '<div class="empty-state">';
                    echo '<span class="empty-state-icon">👥</span>';
                    echo '<h3>Noch keine Gruppen erstellt</h3>';
                    echo '<p>Erstellen Sie Arbeitsgruppen basierend auf Schülerstärken und Themen.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;
                    
                case 'bewerten':
                    echo '<h2 style="text-align: center; color: #002b45; margin-bottom: 30px;">⭐ Schüler bewerten</h2>';
                    echo '<p style="text-align: center; margin-bottom: 30px; color: #666;">Bewerten Sie Ihre Schüler anhand der definierten Kriterien und Themen.</p>';
                    
                    echo '<div class="content-section">';
                    echo '<div class="empty-state">';
                    echo '<span class="empty-state-icon">⭐</span>';
                    echo '<h3>Bewertungssystem in Vorbereitung</h3>';
                    echo '<p>Das Bewertungsmodul wird nach der Erstellung von Themen und Gruppen verfügbar sein.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;

                case 'vorlagen':
                    echo '<h2 style="text-align: center; color: #002b45; margin-bottom: 30px;">📋 Bewertungsvorlagen</h2>';
                    echo '<p style="text-align: center; margin-bottom: 30px; color: #666;">Erstellen und verwalten Sie Bewertungsvorlagen für wiederkehrende Aufgaben.</p>';
                    
                    echo '<div class="content-section">';
                    echo '<div class="empty-state">';
                    echo '<span class="empty-state-icon">📋</span>';
                    echo '<h3>Vorlagen-System in Entwicklung</h3>';
                    echo '<p>Erstellen Sie wiederverwendbare Bewertungsvorlagen für verschiedene Projekttypen.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;

                case 'uebersicht':
                    echo '<h2 style="text-align: center; color: #002b45; margin-bottom: 30px;">📊 Übersicht</h2>';
                    echo '<p style="text-align: center; margin-bottom: 30px; color: #666;">Statistische Auswertungen und Berichte zu Ihren Bewertungen.</p>';
                    
                    echo '<div class="content-section">';
                    echo '<div class="empty-state">';
                    echo '<span class="empty-state-icon">📊</span>';
                    echo '<h3>Statistiken werden erstellt</h3>';
                    echo '<p>Nach den ersten Bewertungen werden hier detaillierte Auswertungen angezeigt.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;

                case 'einstellungen':
                    echo '<h2 style="text-align: center; color: #002b45; margin-bottom: 30px;">⚙️ Einstellungen</h2>';
                    echo '<p style="text-align: center; margin-bottom: 30px; color: #666;">Persönliche Einstellungen und Präferenzen anpassen.</p>';
                    
                    echo '<div class="content-section">';
                    echo '<div class="empty-state">';
                    echo '<span class="empty-state-icon">⚙️</span>';
                    echo '<h3>Einstellungen in Vorbereitung</h3>';
                    echo '<p>Hier können Sie bald Ihre persönlichen Präferenzen anpassen.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;

                default:
                    echo '<div class="content-section">';
                    echo '<h2>Seite nicht gefunden</h2>';
                    echo '<p>Die angeforderte Seite existiert nicht.</p>';
                    echo '</div>';
                    break;
            }
            ?>
        </div>
    </div>
</body>
</html>