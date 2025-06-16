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
                    
                    echo '<div style="background: #3d3d3d; padding: 30px; border-radius: 12px; margin-bottom: 20px;">';
                    echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">';
                    echo '<h3 style="color: #5b67ca;">Meine Themen</h3>';
                    echo '<button style="background: #5b67ca; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer;">+ Neues Thema</button>';
                    echo '</div>';
                    echo '<div style="text-align: center; padding: 40px; color: #888;">';
                    echo '<span style="font-size: 48px;">📚</span>';
                    echo '<h3 style="margin: 20px 0;">Noch keine Themen vorhanden</h3>';
                    echo '<p>Erstellen Sie Ihr erstes Unterrichtsthema, um mit der Bewertung zu beginnen.</p>';
                    echo '</div>';
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
                    
                    echo '<div style="background: #3d3d3d; padding: 30px; border-radius: 12px; margin-bottom: 20px;">';
                    echo '<h3 style="color: #5b67ca; margin-bottom: 20px;">Bewertung durchführen</h3>';
                    echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">';
                    echo '<div>';
                    echo '<label style="display: block; margin-bottom: 8px; color: #e0e0e0;">Klasse auswählen</label>';
                    echo '<select style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #555; background: #2d2d2d; color: #e0e0e0;">';
                    echo '<option>Bitte Klasse auswählen...</option>';
                    echo '</select>';
                    echo '</div>';
                    echo '<div>';
                    echo '<label style="display: block; margin-bottom: 8px; color: #e0e0e0;">Thema auswählen</label>';
                    echo '<select style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #555; background: #2d2d2d; color: #e0e0e0;">';
                    echo '<option>Bitte Thema auswählen...</option>';
                    echo '</select>';
                    echo '</div>';
                    echo '</div>';
                    echo '<div style="text-align: center; padding: 40px; color: #888;">';
                    echo '<span style="font-size: 48px;">⭐</span>';
                    echo '<h3 style="margin: 20px 0;">Bewertung starten</h3>';
                    echo '<p>Wählen Sie eine Klasse und ein Thema aus, um mit der Bewertung zu beginnen.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;
                    
                case 'vorlagen':
                    echo '<h2>📋 Bewertungsvorlagen</h2>';
                    echo '<p style="margin-bottom: 30px; color: #b0b0b0;">Erstellen und verwalten Sie Bewertungsvorlagen mit verschiedenen Kriterien.</p>';
                    
                    echo '<div style="background: #3d3d3d; padding: 30px; border-radius: 12px; margin-bottom: 20px;">';
                    echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">';
                    echo '<h3 style="color: #5b67ca;">Meine Vorlagen</h3>';
                    echo '<button style="background: #5b67ca; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer;">+ Neue Vorlage</button>';
                    echo '</div>';
                    echo '<div style="text-align: center; padding: 40px; color: #888;">';
                    echo '<span style="font-size: 48px;">📋</span>';
                    echo '<h3 style="margin: 20px 0;">Noch keine Vorlagen vorhanden</h3>';
                    echo '<p>Erstellen Sie Bewertungsvorlagen mit verschiedenen Kriterien und Gewichtungen.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;
                    
                case 'uebersicht':
                    echo '<h2>📊 Übersicht</h2>';
                    echo '<p style="margin-bottom: 30px; color: #b0b0b0;">Verschaffen Sie sich einen Überblick über alle Bewertungen und Auswertungen.</p>';
                    
                    echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">';
                    echo '<div style="background: #3d3d3d; padding: 20px; border-radius: 12px; text-align: center;">';
                    echo '<div style="font-size: 32px; font-weight: bold; color: #5b67ca; margin-bottom: 8px;">0</div>';
                    echo '<div style="color: #b0b0b0; font-size: 14px;">Bewertungen gesamt</div>';
                    echo '</div>';
                    echo '<div style="background: #3d3d3d; padding: 20px; border-radius: 12px; text-align: center;">';
                    echo '<div style="font-size: 32px; font-weight: bold; color: #27ae60; margin-bottom: 8px;">0</div>';
                    echo '<div style="color: #b0b0b0; font-size: 14px;">Aktive Themen</div>';
                    echo '</div>';
                    echo '<div style="background: #3d3d3d; padding: 20px; border-radius: 12px; text-align: center;">';
                    echo '<div style="font-size: 32px; font-weight: bold; color: #e67e22; margin-bottom: 8px;">0</div>';
                    echo '<div style="color: #b0b0b0; font-size: 14px;">Gruppen erstellt</div>';
                    echo '</div>';
                    echo '<div style="background: #3d3d3d; padding: 20px; border-radius: 12px; text-align: center;">';
                    echo '<div style="font-size: 32px; font-weight: bold; color: #8e44ad; margin-bottom: 8px;">0</div>';
                    echo '<div style="color: #b0b0b0; font-size: 14px;">Schüler bewertet</div>';
                    echo '</div>';
                    echo '</div>';
                    
                    echo '<div style="background: #3d3d3d; padding: 30px; border-radius: 12px;">';
                    echo '<h3 style="color: #5b67ca; margin-bottom: 20px;">Letzte Aktivitäten</h3>';
                    echo '<div style="text-align: center; padding: 40px; color: #888;">';
                    echo '<span style="font-size: 48px;">📊</span>';
                    echo '<h3 style="margin: 20px 0;">Noch keine Aktivitäten</h3>';
                    echo '<p>Hier werden Ihre letzten Bewertungen und Aktivitäten angezeigt.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;
                    
                case 'einstellungen':
                    echo '<h2>⚙️ Einstellungen</h2>';
                    echo '<p style="margin-bottom: 30px; color: #b0b0b0;">Verwalten Sie Ihre persönlichen Einstellungen und Präferenzen.</p>';
                    
                    echo '<div style="background: #3d3d3d; padding: 30px; border-radius: 12px; margin-bottom: 20px;">';
                    echo '<h3 style="color: #5b67ca; margin-bottom: 20px;">Persönliche Daten</h3>';
                    echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">';
                    echo '<div>';
                    echo '<label style="display: block; margin-bottom: 8px; color: #e0e0e0;">Name</label>';
                    echo '<input type="text" value="' . htmlspecialchars($teacher['name']) . '" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #555; background: #2d2d2d; color: #e0e0e0;" readonly>';
                    echo '</div>';
                    echo '<div>';
                    echo '<label style="display: block; margin-bottom: 8px; color: #e0e0e0;">E-Mail</label>';
                    echo '<input type="email" value="' . htmlspecialchars($teacher['email']) . '" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #555; background: #2d2d2d; color: #e0e0e0;" readonly>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    
                    echo '<div style="background: #3d3d3d; padding: 30px; border-radius: 12px; margin-bottom: 20px;">';
                    echo '<h3 style="color: #5b67ca; margin-bottom: 20px;">Passwort ändern</h3>';
                    echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">';
                    echo '<div>';
                    echo '<label style="display: block; margin-bottom: 8px; color: #e0e0e0;">Neues Passwort</label>';
                    echo '<input type="password" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #555; background: #2d2d2d; color: #e0e0e0;">';
                    echo '</div>';
                    echo '<div>';
                    echo '<label style="display: block; margin-bottom: 8px; color: #e0e0e0;">Passwort bestätigen</label>';
                    echo '<input type="password" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #555; background: #2d2d2d; color: #e0e0e0;">';
                    echo '</div>';
                    echo '</div>';
                    echo '<button style="background: #5b67ca; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer;">Passwort ändern</button>';
                    echo '</div>';
                    
                    echo '<div style="background: #3d3d3d; padding: 30px; border-radius: 12px;">';
                    echo '<h3 style="color: #5b67ca; margin-bottom: 20px;">Systemeinstellungen</h3>';
                    echo '<div style="margin-bottom: 15px;">';
                    echo '<label style="display: flex; align-items: center; color: #e0e0e0; cursor: pointer;">';
                    echo '<input type="checkbox" style="margin-right: 10px;"> E-Mail-Benachrichtigungen aktivieren';
                    echo '</label>';
                    echo '</div>';
                    echo '<div style="margin-bottom: 15px;">';
                    echo '<label style="display: flex; align-items: center; color: #e0e0e0; cursor: pointer;">';
                    echo '<input type="checkbox" checked style="margin-right: 10px;"> Automatische Speicherung';
                    echo '</label>';
                    echo '</div>';
                    echo '<div style="margin-bottom: 20px;">';
                    echo '<label style="display: flex; align-items: center; color: #e0e0e0; cursor: pointer;">';
                    echo '<input type="checkbox" style="margin-right: 10px;"> Erweiterte Bewertungsoptionen anzeigen';
                    echo '</label>';
                    echo '</div>';
                    echo '<button style="background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer;">Einstellungen speichern</button>';
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