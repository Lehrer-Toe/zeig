<?php
session_start();
require_once '../config.php';

// Prüfen ob Benutzer eingeloggt und Lehrer ist
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    header('Location: ../index.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];
$page = isset($_GET['page']) ? $_GET['page'] : 'news';

// Lehrerdaten und Schulinfo abrufen
$stmt = $pdo->prepare("
    SELECT t.*, s.name as school_name, s.type as school_type
    FROM teachers t
    JOIN schools s ON t.school_id = s.id
    WHERE t.id = ?
");
$stmt->execute([$teacher_id]);
$teacher = $stmt->fetch();

if (!$teacher) {
    session_destroy();
    header('Location: ../index.php');
    exit();
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
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .logout-btn:hover {
            background: #c0392b;
            transform: translateY(-1px);
        }

        .main-container {
            max-width: 1400px;
            margin: 20px auto;
            background: #2a2a2a;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.3);
            min-height: calc(100vh - 150px);
        }

        .nav-tabs {
            display: flex;
            background: #333;
            border-bottom: 1px solid #444;
            overflow-x: auto;
        }

        .nav-tab {
            padding: 15px 25px;
            background: none;
            border: none;
            color: #aaa;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
            white-space: nowrap;
            text-decoration: none;
            display: block;
            position: relative;
        }

        .nav-tab:hover {
            color: #fff;
            background: rgba(255,255,255,0.05);
        }

        .nav-tab.active {
            color: #5b67ca;
            background: #2a2a2a;
        }

        .nav-tab.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 3px;
            background: #5b67ca;
        }

        .content {
            padding: 30px;
            min-height: 500px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            border: 1px solid #2ecc71;
            color: #2ecc71;
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            border: 1px solid #e74c3c;
            color: #e74c3c;
        }

        .welcome-message {
            font-size: 18px;
            color: #bbb;
            margin-bottom: 30px;
        }

        @media (max-width: 768px) {
            .header {
                padding: 15px 20px;
            }

            .header h1 {
                font-size: 22px;
            }

            .header-content {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .nav-tabs {
                flex-wrap: nowrap;
                -webkit-overflow-scrolling: touch;
            }

            .nav-tab {
                padding: 12px 20px;
                font-size: 14px;
            }

            .content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1>Zeig, was du kannst!</h1>
            <div class="header-info">
                <div class="school-info">
                    <div class="school-name"><?= htmlspecialchars($teacher['school_name']) ?></div>
                    <div class="school-type"><?= htmlspecialchars($teacher['school_type']) ?></div>
                </div>
                <div class="user-info">
                    <span><?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?> (Lehrer)</span>
                    <a href="../logout.php" class="logout-btn">Abmelden</a>
                </div>
            </div>
        </div>
    </header>

    <div class="main-container">
        <nav class="nav-tabs">
            <a href="?page=news" class="nav-tab <?= $page === 'news' ? 'active' : '' ?>">News</a>
            <a href="?page=themen" class="nav-tab <?= $page === 'themen' ? 'active' : '' ?>">Themen</a>
            <a href="?page=gruppen" class="nav-tab <?= $page === 'gruppen' ? 'active' : '' ?>">Gruppen erstellen</a>
            <a href="?page=bewerten" class="nav-tab <?= $page === 'bewerten' ? 'active' : '' ?>">Schüler bewerten</a>
            <a href="?page=vorlagen" class="nav-tab <?= $page === 'vorlagen' ? 'active' : '' ?>">Bewertungsvorlagen</a>
            <a href="?page=uebersicht" class="nav-tab <?= $page === 'uebersicht' ? 'active' : '' ?>">Übersicht</a>
            <a href="?page=einstellungen" class="nav-tab <?= $page === 'einstellungen' ? 'active' : '' ?>">Einstellungen</a>
        </nav>

        <div class="content">
            <?php
            // Nachrichten anzeigen
            if (isset($_SESSION['success'])) {
                echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['success']) . '</div>';
                unset($_SESSION['success']);
            }
            if (isset($_SESSION['error'])) {
                echo '<div class="alert alert-error">' . htmlspecialchars($_SESSION['error']) . '</div>';
                unset($_SESSION['error']);
            }

            // Seite einbinden
            switch ($page) {
                case 'news':
                    include 'lehrer_news.php';
                    break;
                case 'themen':
                    include 'lehrer_themen.php';
                    break;
                case 'gruppen':
                    include 'lehrer_gruppen.php';
                    break;
                case 'bewerten':
                    include 'lehrer_bewerten.php';
                    break;
                case 'vorlagen':
                    include 'lehrer_vorlagen.php';
                    break;
                case 'uebersicht':
                    include 'lehrer_uebersicht.php';
                    break;
                case 'einstellungen':
                    include 'lehrer_einstellungen.php';
                    break;
                default:
                    include 'lehrer_news.php';
            }
            ?>
        </div>
    </div>
</body>
</html>