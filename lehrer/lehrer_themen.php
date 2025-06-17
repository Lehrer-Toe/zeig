<?php
require_once '../config.php';

// Lehrer-Zugriff prüfen
if (!isLoggedIn() || $_SESSION['user_type'] !== 'lehrer') {
    header('Location: ../index.php');
    exit();
}

$db = getDB();
$teacher_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'];

// Prüfen ob Themen-Tabellen existieren und erweiterte Felder vorhanden sind
try {
    $db->query("SELECT 1 FROM topics LIMIT 1");
    $db->query("SELECT 1 FROM topic_subjects LIMIT 1");
    
    // Prüfen ob neue Felder existieren
    $result = $db->query("SHOW COLUMNS FROM topics LIKE 'short_description'");
    if (!$result->fetch()) {
        throw new Exception("short_description field missing");
    }
    
    $result = $db->query("SHOW COLUMNS FROM topics LIKE 'is_global'");
    if (!$result->fetch()) {
        throw new Exception("is_global field missing");
    }
} catch (Exception $e) {
    die('
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; background: rgba(255,255,255,0.95); color: #001133; border-radius: 15px; box-shadow: 0 8px 32px rgba(0,0,0,0.2);">
        <h2 style="color: #ef4444;">⚠️ Datenbankfehler</h2>
        <p>Die Themen-Tabellen sind noch nicht vollständig erstellt oder die neuen Felder fehlen.</p>
        <p>Bitte führen Sie zuerst diese SQL-Befehle aus:</p>
        <pre style="background: #f8f9fa; padding: 15px; border-radius: 5px; overflow: auto; font-size: 12px; border: 1px solid #ddd;">
-- Falls nur die neuen Felder fehlen:
ALTER TABLE `topics` 
ADD COLUMN IF NOT EXISTS `short_description` VARCHAR(240) DEFAULT NULL COMMENT \'240 Zeichen Kurzbeschreibung\',
ADD COLUMN IF NOT EXISTS `is_global` TINYINT(1) DEFAULT 0 COMMENT \'Ob das Thema global für andere Schulen sichtbar ist\';

-- Index für bessere Performance bei globalen Themen
CREATE INDEX IF NOT EXISTS idx_topics_global ON topics(is_global, is_active);
        </pre>
        <p><a href="dashboard.php" style="color: #ff9900; text-decoration: none; font-weight: 600;">← Zurück zum Dashboard</a></p>
    </div>
    ');
}

// AJAX Requests verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => false, 'message' => ''];
    
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $response['message'] = 'Ungültiger CSRF-Token';
        echo json_encode($response);
        exit();
    }
    
    try {
        switch ($_POST['action']) {
            case 'create_topic':
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $short_description = trim($_POST['short_description'] ?? '');
                $is_global = (int)($_POST['is_global'] ?? 0);
                $subject_ids = json_decode($_POST['subject_ids'] ?? '[]', true);
                
                if (empty($title)) {
                    throw new Exception('Titel ist erforderlich.');
                }
                
                
                $db->beginTransaction();
                
                // Thema erstellen
                $stmt = $db->prepare("INSERT INTO topics (school_id, teacher_id, title, description, short_description, is_global) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$school_id, $teacher_id, $title, $description, $short_description, $is_global]);
                $topic_id = $db->lastInsertId();
                
                // Fächer zuordnen
                $stmt = $db->prepare("INSERT INTO topic_subjects (topic_id, subject_id) VALUES (?, ?)");
                foreach ($subject_ids as $subject_id) {
                    $stmt->execute([$topic_id, $subject_id]);
                }
                
                $db->commit();
                $response['success'] = true;
                $response['message'] = 'Thema erfolgreich erstellt.';
                break;
                
            case 'update_topic':
                $topic_id = (int)$_POST['topic_id'];
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $short_description = trim($_POST['short_description'] ?? '');
                $is_global = (int)($_POST['is_global'] ?? 0);
                $subject_ids = json_decode($_POST['subject_ids'] ?? '[]', true);
                
                if (empty($title)) {
                    throw new Exception('Titel ist erforderlich.');
                }
                
                
                // Prüfen ob Thema dem Lehrer gehört
                $stmt = $db->prepare("SELECT id FROM topics WHERE id = ? AND teacher_id = ? AND school_id = ?");
                $stmt->execute([$topic_id, $teacher_id, $school_id]);
                if (!$stmt->fetch()) {
                    throw new Exception('Thema nicht gefunden oder keine Berechtigung.');
                }
                
                $db->beginTransaction();
                
                // Thema aktualisieren
                $stmt = $db->prepare("UPDATE topics SET title = ?, description = ?, short_description = ?, is_global = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$title, $description, $short_description, $is_global, $topic_id]);
                
                // Alte Fächer entfernen
                $stmt = $db->prepare("DELETE FROM topic_subjects WHERE topic_id = ?");
                $stmt->execute([$topic_id]);
                
                // Neue Fächer zuordnen
                $stmt = $db->prepare("INSERT INTO topic_subjects (topic_id, subject_id) VALUES (?, ?)");
                foreach ($subject_ids as $subject_id) {
                    $stmt->execute([$topic_id, $subject_id]);
                }
                
                $db->commit();
                $response['success'] = true;
                $response['message'] = 'Thema erfolgreich aktualisiert.';
                break;
                
            case 'delete_topic':
                $topic_id = (int)$_POST['topic_id'];
                
                // Prüfen ob Thema dem Lehrer gehört
                $stmt = $db->prepare("SELECT id FROM topics WHERE id = ? AND teacher_id = ? AND school_id = ?");
                $stmt->execute([$topic_id, $teacher_id, $school_id]);
                if (!$stmt->fetch()) {
                    throw new Exception('Thema nicht gefunden oder keine Berechtigung.');
                }
                
                // Soft Delete
                $stmt = $db->prepare("UPDATE topics SET is_active = 0 WHERE id = ?");
                $stmt->execute([$topic_id]);
                
                $response['success'] = true;
                $response['message'] = 'Thema erfolgreich gelöscht.';
                break;
                
            case 'get_topic':
                $topic_id = (int)$_POST['topic_id'];
                
                // Thema mit Fächern laden
                $stmt = $db->prepare("SELECT * FROM topics WHERE id = ? AND teacher_id = ? AND school_id = ? AND is_active = 1");
                $stmt->execute([$topic_id, $teacher_id, $school_id]);
                $topic = $stmt->fetch();
                
                if (!$topic) {
                    throw new Exception('Thema nicht gefunden.');
                }
                
                // Fächer laden
                $stmt = $db->prepare("SELECT subject_id FROM topic_subjects WHERE topic_id = ?");
                $stmt->execute([$topic_id]);
                $subject_ids = [];
                while ($row = $stmt->fetch()) {
                    $subject_ids[] = $row['subject_id'];
                }
                
                $topic['subject_ids'] = array_map('strval', $subject_ids);
                
                $response['success'] = true;
                $response['topic'] = $topic;
                break;
        }
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit();
}

// Fächer abrufen
$stmt = $db->prepare("SELECT * FROM subjects WHERE school_id = ? AND is_active = 1 ORDER BY short_name");
$stmt->execute([$school_id]);
$subjects = $stmt->fetchAll();

// Themen abrufen mit Fächern
$filter = $_GET['filter'] ?? 'alphabetic';
$sort_by = $_GET['sort'] ?? 'title';

$order_clause = "ORDER BY t.title ASC";
switch ($sort_by) {
    case 'date':
        $order_clause = "ORDER BY t.created_at DESC";
        break;
    case 'updated':
        $order_clause = "ORDER BY t.updated_at DESC";
        break;
}

$stmt = $db->prepare("
    SELECT t.*, u.name as teacher_name
    FROM topics t 
    LEFT JOIN users u ON t.teacher_id = u.id
    WHERE t.school_id = ? AND t.teacher_id = ? AND t.is_active = 1
    $order_clause
");
$stmt->execute([$school_id, $teacher_id]);
$topics = $stmt->fetchAll();

// Fächer für jedes Thema laden
foreach ($topics as &$topic) {
    $stmt = $db->prepare("
        SELECT s.* 
        FROM subjects s 
        JOIN topic_subjects ts ON s.id = ts.subject_id 
        WHERE ts.topic_id = ? 
        ORDER BY s.short_name
    ");
    $stmt->execute([$topic['id']]);
    $topic['subjects'] = $stmt->fetchAll();
}
?>

<style>
    .controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        gap: 20px;
        flex-wrap: wrap;
        background: rgba(255, 255, 255, 0.9);
        padding: 20px;
        border-radius: 15px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    }

    .filter-controls {
        display: flex;
        gap: 15px;
        align-items: center;
    }

    .toggle-group {
        display: flex;
        background: rgba(0, 43, 69, 0.1);
        border-radius: 999px;
        padding: 4px;
        border: 1px solid rgba(0, 43, 69, 0.2);
    }

    .toggle-btn {
        padding: 10px 20px;
        background: transparent;
        border: none;
        color: #002b45;
        cursor: pointer;
        border-radius: 999px;
        transition: all 0.3s ease;
        font-size: 14px;
        font-weight: 500;
    }

    .toggle-btn.active {
        background: #002b45;
        color: white;
        box-shadow: 0 2px 8px rgba(0, 43, 69, 0.3);
    }

    .toggle-btn:hover:not(.active) {
        background: rgba(0, 43, 69, 0.1);
    }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 24px;
        background: linear-gradient(135deg, #ff9900, #ffad33);
        color: white;
        border: none;
        border-radius: 999px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.3s ease;
        text-decoration: none;
        box-shadow: 0 4px 12px rgba(255, 153, 0, 0.3);
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(255, 153, 0, 0.4);
        background: linear-gradient(135deg, #ffad33, #ff9900);
    }

    .btn-primary {
        background: linear-gradient(135deg, #ff9900, #ffad33);
    }

    .btn-secondary {
        background: #6c757d;
        box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
    }

    .btn-secondary:hover {
        background: #5a6268;
        box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
    }

    .topics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 25px;
        margin-bottom: 30px;
    }

    .topic-card {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        padding: 25px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .topic-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 6px;
        background: linear-gradient(90deg, #ff9900, #ffad33);
    }

    .topic-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
    }

    .topic-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }

    .topic-title {
        font-size: 20px;
        font-weight: 600;
        color: #002b45;
        margin: 0;
        flex: 1;
        word-break: break-word;
    }

    .topic-actions {
        display: flex;
        gap: 5px;
        flex-shrink: 0;
        margin-left: 15px;
    }

    .action-btn {
        background: rgba(0, 43, 69, 0.05);
        border: 1px solid rgba(0, 43, 69, 0.1);
        padding: 6px 10px;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 14px;
    }

    .action-btn:hover {
        background: rgba(0, 43, 69, 0.1);
        border-color: rgba(0, 43, 69, 0.2);
    }

    .action-btn.delete:hover {
        background: rgba(239, 68, 68, 0.1);
        color: #ef4444;
        border-color: rgba(239, 68, 68, 0.3);
    }

    .topic-meta {
        display: flex;
        flex-direction: column;
        gap: 10px;
        color: #666;
        font-size: 13px;
        margin-bottom: 15px;
    }

    .topic-subjects {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .subject-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 500;
        color: white;
        white-space: nowrap;
    }

    .topic-description {
        color: #333;
        line-height: 1.6;
        margin-top: 15px;
        font-size: 14px;
    }

    .empty-state {
        text-align: center;
        padding: 80px 20px;
        background: rgba(255, 255, 255, 0.9);
        border-radius: 20px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
    }

    .empty-state-icon {
        font-size: 72px;
        margin-bottom: 20px;
        display: block;
        opacity: 0.5;
    }

    .empty-state h3 {
        color: #002b45;
        font-size: 24px;
        margin-bottom: 10px;
    }

    .empty-state p {
        color: #666;
        font-size: 16px;
        margin-bottom: 30px;
    }

    /* Modal Styles */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(5px);
        z-index: 1000;
        overflow-y: auto;
        padding: 20px;
    }

    .modal.show {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .modal-content {
        background: white;
        border-radius: 20px;
        max-width: 800px;
        width: 100%;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        position: relative;
    }

    .modal-header {
        padding: 30px;
        border-bottom: 1px solid #eee;
        position: relative;
    }

    .modal-header h2 {
        font-size: 24px;
        color: #002b45;
        margin: 0;
    }

    .modal-close {
        position: absolute;
        top: 20px;
        right: 20px;
        font-size: 28px;
        color: #999;
        cursor: pointer;
        transition: color 0.2s;
        background: none;
        border: none;
        padding: 5px;
        line-height: 1;
    }

    .modal-close:hover {
        color: #333;
    }

    .modal-body {
        padding: 30px;
    }

    .form-group {
        margin-bottom: 25px;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #002b45;
        font-size: 14px;
    }

    .form-group input,
    .form-group textarea,
    .form-group select {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid #e5e5e5;
        border-radius: 12px;
        font-size: 15px;
        transition: border-color 0.3s;
        font-family: inherit;
    }

    .form-group input:focus,
    .form-group textarea:focus,
    .form-group select:focus {
        outline: none;
        border-color: #ff9900;
    }

    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }

    .char-counter {
        text-align: right;
        font-size: 12px;
        color: #999;
        margin-top: 5px;
    }

    .char-counter.warning {
        color: #f59e0b;
    }

    .char-counter.danger {
        color: #ef4444;
    }

    .subject-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 10px;
    }

    .subject-checkbox {
        display: flex;
        align-items: center;
        padding: 10px 15px;
        background: #f8f8f8;
        border: 2px solid #e5e5e5;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .subject-checkbox:hover {
        background: #f0f0f0;
        border-color: #ddd;
    }

    .subject-checkbox.checked {
        background: rgba(255, 153, 0, 0.1);
        border-color: #ff9900;
    }

    .subject-checkbox input {
        display: none;
    }

    .subject-checkbox .subject-color {
        width: 16px;
        height: 16px;
        border-radius: 4px;
        margin-right: 8px;
        flex-shrink: 0;
    }

    .subject-checkbox .subject-name {
        font-size: 14px;
        color: #333;
        font-weight: 500;
    }

    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .checkbox-group input[type="checkbox"] {
        width: auto;
        margin: 0;
        cursor: pointer;
    }

    .checkbox-group label {
        margin: 0;
        cursor: pointer;
        font-weight: normal;
    }

    .modal-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        margin-top: 30px;
    }

    /* List View Styles */
    .topics-list {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
    }

    .topics-list .topic-row {
        display: grid;
        grid-template-columns: 1fr 2fr 150px 120px;
        padding: 20px 25px;
        border-bottom: 1px solid #eee;
        align-items: center;
        transition: background 0.2s;
    }

    .topics-list .topic-row:hover {
        background: #f8f9fa;
    }

    .topics-list .topic-row:last-child {
        border-bottom: none;
    }

    .topics-list .topic-title {
        font-weight: 600;
        color: #002b45;
    }

    .topics-list .topic-subjects {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .topics-list .topic-date {
        color: #666;
        font-size: 14px;
    }

    .topics-list .topic-actions {
        display: flex;
        gap: 5px;
        justify-content: flex-end;
    }

    /* Flash Messages */
    .flash-message {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 25px;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        z-index: 9999;
        animation: slideIn 0.3s ease;
        max-width: 400px;
    }

    .flash-message.success {
        background: #10b981;
        color: white;
    }

    .flash-message.error {
        background: #ef4444;
        color: white;
    }

    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    /* Filter und Sort Styles */
    .filter-select {
        padding: 10px 16px;
        border: 2px solid #e5e5e5;
        border-radius: 999px;
        background: white;
        color: #002b45;
        font-size: 14px;
        cursor: pointer;
        transition: border-color 0.3s;
    }

    .filter-select:focus {
        outline: none;
        border-color: #ff9900;
    }

    .view-toggle {
        display: flex;
        gap: 5px;
        background: rgba(0, 43, 69, 0.1);
        padding: 4px;
        border-radius: 999px;
    }

    .view-btn {
        padding: 8px 12px;
        background: transparent;
        border: none;
        color: #002b45;
        cursor: pointer;
        border-radius: 999px;
        transition: all 0.3s ease;
        font-size: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .view-btn.active {
        background: #002b45;
        color: white;
    }

    .view-btn:hover:not(.active) {
        background: rgba(0, 43, 69, 0.1);
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .controls {
            flex-direction: column;
            align-items: stretch;
        }
        
        .filter-controls {
            flex-direction: column;
            width: 100%;
        }
        
        .topics-grid {
            grid-template-columns: 1fr;
        }
        
        .topics-list .topic-row {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        
        .modal-content {
            margin: 20px;
        }
    }
</style>

<div class="controls">
    <div class="filter-controls">
        <div class="toggle-group">
            <button class="toggle-btn active" data-filter="alphabetic">Alphabetisch</button>
            <button class="toggle-btn" data-filter="recent">Neueste zuerst</button>
            <button class="toggle-btn" data-filter="updated">Zuletzt bearbeitet</button>
        </div>
        
        <select class="filter-select" id="sortSelect">
            <option value="title">Nach Titel</option>
            <option value="date">Nach Erstellungsdatum</option>
            <option value="updated">Nach Aktualisierung</option>
        </select>
    </div>
    
    <div style="display: flex; gap: 15px; align-items: center;">
        <div class="view-toggle">
            <button class="view-btn active" data-view="grid" title="Kachelansicht">▦</button>
            <button class="view-btn" data-view="list" title="Listenansicht">☰</button>
        </div>
        
        <button class="btn btn-primary" onclick="openModal()">
            <span style="font-size: 18px;">+</span> Neues Thema
        </button>
    </div>
</div>

<?php if (empty($topics)): ?>
    <div class="empty-state">
        <span class="empty-state-icon">📚</span>
        <h3>Noch keine Themen erstellt</h3>
        <p>Erstellen Sie Ihr erstes Thema, um Schülergruppen dafür anzulegen.</p>
        <button class="btn btn-primary" onclick="openModal()">Erstes Thema erstellen</button>
    </div>
<?php else: ?>
    <div id="topicsContainer" class="topics-grid">
        <?php foreach ($topics as $topic): ?>
            <div class="topic-card" data-topic-id="<?= $topic['id'] ?>">
                <div class="topic-header">
                    <h3 class="topic-title"><?= htmlspecialchars($topic['title']) ?></h3>
                    <div class="topic-actions">
                        <button class="action-btn edit" onclick="editTopic(<?= $topic['id'] ?>)" title="Bearbeiten">✏️</button>
                        <button class="action-btn delete" onclick="deleteTopic(<?= $topic['id'] ?>)" title="Löschen">🗑️</button>
                    </div>
                </div>
                
                <div class="topic-meta">
                    <div>Erstellt am: <?= date('d.m.Y', strtotime($topic['created_at'])) ?></div>
                    <?php if ($topic['is_global']): ?>
                        <div>🌍 Global sichtbar</div>
                    <?php endif; ?>
                </div>
                
                <div class="topic-subjects">
                    <?php foreach ($topic['subjects'] as $subject): ?>
                        <span class="subject-badge" style="background-color: <?= htmlspecialchars($subject['color']) ?>">
                            <?= htmlspecialchars($subject['short_name']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                
                <?php if (!empty($topic['short_description'])): ?>
                    <div class="topic-description">
                        <?= htmlspecialchars($topic['short_description']) ?>
                    </div>
                <?php elseif (!empty($topic['description'])): ?>
                    <div class="topic-description">
                        <?= htmlspecialchars(substr($topic['description'], 0, 200)) ?>
                        <?= strlen($topic['description']) > 200 ? '...' : '' ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Modal für Thema erstellen/bearbeiten -->
<div id="topicModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Neues Thema erstellen</h2>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="topicForm">
                <input type="hidden" id="topicId" value="">
                
                <div class="form-group">
                    <label for="topicTitle">Titel *</label>
                    <input type="text" id="topicTitle" name="title" required>
                </div>
                
                <div class="form-group">
                    <label for="shortDescription">Kurzbeschreibung (max. 240 Zeichen)</label>
                    <textarea id="shortDescription" name="short_description" maxlength="240" rows="3"></textarea>
                    <div class="char-counter" id="charCounter">0 / 240</div>
                </div>
                
                <div class="form-group">
                    <label for="topicDescription">Ausführliche Beschreibung</label>
                    <textarea id="topicDescription" name="description" rows="5"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Fächer *</label>
                    <div class="subject-grid">
                        <?php foreach ($subjects as $subject): ?>
                            <label class="subject-checkbox">
                                <input type="checkbox" value="<?= $subject['id'] ?>">
                                <span class="subject-color" style="background-color: <?= htmlspecialchars($subject['color']) ?>"></span>
                                <span class="subject-name">
                                    <?= htmlspecialchars($subject['short_name']) ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="isGlobal" name="is_global" value="1">
                        <label for="isGlobal">🌍 Thema global für andere Schulen sichtbar machen</label>
                    </div>
                    <small style="color: #666; margin-top: 5px; display: block; font-size: 12px;">
                        Globale Themen können von anderen Schulen eingesehen werden (ohne Zugriff auf Ihre Fächer)
                    </small>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Abbrechen</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    let isEditing = false;
    let currentView = 'grid'; // Standard: Kachel-Ansicht
    const csrfToken = '<?= $_SESSION['csrf_token'] ?>';
    const ajaxUrl = window.location.href;

    // View Toggle funktionalität
    document.querySelectorAll('.view-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const view = this.dataset.view;
            switchView(view);
        });
    });

    function switchView(view) {
        currentView = view;
        
        // Button states aktualisieren
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.view === view);
        });
        
        // Container Klasse ändern
        const container = document.getElementById('topicsContainer');
        if (view === 'list') {
            container.className = 'topics-list';
            // List view HTML generieren
            renderListView();
        } else {
            container.className = 'topics-grid';
            // Zurück zur Grid view
            location.reload(); // Einfachste Lösung für Demo
        }
    }

    function renderListView() {
        // Diese Funktion würde in der Praxis die Listenansicht rendern
        // Für die Demo reicht ein Reload
    }

    // Filter Toggle
    document.querySelectorAll('.toggle-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            const filter = this.dataset.filter;
            const url = new URL(window.location);
            url.searchParams.set('filter', filter);
            
            // Sortierung basierend auf Filter setzen
            if (filter === 'recent') {
                url.searchParams.set('sort', 'date');
            } else if (filter === 'updated') {
                url.searchParams.set('sort', 'updated');
            } else {
                url.searchParams.set('sort', 'title');
            }
            
            window.location.href = url.toString();
        });
    });

    // Modal Funktionen
    function openModal() {
        isEditing = false;
        document.getElementById('modalTitle').textContent = 'Neues Thema erstellen';
        document.getElementById('topicForm').reset();
        document.getElementById('topicId').value = '';
        
        // Checkboxen zurücksetzen
        document.querySelectorAll('.subject-checkbox').forEach(label => {
            label.classList.remove('checked');
            label.querySelector('input').checked = false;
        });
        
        updateCharCounter();
        document.getElementById('topicModal').classList.add('show');
    }

    function closeModal() {
        document.getElementById('topicModal').classList.remove('show');
    }

    // Zeichenzähler für Kurzbeschreibung
    const shortDescTextarea = document.getElementById('shortDescription');
    const charCounter = document.getElementById('charCounter');

    function updateCharCounter() {
        const length = shortDescTextarea.value.length;
        charCounter.textContent = `${length} / 240`;
        
        charCounter.classList.remove('warning', 'danger');
        if (length > 200) {
            charCounter.classList.add('warning');
        }
        if (length >= 240) {
            charCounter.classList.add('danger');
        }
    }

    shortDescTextarea.addEventListener('input', updateCharCounter);

    // Flash Messages
    function showFlashMessage(message, type) {
        const flash = document.createElement('div');
        flash.className = `flash-message ${type}`;
        flash.textContent = message;
        document.body.appendChild(flash);
        
        setTimeout(() => {
            flash.remove();
        }, 3000);
    }

    // Thema bearbeiten
    async function editTopic(topicId) {
        isEditing = true;
        document.getElementById('modalTitle').textContent = 'Thema bearbeiten';
        
        try {
            const formData = new FormData();
            formData.append('action', 'get_topic');
            formData.append('topic_id', topicId);
            formData.append('csrf_token', csrfToken);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error('Server-Fehler: ' + response.status);
            }

            const result = await response.json();
            
            if (result.success) {
                const topic = result.topic;
                document.getElementById('topicId').value = topic.id;
                document.getElementById('topicTitle').value = topic.title || '';
                document.getElementById('shortDescription').value = topic.short_description || '';
                document.getElementById('topicDescription').value = topic.description || '';
                document.getElementById('isGlobal').checked = topic.is_global == 1;
                
                // Fächer setzen
                document.querySelectorAll('.subject-checkbox').forEach(label => {
                    label.classList.remove('checked');
                    const checkbox = label.querySelector('input');
                    checkbox.checked = topic.subject_ids.includes(checkbox.value);
                    if (checkbox.checked) {
                        label.classList.add('checked');
                    }
                });
                
                updateCharCounter();
                document.getElementById('topicModal').classList.add('show');
            } else {
                showFlashMessage(result.message, 'error');
            }
        } catch (error) {
            console.error('Edit Error:', error);
            showFlashMessage('Fehler beim Laden der Themendaten: ' + error.message, 'error');
        }
    }

    // Thema löschen
    async function deleteTopic(topicId) {
        if (!confirm('Sind Sie sicher, dass Sie dieses Thema löschen möchten?')) {
            return;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'delete_topic');
            formData.append('topic_id', topicId);
            formData.append('csrf_token', csrfToken);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error('Server-Fehler: ' + response.status);
            }

            const result = await response.json();
            
            if (result.success) {
                showFlashMessage(result.message, 'success');
                const topicElement = document.querySelector(`[data-topic-id="${topicId}"]`);
                if (topicElement) {
                    topicElement.remove();
                }
                
                // Prüfen ob keine Themen mehr vorhanden
                if (document.querySelectorAll('.topic-card').length === 0) {
                    setTimeout(() => location.reload(), 1000);
                }
            } else {
                showFlashMessage(result.message, 'error');
            }
        } catch (error) {
            console.error('Delete Error:', error);
            showFlashMessage('Fehler beim Löschen: ' + error.message, 'error');
        }
    }

    // Form Submit
    document.getElementById('topicForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const submitBtn = document.getElementById('submitBtn');
        const originalText = submitBtn.textContent;
        submitBtn.textContent = 'Speichern...';
        submitBtn.disabled = true;

        try {
            const formData = new FormData();
            const action = isEditing ? 'update_topic' : 'create_topic';
            formData.append('action', action);
            formData.append('csrf_token', csrfToken);
            
            if (isEditing) {
                formData.append('topic_id', document.getElementById('topicId').value);
            }
            
            formData.append('title', document.getElementById('topicTitle').value);
            formData.append('description', document.getElementById('topicDescription').value);
            formData.append('short_description', document.getElementById('shortDescription').value);
            formData.append('is_global', document.getElementById('isGlobal').checked ? '1' : '0');
            
            const selectedSubjects = [];
            document.querySelectorAll('.subject-checkbox input:checked').forEach(input => {
                selectedSubjects.push(input.value);
            });
            formData.append('subject_ids', JSON.stringify(selectedSubjects));

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error('Server-Fehler: ' + response.status);
            }

            const result = await response.json();
            
            if (result.success) {
                showFlashMessage(result.message, 'success');
                closeModal();
                setTimeout(() => location.reload(), 1000);
            } else {
                showFlashMessage(result.message, 'error');
            }
        } catch (error) {
            console.error('Submit Error:', error);
            showFlashMessage('Fehler beim Speichern: ' + error.message, 'error');
        } finally {
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }
    });

    // Subject Checkbox Styling
    document.querySelectorAll('.subject-checkbox').forEach(label => {
        label.addEventListener('click', function(e) {
            // Verhindern, dass das Event zweimal ausgelöst wird
            if (e.target.type === 'checkbox') return;
            
            const checkbox = this.querySelector('input');
            checkbox.checked = !checkbox.checked;
            this.classList.toggle('checked', checkbox.checked);
        });
    });

    // Sort Select
    document.getElementById('sortSelect').addEventListener('change', function() {
        const sortBy = this.value;
        const url = new URL(window.location);
        url.searchParams.set('sort', sortBy);
        window.location.href = url.toString();
    });

    // Modal schließen bei Klick außerhalb
    document.getElementById('topicModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
</script>
