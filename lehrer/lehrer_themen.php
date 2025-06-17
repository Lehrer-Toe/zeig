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

// Prüfen ob Themen-Tabellen existieren
try {
    $db->query("SELECT 1 FROM topics LIMIT 1");
    $db->query("SELECT 1 FROM topic_subjects LIMIT 1");
} catch (Exception $e) {
    die('
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; background: rgba(255,255,255,0.95); color: #001133; border-radius: 15px; box-shadow: 0 8px 32px rgba(0,0,0,0.2);">
        <h2 style="color: #ef4444;">⚠️ Datenbankfehler</h2>
        <p>Die Themen-Tabellen sind noch nicht erstellt.</p>
        <p>Bitte führen Sie zuerst die SQL-Befehle für die Datenbank-Erweiterung aus:</p>
        <pre style="background: #f8f9fa; padding: 15px; border-radius: 5px; overflow: auto; font-size: 12px; border: 1px solid #ddd;">
CREATE TABLE `topics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `school_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_school_id` (`school_id`),
  KEY `idx_teacher_id` (`teacher_id`),
  KEY `idx_active` (`is_active`),
  FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `topic_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `topic_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_topic_subject` (`topic_id`, `subject_id`),
  KEY `idx_topic_id` (`topic_id`),
  KEY `idx_subject_id` (`subject_id`),
  FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        </pre>
        <p><a href="dashboard.php" style="color: #ff9900; text-decoration: none; font-weight: 600;">← Zurück zum Dashboard</a></p>
    </div>
    ');
}

// AJAX Requests verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    try {
        switch ($_POST['action']) {
            case 'create_topic':
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                $subject_ids = json_decode($_POST['subject_ids'], true);
                
                if (empty($title)) {
                    throw new Exception('Titel ist erforderlich.');
                }
                
                if (empty($subject_ids)) {
                    throw new Exception('Mindestens ein Fach muss ausgewählt werden.');
                }
                
                $db->beginTransaction();
                
                // Thema erstellen
                $stmt = $db->prepare("INSERT INTO topics (school_id, teacher_id, title, description) VALUES (?, ?, ?, ?)");
                $stmt->execute([$school_id, $teacher_id, $title, $description]);
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
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                $subject_ids = json_decode($_POST['subject_ids'], true);
                
                if (empty($title)) {
                    throw new Exception('Titel ist erforderlich.');
                }
                
                if (empty($subject_ids)) {
                    throw new Exception('Mindestens ein Fach muss ausgewählt werden.');
                }
                
                // Prüfen ob Thema dem Lehrer gehört
                $stmt = $db->prepare("SELECT id FROM topics WHERE id = ? AND teacher_id = ? AND school_id = ?");
                $stmt->execute([$topic_id, $teacher_id, $school_id]);
                if (!$stmt->fetch()) {
                    throw new Exception('Thema nicht gefunden oder keine Berechtigung.');
                }
                
                $db->beginTransaction();
                
                // Thema aktualisieren
                $stmt = $db->prepare("UPDATE topics SET title = ?, description = ? WHERE id = ?");
                $stmt->execute([$title, $description, $topic_id]);
                
                // Alte Fachzuordnungen löschen
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
                
                $stmt = $db->prepare("DELETE FROM topics WHERE id = ?");
                $stmt->execute([$topic_id]);
                
                $response['success'] = true;
                $response['message'] = 'Thema erfolgreich gelöscht.';
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

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Themen verwalten - Lehrer Dashboard</title>
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

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 40px 40px;
        }

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

        .toggle-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .select-control {
            padding: 10px 15px;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(0, 43, 69, 0.2);
            border-radius: 10px;
            color: #002b45;
            cursor: pointer;
            font-weight: 500;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 999px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #ffffff;
            color: #001133;
            border: 2px solid #ff9900;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .btn-primary:hover {
            background: #ff9900;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 153, 0, 0.3);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.8);
            color: #002b45;
            border: 1px solid rgba(0, 43, 69, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.95);
            transform: translateY(-1px);
        }

        .btn-danger {
            background: rgba(231, 76, 60, 0.9);
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 12px;
        }

        .topics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .topic-card {
            background: rgba(255, 255, 255, 0.95);
            border: 2px solid transparent;
            border-radius: 15px;
            padding: 25px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }

        .topic-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
            border-color: #ff9900;
        }

        .topic-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .topic-title {
            font-size: 20px;
            font-weight: 700;
            color: #002b45;
            margin-bottom: 8px;
        }

        .topic-meta {
            font-size: 12px;
            color: #666;
        }

        .topic-description {
            color: #555;
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .topic-subjects {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 15px;
        }

        .subject-tag {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: white;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
        }

        .topic-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: rgba(255, 255, 255, 0.98);
            border: 2px solid #ff9900;
            border-radius: 20px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            backdrop-filter: blur(10px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .modal-title {
            font-size: 24px;
            color: #002b45;
            font-weight: 700;
        }

        .close-modal {
            background: none;
            border: none;
            color: #666;
            font-size: 28px;
            cursor: pointer;
            padding: 5px;
            border-radius: 50%;
            transition: all 0.2s;
        }

        .close-modal:hover {
            color: #e74c3c;
            background: rgba(231, 76, 60, 0.1);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #002b45;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid rgba(0, 43, 69, 0.2);
            border-radius: 10px;
            color: #002b45;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: #ff9900;
            box-shadow: 0 0 0 3px rgba(255, 153, 0, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
        }

        .subject-checkbox {
            display: flex;
            align-items: center;
            padding: 12px;
            background: rgba(255, 255, 255, 0.8);
            border: 2px solid rgba(0, 43, 69, 0.2);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .subject-checkbox:hover {
            background: rgba(255, 255, 255, 0.95);
            border-color: #ff9900;
        }

        .subject-checkbox input {
            margin-right: 8px;
        }

        .subject-checkbox.checked {
            border-color: #ff9900;
            background: rgba(255, 153, 0, 0.1);
        }

        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: #666;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 15px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        }

        .empty-state-icon {
            font-size: 72px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: #002b45;
            margin: 20px 0;
        }

        .flash-message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .flash-success {
            background: rgba(34, 197, 94, 0.1);
            border: 2px solid rgba(34, 197, 94, 0.3);
            color: #15803d;
        }

        .flash-error {
            background: rgba(231, 76, 60, 0.1);
            border: 2px solid rgba(231, 76, 60, 0.3);
            color: #dc2626;
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }

            .controls {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-controls {
                justify-content: space-between;
            }

            .topics-grid {
                grid-template-columns: 1fr;
            }

            .modal-content {
                padding: 20px;
                margin: 20px;
                width: calc(100% - 40px);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div id="flash-messages"></div>

        <div class="controls">
            <div class="filter-controls">
                <div class="toggle-group">
                    <button class="toggle-btn active" data-filter="school">🏫 Schule</button>
                    <button class="toggle-btn" data-filter="global" disabled title="Funktion in Entwicklung">🌍 Global</button>
                </div>

                <select class="select-control" id="sortSelect">
                    <option value="alphabetic" <?= isset($_GET['sort']) && $_GET['sort'] === 'alphabetic' ? 'selected' : '' ?>>Alphabetisch</option>
                    <option value="date" <?= isset($_GET['sort']) && $_GET['sort'] === 'date' ? 'selected' : '' ?>>Nach Erstelldatum</option>
                    <option value="updated" <?= isset($_GET['sort']) && $_GET['sort'] === 'updated' ? 'selected' : '' ?>>Zuletzt geändert</option>
                </select>
            </div>

            <button class="btn btn-primary" onclick="openCreateModal()">
                ➕ Neues Thema
            </button>
        </div>

        <div class="topics-grid" id="topicsGrid">
            <?php if (empty($topics)): ?>
                <div class="empty-state" style="grid-column: 1 / -1;">
                    <div class="empty-state-icon">📚</div>
                    <h3>Noch keine Themen vorhanden</h3>
                    <p>Erstellen Sie Ihr erstes Projektthema für die Schüler.</p>
                    <button class="btn btn-primary" onclick="openCreateModal()" style="margin-top: 20px;">
                        ➕ Erstes Thema erstellen
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($topics as $topic): ?>
                    <div class="topic-card" data-topic-id="<?= $topic['id'] ?>">
                        <div class="topic-header">
                            <div>
                                <div class="topic-title"><?= htmlspecialchars($topic['title']) ?></div>
                                <div class="topic-meta">
                                    Erstellt: <?= date('d.m.Y', strtotime($topic['created_at'])) ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($topic['description'])): ?>
                            <div class="topic-description">
                                <?= nl2br(htmlspecialchars($topic['description'])) ?>
                            </div>
                        <?php endif; ?>

                        <div class="topic-subjects">
                            <?php foreach ($topic['subjects'] as $subject): ?>
                                <span class="subject-tag" style="background-color: <?= htmlspecialchars($subject['color']) ?>">
                                    <?= htmlspecialchars($subject['short_name']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>

                        <div class="topic-actions">
                            <button class="btn btn-secondary btn-sm" onclick="editTopic(<?= $topic['id'] ?>, '<?= htmlspecialchars(addslashes($topic['title'])) ?>', '<?= htmlspecialchars(addslashes($topic['description'])) ?>', <?= json_encode($topic['subjects']) ?>)">
                                ✏️ Bearbeiten
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="deleteTopic(<?= $topic['id'] ?>)">
                                🗑️ Löschen
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal für Thema erstellen/bearbeiten -->
    <div class="modal" id="topicModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Neues Thema</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>

            <form id="topicForm">
                <input type="hidden" id="topicId" name="topic_id">

                <div class="form-group">
                    <label class="form-label" for="topicTitle">Titel *</label>
                    <input type="text" class="form-control" id="topicTitle" name="title" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="topicDescription">Beschreibung</label>
                    <textarea class="form-control" id="topicDescription" name="description" rows="4"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Fächer auswählen *</label>
                    <div class="subjects-grid" id="subjectsGrid">
                        <?php foreach ($subjects as $subject): ?>
                            <label class="subject-checkbox">
                                <input type="checkbox" name="subjects[]" value="<?= $subject['id'] ?>">
                                <span style="color: <?= htmlspecialchars($subject['color']) ?>">
                                    <?= htmlspecialchars($subject['short_name']) ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Abbrechen</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Speichern</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let isEditing = false;

        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Neues Thema';
            document.getElementById('topicForm').reset();
            document.getElementById('topicId').value = '';
            document.getElementById('submitBtn').textContent = 'Erstellen';
            isEditing = false;
            
            document.querySelectorAll('.subject-checkbox').forEach(label => {
                label.classList.remove('checked');
                label.querySelector('input').checked = false;
            });
            
            document.getElementById('topicModal').classList.add('show');
        }

        function closeModal() {
            document.getElementById('topicModal').classList.remove('show');
        }

        function editTopic(topicId, title, description, subjects) {
            document.getElementById('modalTitle').textContent = 'Thema bearbeiten';
            document.getElementById('topicId').value = topicId;
            document.getElementById('topicTitle').value = title;
            document.getElementById('topicDescription').value = description;
            document.getElementById('submitBtn').textContent = 'Aktualisieren';
            isEditing = true;
            
            document.querySelectorAll('.subject-checkbox').forEach(label => {
                label.classList.remove('checked');
                label.querySelector('input').checked = false;
            });
            
            subjects.forEach(subject => {
                const checkbox = document.querySelector(`input[value="${subject.id}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                    checkbox.closest('.subject-checkbox').classList.add('checked');
                }
            });
            
            document.getElementById('topicModal').classList.add('show');
        }

        async function deleteTopic(topicId) {
            if (!confirm('Sind Sie sicher, dass Sie dieses Thema löschen möchten?')) {
                return;
            }

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=delete_topic&topic_id=${topicId}`
                });

                const result = await response.json();
                
                if (result.success) {
                    showFlashMessage(result.message, 'success');
                    document.querySelector(`[data-topic-id="${topicId}"]`).remove();
                    
                    if (document.querySelectorAll('.topic-card').length === 0) {
                        location.reload();
                    }
                } else {
                    showFlashMessage(result.message, 'error');
                }
            } catch (error) {
                showFlashMessage('Fehler beim Löschen: ' + error.message, 'error');
            }
        }

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
                
                if (isEditing) {
                    formData.append('topic_id', document.getElementById('topicId').value);
                }
                
                formData.append('title', document.getElementById('topicTitle').value);
                formData.append('description', document.getElementById('topicDescription').value);
                
                const selectedSubjects = [];
                document.querySelectorAll('.subject-checkbox input:checked').forEach(input => {
                    selectedSubjects.push(input.value);
                });
                formData.append('subject_ids', JSON.stringify(selectedSubjects));

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.success) {
                    showFlashMessage(result.message, 'success');
                    closeModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showFlashMessage(result.message, 'error');
                }
            } catch (error) {
                showFlashMessage('Fehler beim Speichern: ' + error.message, 'error');
            } finally {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            }
        });

        document.querySelectorAll('.subject-checkbox').forEach(label => {
            label.addEventListener('click', function() {
                const checkbox = this.querySelector('input');
                checkbox.checked = !checkbox.checked;
                this.classList.toggle('checked', checkbox.checked);
            });
        });

        document.getElementById('sortSelect').addEventListener('change', function() {
            const sortBy = this.value;
            const url = new URL(window.location);
            url.searchParams.set('sort', sortBy);
            window.location.href = url.toString();
        });

        function showFlashMessage(message, type) {
            const flashContainer = document.getElementById('flash-messages');
            const flash = document.createElement('div');
            flash.className = `flash-message flash-${type}`;
            flash.textContent = message;
            
            flashContainer.appendChild(flash);
            
            setTimeout(() => {
                flash.remove();
            }, 5000);
        }

        document.getElementById('topicModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('topicModal').classList.contains('show')) {
                closeModal();
            }
        });
    </script>
</body>
</html>