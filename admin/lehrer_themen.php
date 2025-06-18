<?php
session_start();
require_once '../php/db.php';

// Sicherheitsprüfung
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'lehrer') {
    header('Location: ../login.php');
    exit();
}

$db = getDB();
$teacher_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'];

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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: #e2e8f0;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
        }

        .header h1 {
            color: #3b82f6;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .header p {
            opacity: 0.8;
            font-size: 1.1rem;
        }

        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .filter-controls {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .toggle-group {
            display: flex;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 0.5rem;
            padding: 0.25rem;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .toggle-btn {
            padding: 0.5rem 1rem;
            background: transparent;
            border: none;
            color: #cbd5e1;
            cursor: pointer;
            border-radius: 0.25rem;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .toggle-btn.active {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
        }

        .toggle-btn:hover:not(.active) {
            background: rgba(59, 130, 246, 0.1);
        }

        .select-control {
            padding: 0.5rem 1rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 0.5rem;
            color: white;
            cursor: pointer;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-secondary {
            background: rgba(100, 116, 139, 0.2);
            color: #cbd5e1;
            border: 1px solid rgba(100, 116, 139, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(100, 116, 139, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .topics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .topic-card {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .topic-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.15);
            border-color: rgba(59, 130, 246, 0.4);
        }

        .topic-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .topic-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #3b82f6;
            margin-bottom: 0.5rem;
        }

        .topic-meta {
            font-size: 0.8rem;
            color: #94a3b8;
        }

        .topic-description {
            color: #cbd5e1;
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .topic-subjects {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .subject-tag {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            font-weight: 500;
            color: white;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
        }

        .topic-actions {
            display: flex;
            gap: 0.5rem;
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
            backdrop-filter: blur(5px);
            z-index: 1000;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: linear-gradient(135deg, #1e293b, #334155);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 1rem;
            padding: 2rem;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 1.5rem;
            color: #3b82f6;
        }

        .close-modal {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
        }

        .close-modal:hover {
            color: #ef4444;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #e2e8f0;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            color: white;
            font-size: 1rem;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.5rem;
        }

        .subject-checkbox {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(100, 116, 139, 0.2);
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .subject-checkbox:hover {
            background: rgba(0, 0, 0, 0.4);
        }

        .subject-checkbox input {
            margin-right: 0.5rem;
        }

        .subject-checkbox.checked {
            border-color: #3b82f6;
            background: rgba(59, 130, 246, 0.1);
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #94a3b8;
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        .flash-message {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
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

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
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
                padding: 1.5rem;
                margin: 1rem;
                width: calc(100% - 2rem);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📚 Themen verwalten</h1>
            <p>Erstellen und verwalten Sie Projektthemen für Ihre Schüler</p>
        </div>

        <div id="flash-messages"></div>

        <div class="controls">
            <div class="filter-controls">
                <div class="toggle-group">
                    <button class="toggle-btn active" data-filter="school">🏫 Schule</button>
                    <button class="toggle-btn" data-filter="global" disabled title="Funktion in Entwicklung">🌍 Global</button>
                </div>

                <select class="select-control" id="sortSelect">
                    <option value="alphabetic">Alphabetisch</option>
                    <option value="date">Nach Erstelldatum</option>
                    <option value="updated">Zuletzt geändert</option>
                    <option value="subject">Nach Fach</option>
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
                    <button class="btn btn-primary" onclick="openCreateModal()" style="margin-top: 1rem;">
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
                            <button class="btn btn-secondary btn-sm" onclick="editTopic(<?= $topic['id'] ?>)">
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
        let currentTopicId = null;

        // Modal Funktionen
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Neues Thema';
            document.getElementById('topicForm').reset();
            document.getElementById('topicId').value = '';
            document.getElementById('submitBtn').textContent = 'Erstellen';
            isEditing = false;
            
            // Alle Checkboxen zurücksetzen
            document.querySelectorAll('.subject-checkbox').forEach(label => {
                label.classList.remove('checked');
                label.querySelector('input').checked = false;
            });
            
            document.getElementById('topicModal').classList.add('show');
        }

        function closeModal() {
            document.getElementById('topicModal').classList.remove('show');
        }

        // Thema bearbeiten
        async function editTopic(topicId) {
            try {
                // Topic-Daten vom Server laden (vereinfacht - in der Praxis über AJAX)
                const topicCard = document.querySelector(`[data-topic-id="${topicId}"]`);
                const title = topicCard.querySelector('.topic-title').textContent;
                const description = topicCard.querySelector('.topic-description')?.textContent || '';
                
                document.getElementById('modalTitle').textContent = 'Thema bearbeiten';
                document.getElementById('topicId').value = topicId;
                document.getElementById('topicTitle').value = title;
                document.getElementById('topicDescription').value = description;
                document.getElementById('submitBtn').textContent = 'Aktualisieren';
                isEditing = true;
                currentTopicId = topicId;
                
                // Fächer auswählen (vereinfacht)
                const subjectTags = topicCard.querySelectorAll('.subject-tag');
                document.querySelectorAll('.subject-checkbox').forEach(label => {
                    label.classList.remove('checked');
                    label.querySelector('input').checked = false;
                });
                
                subjectTags.forEach(tag => {
                    const subjectName = tag.textContent.trim();
                    document.querySelectorAll('.subject-checkbox').forEach(label => {
                        const labelText = label.querySelector('span').textContent.trim();
                        if (labelText === subjectName) {
                            label.classList.add('checked');
                            label.querySelector('input').checked = true;
                        }
                    });
                });
                
                document.getElementById('topicModal').classList.add('show');
            } catch (error) {
                showFlashMessage('Fehler beim Laden der Daten: ' + error.message, 'error');
            }
        }

        // Thema löschen
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
                    // Karte entfernen
                    document.querySelector(`[data-topic-id="${topicId}"]`).remove();
                    
                    // Prüfen ob Grid leer ist
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
                
                if (isEditing) {
                    formData.append('topic_id', document.getElementById('topicId').value);
                }
                
                formData.append('title', document.getElementById('topicTitle').value);
                formData.append('description', document.getElementById('topicDescription').value);
                
                // Ausgewählte Fächer sammeln
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
                    // Seite neu laden um Änderungen anzuzeigen
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

        // Checkbox Handling
        document.querySelectorAll('.subject-checkbox').forEach(label => {
            label.addEventListener('click', function() {
                const checkbox = this.querySelector('input');
                checkbox.checked = !checkbox.checked;
                this.classList.toggle('checked', checkbox.checked);
            });
        });

        // Sort Handler
        document.getElementById('sortSelect').addEventListener('change', function() {
            const sortBy = this.value;
            const url = new URL(window.location);
            url.searchParams.set('sort', sortBy);
            window.location.href = url.toString();
        });

        // Flash Messages
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

        // Modal schließen bei Klick außerhalb
        document.getElementById('topicModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // ESC Taste
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('topicModal').classList.contains('show')) {
                closeModal();
            }
        });
    </script>
</body>
</html>