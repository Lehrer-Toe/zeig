<?php
session_start();

// Angepasste Pfade für deine Struktur
require_once '../config.php';

// Überprüfe Admin-Rechte
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'schuladmin') {
    header('Location: ../index.php');
    exit();
}

// Datenbankverbindung holen
$pdo = getDB();
$admin_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'];
$message = '';
$error = '';

// Nachricht erstellen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_news'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $is_important = isset($_POST['is_important']) ? 1 : 0;
    $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
    
    if (!empty($title) && !empty($content)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO admin_news (title, content, created_by, expires_at, is_important)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $content, $admin_id, $expires_at, $is_important]);
            $message = "Nachricht erfolgreich erstellt!";
        } catch (PDOException $e) {
            $error = "Fehler beim Erstellen der Nachricht: " . $e->getMessage();
        }
    } else {
        $error = "Bitte füllen Sie alle Pflichtfelder aus.";
    }
}

// Nachricht löschen
if (isset($_POST['delete_news']) && isset($_POST['news_id'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM admin_news WHERE id = ?");
        $stmt->execute([$_POST['news_id']]);
        $message = "Nachricht erfolgreich gelöscht!";
    } catch (PDOException $e) {
        $error = "Fehler beim Löschen der Nachricht: " . $e->getMessage();
    }
}

// Alle Nachrichten abrufen (nur für diese Schule)
try {
    $stmt = $pdo->prepare("
        SELECT n.*, 
               COUNT(DISTINCT r.teacher_id) as read_count,
               (SELECT COUNT(DISTINCT id) FROM users WHERE user_type = 'lehrer' AND school_id = ?) as total_teachers
        FROM admin_news n
        LEFT JOIN news_read_status r ON n.id = r.news_id
        LEFT JOIN users u ON n.created_by = u.id
        WHERE u.school_id = ?
        AND (n.expires_at IS NULL OR n.expires_at >= CURDATE())
        GROUP BY n.id
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$school_id, $school_id]);
    $news_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Fehler beim Abrufen der Nachrichten: " . $e->getMessage();
    $news_list = [];
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin News - Zeig, was du kannst!</title>
    <link rel="stylesheet" href="../css/admin_styles.css">
    <style>
        /* Sunset Theme Anpassungen */
        body {
            background-color: #0a0f1b;
            color: #e0e0e0;
            font-family: 'Arial', sans-serif;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        h1 {
            color: #f4a460;
            margin-bottom: 30px;
            font-size: 28px;
        }
        
        h2 {
            color: #8fb1d9;
            font-size: 22px;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: 500;
        }
        
        .alert-success {
            background-color: #1b4332;
            color: #95d5b2;
            border: 1px solid #2d6a4f;
        }
        
        .alert-error {
            background-color: #5c1f1f;
            color: #f8b4b4;
            border: 1px solid #8b3333;
        }
        
        .form-section {
            background-color: #1a2332;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
            border: 1px solid #2d3f55;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #b8d4e8;
            font-weight: 500;
        }
        
        input[type="text"],
        textarea,
        input[type="date"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #3a4f65;
            background-color: #0f1824;
            color: #e0e0e0;
            border-radius: 4px;
            font-size: 14px;
        }
        
        input[type="text"]:focus,
        textarea:focus,
        input[type="date"]:focus {
            outline: none;
            border-color: #5a8fc0;
            background-color: #1a2532;
        }
        
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background-color: #3b7ea8;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #4a95c9;
            transform: translateY(-1px);
        }
        
        .btn-danger {
            background-color: #d32f2f;
            color: white;
            padding: 5px 15px;
            font-size: 13px;
        }
        
        .btn-danger:hover {
            background-color: #e53935;
        }
        
        .news-list {
            background-color: #1a2332;
            padding: 25px;
            border-radius: 8px;
            border: 1px solid #2d3f55;
        }
        
        .news-item {
            background-color: #243447;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 6px;
            border: 1px solid #35495e;
        }
        
        .news-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        
        .news-title {
            font-size: 18px;
            color: #f4a460;
            margin: 0 0 5px 0;
        }
        
        .news-meta {
            font-size: 13px;
            color: #8fb1d9;
        }
        
        .news-content {
            color: #e0e0e0;
            line-height: 1.6;
            margin-bottom: 15px;
            padding: 10px;
            background-color: #1a2332;
            border-radius: 4px;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            font-size: 11px;
            border-radius: 3px;
            margin-left: 10px;
            font-weight: normal;
        }
        
        .badge-important {
            background-color: #d32f2f;
            color: white;
        }
        
        .badge-expires {
            background-color: #f57c00;
            color: white;
        }
        
        .news-stats {
            background-color: #1a2332;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #8fb1d9;
        }
        
        /* Zurück-Link Styling */
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #5a8fc0;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .back-link:hover {
            color: #7db3e0;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">🏠 zurück zum Dashboardd</a>
        
        <h1>Admin News verwalten</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="form-section">
            <h2>Neue Nachricht erstellen</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="title">Titel *</label>
                    <input type="text" id="title" name="title" required>
                </div>
                
                <div class="form-group">
                    <label for="content">Inhalt *</label>
                    <textarea id="content" name="content" required></textarea>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="is_important" name="is_important" value="1">
                        <label for="is_important" style="margin-bottom: 0;">Als wichtig markieren</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="expires_at">Zeitbegrenzt bis (optional)</label>
                    <input type="date" id="expires_at" name="expires_at" min="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <button type="submit" name="create_news" class="btn btn-primary">Nachricht veröffentlichen</button>
            </form>
        </div>
        
        <div class="news-list">
            <h2 style="margin-bottom: 20px;">Aktuelle Nachrichten</h2>
            
            <?php if (empty($news_list)): ?>
                <p style="color: #666;">Keine Nachrichten vorhanden.</p>
            <?php else: ?>
                <?php foreach ($news_list as $news): ?>
                    <div class="news-item">
                        <div class="news-header">
                            <div>
                                <h3 class="news-title">
                                    <?php echo htmlspecialchars($news['title']); ?>
                                    <?php if ($news['is_important']): ?>
                                        <span class="badge badge-important">Wichtig</span>
                                    <?php endif; ?>
                                    <?php if ($news['expires_at']): ?>
                                        <span class="badge badge-expires">Läuft ab: <?php echo date('d.m.Y', strtotime($news['expires_at'])); ?></span>
                                    <?php endif; ?>
                                </h3>
                                <p class="news-meta">Erstellt am <?php echo date('d.m.Y H:i', strtotime($news['created_at'])); ?></p>
                            </div>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Möchten Sie diese Nachricht wirklich löschen?');">
                                <input type="hidden" name="news_id" value="<?php echo $news['id']; ?>">
                                <button type="submit" name="delete_news" class="btn btn-danger">Löschen</button>
                            </form>
                        </div>
                        
                        <div class="news-content"><?php echo htmlspecialchars($news['content']); ?></div>
                        
                        <div class="news-stats">
                            <div class="stat-item">
                                <span>📖</span>
                                <span><?php echo $news['read_count']; ?> von <?php echo $news['total_teachers']; ?> Lehrern haben gelesen</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>