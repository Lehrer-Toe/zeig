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

// Flash-Messages verarbeiten
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>News-Verwaltung - Admin</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #0a0a0a;
            color: #e0e0e0;
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background-color: #1a1a1a;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 1px solid #333;
        }
        
        h1 {
            color: #fff;
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #888;
            font-size: 1.1em;
        }
        
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: fadeIn 0.3s ease-in;
        }
        
        .success {
            background-color: #1a3a1a;
            color: #4ade80;
            border: 1px solid #22c55e;
        }
        
        .error {
            background-color: #3a1a1a;
            color: #f87171;
            border: 1px solid #ef4444;
        }
        
        .news-form {
            background-color: #1a1a1a;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 1px solid #333;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            color: #a0a0a0;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        input[type="text"],
        input[type="date"],
        textarea {
            width: 100%;
            padding: 12px;
            background-color: #0a0a0a;
            border: 1px solid #333;
            border-radius: 6px;
            color: #e0e0e0;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }
        
        input[type="text"]:focus,
        input[type="date"]:focus,
        textarea:focus {
            outline: none;
            border-color: #4a9eff;
        }
        
        textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        .btn-primary {
            background-color: #4a9eff;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #357abd;
            transform: translateY(-1px);
        }
        
        .btn-danger {
            background-color: #ef4444;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #dc2626;
        }
        
        .news-list {
            background-color: #1a1a1a;
            padding: 30px;
            border-radius: 10px;
            border: 1px solid #333;
        }
        
        .news-item {
            background-color: #0a0a0a;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #222;
            transition: border-color 0.3s ease;
        }
        
        .news-item:hover {
            border-color: #444;
        }
        
        .news-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
        }
        
        .news-title {
            font-size: 1.3em;
            color: #fff;
            margin-bottom: 5px;
        }
        
        .news-meta {
            color: #666;
            font-size: 0.9em;
        }
        
        .news-content {
            color: #ccc;
            margin: 15px 0;
            white-space: pre-wrap;
        }
        
        .news-stats {
            display: flex;
            gap: 20px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #333;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #888;
            font-size: 0.9em;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 500;
        }
        
        .badge-important {
            background-color: #991b1b;
            color: #fca5a5;
        }
        
        .badge-expires {
            background-color: #1e3a8a;
            color: #93bbfc;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .back-link {
            display: inline-block;
            color: #4a9eff;
            text-decoration: none;
            margin-bottom: 20px;
            padding: 8px 16px;
            border: 1px solid #4a9eff;
            border-radius: 6px;
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            background-color: #4a9eff;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Zurück zum Dashboard</a>
        
        <div class="header">
            <h1>News-Verwaltung</h1>
            <p class="subtitle">Erstellen und verwalten Sie Nachrichten für alle Lehrer</p>
        </div>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="news-form">
            <h2 style="margin-bottom: 20px;">Neue Nachricht erstellen</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="title">Titel der Nachricht</label>
                    <input type="text" id="title" name="title" required>
                </div>
                
                <div class="form-group">
                    <label for="content">Nachrichtentext</label>
                    <textarea id="content" name="content" required></textarea>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="is_important" name="is_important">
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