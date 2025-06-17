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
-- Falls Tabellen nicht existieren:
CREATE TABLE IF NOT EXISTS `topics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `school_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `short_description` varchar(240) DEFAULT NULL,
  `is_global` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_school_id` (`school_id`),
  KEY `idx_teacher_id` (`teacher_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_global` (`is_global`),
  FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

// CSRF-Token generieren falls nicht vorhanden
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fächer abrufen
$stmt = $db->prepare("SELECT * FROM subjects WHERE school_id = ? AND is_active = 1 ORDER BY short_name");
$stmt->execute([$school_id]);
$subjects = $stmt->fetchAll();

// Filter für globale/lokale Ansicht
$filter = $_GET['filter'] ?? 'school';
$sort_by = $_GET['sort'] ?? 'alphabetic';

$order_clause = "ORDER BY t.title ASC";
switch ($sort_by) {
    case 'date':
        $order_clause = "ORDER BY t.created_at DESC";
        break;
    case 'updated':
        $order_clause = "ORDER BY t.updated_at DESC";
        break;
}

// Themen abrufen basierend auf Filter
if ($filter === 'global') {
    // Globale Ansicht: Alle globalen Themen anzeigen (auch eigene)
    $stmt = $db->prepare("
        SELECT t.id, t.school_id, t.teacher_id, t.title, t.description, 
               t.short_description, t.is_global, t.is_active, t.created_at, t.updated_at,
               u.name as teacher_name, s.name as school_name
        FROM topics t 
        LEFT JOIN users u ON t.teacher_id = u.id
        LEFT JOIN schools s ON t.school_id = s.id
        WHERE t.is_global = 1 AND t.is_active = 1
        $order_clause
    ");
    $stmt->execute();
} else {
    // Lokale Ansicht: Nur eigene Themen (unabhängig ob global oder nicht)
    $stmt = $db->prepare("
        SELECT t.id, t.school_id, t.teacher_id, t.title, t.description, 
               t.short_description, t.is_global, t.is_active, t.created_at, t.updated_at,
               u.name as teacher_name
        FROM topics t 
        LEFT JOIN users u ON t.teacher_id = u.id
        WHERE t.school_id = ? AND t.teacher_id = ? AND t.is_active = 1
        $order_clause
    ");
    $stmt->execute([$school_id, $teacher_id]);
}
$topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fächer für jedes Thema laden
$topicIds = array_column($topics, 'id');
if (!empty($topicIds)) {
    $placeholders = str_repeat('?,', count($topicIds) - 1) . '?';
    $stmt = $db->prepare("
        SELECT ts.topic_id, s.* 
        FROM subjects s 
        JOIN topic_subjects ts ON s.id = ts.subject_id 
        WHERE ts.topic_id IN ($placeholders)
        ORDER BY s.short_name
    ");
    $stmt->execute($topicIds);
    $allSubjects = $stmt->fetchAll();
    
    // Subjects den Topics zuordnen
    $subjectsByTopic = [];
    foreach ($allSubjects as $subject) {
        $topicId = $subject['topic_id'];
        if (!isset($subjectsByTopic[$topicId])) {
            $subjectsByTopic[$topicId] = [];
        }
        $subjectsByTopic[$topicId][] = $subject;
    }
    
    // Topics mit Subjects anreichern
    foreach ($topics as &$topic) {
        $topic['subjects'] = $subjectsByTopic[$topic['id']] ?? [];
    }
} else {
    foreach ($topics as &$topic) {
        $topic['subjects'] = [];
    }
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

        /* View Toggle für Listen-/Kachelansicht */
        .view-toggle {
            display: flex;
            background: rgba(255, 153, 0, 0.1);
            border-radius: 999px;
            padding: 4px;
            border: 1px solid rgba(255, 153, 0, 0.3);
        }

        .view-btn {
            padding: 8px 16px;
            background: transparent;
            border: none;
            color: #ff9900;
            cursor: pointer;
            border-radius: 999px;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
        }

        .view-btn.active {
            background: #ff9900;
            color: white;
            box-shadow: 0 2px 8px rgba(255, 153, 0, 0.3);
        }

        .view-btn:hover:not(.active) {
            background: rgba(255, 153, 0, 0.1);
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

        /* Kachel-Ansicht (Standard) */
        .topics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        /* Listen-Ansicht */
        .topics-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 30px;
        }

        .topics-list .topic-card {
            display: flex;
            align-items: center;
            padding: 20px;
            min-height: auto;
        }

        .topics-list .topic-content {
            flex: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .topics-list .topic-info {
            flex: 1;
        }

        .topics-list .topic-title {
            font-size: 18px;
            margin-bottom: 5px;
        }

        .topics-list .topic-description {
            font-size: 14px;
            max-width: 400px;
            margin-bottom: 8px;
        }

        .topics-list .topic-subjects {
            margin-bottom: 0;
        }

        .topics-list .topic-meta {
            text-align: right;
            margin-right: 20px;
            font-size: 12px;
            color: #666;
        }

        .topics-list .topic-actions {
            flex-shrink: 0;
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

        .topic-global {
            background: #17a2b8;
            color: white;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }

        .topic-description {
            color: #555;
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .topic-short-description {
            color: #666;
            font-style: italic;
            font-size: 14px;
            margin-bottom: 10px;
            line-height: 1.4;
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
            max-width: 700px;
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
            min-height: 80px;
        }

        .char-counter {
            text-align: right;
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .char-counter.warning {
            color: #ff9900;
        }

        .char-counter.error {
            color: #e74c3c;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            padding: 12px;
            background: rgba(255, 153, 0, 0.05);
            border: 1px solid rgba(255, 153, 0, 0.2);
            border-radius: 8px;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin: 0;
        }

        .checkbox-group label {
            margin: 0;
            cursor: pointer;
            user-select: none;
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
                flex-wrap: wrap;
            }

            .topics-grid {
                grid-template-columns: 1fr;
            }

            .topics-list .topic-card {
                flex-direction: column;
                align-items: stretch;
            }

            .topics-list .topic-content {
                flex-direction: column;
                gap: 15px;
            }

            .topics-list .topic-meta {
                text-align: left;
                margin-right: 0;
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
                    <button class="toggle-btn <?= $filter === 'school' ? 'active' : '' ?>" data-filter="school" onclick="changeFilter('school')">🏫 Schule</button>
                    <button class="toggle-btn <?= $filter === 'global' ? 'active' : '' ?>" data-filter="global" onclick="changeFilter('global')">🌍 Global</button>
                </div>

                <div class="view-toggle">
                    <button class="view-btn" data-view="list">📋 Liste</button>
                    <button class="view-btn active" data-view="grid">🎯 Kacheln</button>
                </div>

                <select class="select-control" id="sortSelect">
                    <option value="alphabetic" <?= $sort_by === 'alphabetic' ? 'selected' : '' ?>>Alphabetisch</option>
                    <option value="date" <?= $sort_by === 'date' ? 'selected' : '' ?>>Nach Erstelldatum</option>
                    <option value="updated" <?= $sort_by === 'updated' ? 'selected' : '' ?>>Zuletzt geändert</option>
                </select>
            </div>

            <?php if ($filter === 'school'): ?>
                <button class="btn btn-primary" onclick="openCreateModal()">
                    ➕ Neues Thema
                </button>
            <?php endif; ?>
        </div>

        <div class="topics-grid" id="topicsContainer">
            <?php if (empty($topics)): ?>
                <div class="empty-state" style="grid-column: 1 / -1;">
                    <div class="empty-state-icon">📚</div>
                    <h3><?= $filter === 'global' ? 'Keine globalen Themen gefunden' : 'Noch keine Themen vorhanden' ?></h3>
                    <p><?= $filter === 'global' ? 'Es sind noch keine globalen Themen von Lehrern verfügbar.' : 'Erstellen Sie Ihr erstes Projektthema für die Schüler.' ?></p>
                    <?php if ($filter === 'school'): ?>
                        <button class="btn btn-primary" onclick="openCreateModal()" style="margin-top: 20px;">
                            ➕ Erstes Thema erstellen
                        </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($topics as $topic): ?>
                    <div class="topic-card" data-topic-id="<?= $topic['id'] ?>">
                        <div class="topic-content">
                            <div class="topic-info">
                                <div class="topic-header">
                                    <div>
                                        <div class="topic-title">
                                            <?= htmlspecialchars($topic['title']) ?>
                                            <?php if ($topic['is_global']): ?>
                                                <span class="topic-global">🌍 Global</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="topic-meta">
                                            <?php if ($filter === 'global' && isset($topic['school_name'])): ?>
                                                🏫 <?= htmlspecialchars($topic['school_name']) ?><br>
                                            <?php endif; ?>
                                            <?php if ($filter === 'global' && $topic['teacher_id'] != $teacher_id): ?>
                                                👤 <?= htmlspecialchars($topic['teacher_name']) ?><br>
                                            <?php endif; ?>
                                            Erstellt: <?= date('d.m.Y', strtotime($topic['created_at'])) ?>
                                            <?php if ($topic['updated_at'] !== $topic['created_at']): ?>
                                                | Geändert: <?= date('d.m.Y', strtotime($topic['updated_at'])) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!empty($topic['short_description'])): ?>
                                    <div class="topic-short-description">
                                        <?= htmlspecialchars($topic['short_description']) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="topic-subjects">
                                    <?php foreach ($topic['subjects'] as $subject): ?>
                                        <span class="subject-tag" style="background-color: <?= htmlspecialchars($subject['color']) ?>">
                                            <?= htmlspecialchars($subject['short_name']) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <?php 
                        // Aktions-Buttons nur für eigene Themen anzeigen
                        $isOwnTopic = ($topic['teacher_id'] == $teacher_id);
                        if ($isOwnTopic): 
                        ?>
                            <div class="topic-actions">
                                <button class="btn btn-secondary btn-sm" onclick="editTopic(<?= $topic['id'] ?>)">
                                    ✏️ Bearbeiten
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="deleteTopic(<?= $topic['id'] ?>)">
                                    🗑️ Löschen
                                </button>
                            </div>
                        <?php endif; ?>
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
                <input type="hidden" id="topicId" name="topic_id" value="">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label class="form-label" for="topicTitle">Titel *</label>
                    <input type="text" class="form-control" id="topicTitle" name="title" required maxlength="255">
                </div>

                <div class="form-group">
                    <label class="form-label" for="shortDescription">Kurzbeschreibung (240 Zeichen)</label>
                    <textarea class="form-control" id="shortDescription" name="short_description" maxlength="240" rows="4" oninput="updateCharCounter()"></textarea>
                    <div id="charCounter" class="char-counter">0 / 240</div>
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

    <script>
        (function() {
            'use strict';
            
            // Globale Variablen für diesen Scope
            let currentView = 'grid';
            
            // AJAX URL definieren - abhängig vom Kontext
            function getAjaxUrl() {
                const path = window.location.pathname;
                if (path.includes('dashboard.php')) {
                    return 'dashboard.php';
                }
                return window.location.pathname;
            }
            
            // Funktionen im globalen Scope verfügbar machen
            window.switchView = function(view) {
                currentView = view;
                document.querySelectorAll('.view-btn').forEach(btn => {
                    btn.classList.toggle('active', btn.dataset.view === view);
                });
                const container = document.getElementById('topicsContainer');
                container.className = view === 'list' ? 'topics-list' : 'topics-grid';
            }
            
            window.changeFilter = function(filter) {
                const searchParams = new URLSearchParams(window.location.search);
                searchParams.set('filter', filter);
                if (window.location.pathname.includes('dashboard.php')) {
                    searchParams.set('page', 'themen');
                }
                window.location.href = window.location.pathname + '?' + searchParams.toString();
            }
            
            window.updateCharCounter = function() {
                const textarea = document.getElementById('shortDescription');
                const counter = document.getElementById('charCounter');
                if (textarea && counter) {
                    const length = textarea.value.length;
                    counter.textContent = length + ' / 240';
                    counter.className = 'char-counter';
                    if (length > 200) counter.classList.add('warning');
                    if (length > 240) counter.classList.add('error');
                }
            }
            
            window.openCreateModal = function() {
                console.log('Opening CREATE modal');
                document.getElementById('modalTitle').textContent = 'Neues Thema';
                
                // Form komplett zurücksetzen
                const form = document.getElementById('topicForm');
                form.reset();
                
                // Explizit alle Felder leeren - WICHTIG: value="" setzen
                const topicIdField = document.getElementById('topicId');
                topicIdField.value = '';
                topicIdField.removeAttribute('value'); // Auch das HTML-Attribut entfernen
                
                document.getElementById('topicTitle').value = '';
                document.getElementById('shortDescription').value = '';
                document.getElementById('isGlobal').checked = false;
                document.getElementById('submitBtn').textContent = 'Erstellen';
                
                // Alle Subject-Checkboxes deaktivieren
                document.querySelectorAll('.subject-checkbox').forEach(label => {
                    label.classList.remove('checked');
                    const checkbox = label.querySelector('input');
                    checkbox.checked = false;
                });
                
                updateCharCounter();
                document.getElementById('topicModal').classList.add('show');
                
                console.log('CREATE modal opened - topicId:', document.getElementById('topicId').value);
            }
            
            window.closeModal = function() {
                console.log('Closing modal - resetting state');
                document.getElementById('topicModal').classList.remove('show');
                
                // Formular komplett zurücksetzen beim Schließen
                const form = document.getElementById('topicForm');
                form.reset();
                
                // topic_id explizit leeren
                const topicIdField = document.getElementById('topicId');
                topicIdField.value = '';
                topicIdField.removeAttribute('value');
                
                console.log('Modal closed - form reset');
            }
            
            window.showFlashMessage = function(message, type) {
                const flashContainer = document.getElementById('flash-messages');
                const flash = document.createElement('div');
                flash.className = 'flash-message flash-' + type;
                flash.textContent = message;
                flashContainer.appendChild(flash);
                setTimeout(() => { flash.remove(); }, 5000);
            }
            
            window.editTopic = async function(topicId) {
                const url = getAjaxUrl();
                console.log('Opening EDIT modal for topic:', topicId, '- URL:', url);
                
                document.getElementById('modalTitle').textContent = 'Thema bearbeiten';
                document.getElementById('submitBtn').textContent = 'Aktualisieren';
                
                try {
                    const formData = new FormData();
                    formData.append('action', 'get_topic');
                    formData.append('topic_id', topicId);
                    formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');

                    const response = await fetch(url, {
                        method: 'POST',
                        body: formData
                    });

                    if (!response.ok) throw new Error('Server-Fehler: ' + response.status);

                    const result = await response.json();
                    
                    if (result.success) {
                        const topic = result.topic;
                        
                        // Explizit alle Werte setzen
                        const topicIdField = document.getElementById('topicId');
                        topicIdField.value = topic.id;
                        topicIdField.setAttribute('value', topic.id); // Auch das HTML-Attribut setzen
                        
                        document.getElementById('topicTitle').value = topic.title;
                        document.getElementById('shortDescription').value = topic.short_description || '';
                        document.getElementById('isGlobal').checked = topic.is_global == 1;
                        
                        // Subject-Checkboxes setzen
                        document.querySelectorAll('.subject-checkbox').forEach(label => {
                            label.classList.remove('checked');
                            const checkbox = label.querySelector('input');
                            const isChecked = topic.subject_ids.includes(parseInt(checkbox.value));
                            checkbox.checked = isChecked;
                            if (isChecked) label.classList.add('checked');
                        });
                        
                        updateCharCounter();
                        document.getElementById('topicModal').classList.add('show');
                        
                        console.log('EDIT modal opened - topicId:', document.getElementById('topicId').value);
                    } else {
                        showFlashMessage(result.message, 'error');
                    }
                } catch (error) {
                    showFlashMessage('Fehler: ' + error.message, 'error');
                    console.error('Edit error:', error);
                }
            }
            
            window.deleteTopic = async function(topicId) {
                const url = getAjaxUrl();
                console.log('Delete topic - URL:', url);
                
                if (!confirm('Sind Sie sicher, dass Sie dieses Thema löschen möchten?')) return;

                try {
                    const formData = new FormData();
                    formData.append('action', 'delete_topic');
                    formData.append('topic_id', topicId);
                    formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');

                    const response = await fetch(url, {
                        method: 'POST',
                        body: formData
                    });

                    if (!response.ok) throw new Error('Server-Fehler: ' + response.status);

                    const result = await response.json();
                    
                    if (result.success) {
                        showFlashMessage(result.message, 'success');
                        document.querySelector('[data-topic-id="' + topicId + '"]').remove();
                        
                        if (document.querySelectorAll('.topic-card').length === 0) {
                            setTimeout(() => { window.location.reload(); }, 1000);
                        }
                    } else {
                        showFlashMessage(result.message, 'error');
                    }
                } catch (error) {
                    showFlashMessage('Fehler: ' + error.message, 'error');
                    console.error('Delete error:', error);
                }
            }
            
            // Event Listener nach DOM-Laden
            document.addEventListener('DOMContentLoaded', function() {
                // Form Submit Handler
                const form = document.getElementById('topicForm');
                if (form) {
                    form.addEventListener('submit', async function(e) {
                        e.preventDefault();
                        
                        const url = getAjaxUrl();
                        const submitBtn = document.getElementById('submitBtn');
                        const originalText = submitBtn.textContent;
                        
                        // Status vor Submit prüfen
                        const topicIdValue = document.getElementById('topicId').value;
                        const topicIdNum = parseInt(topicIdValue) || 0;
                        
                        console.log('=== FORM SUBMIT ===');
                        console.log('topicIdValue:', topicIdValue);
                        console.log('topicIdNum:', topicIdNum);
                        console.log('URL:', url);
                        
                        // Action basierend auf topic_id bestimmen
                        let action;
                        if (topicIdNum > 0) {
                            action = 'update_topic';
                            console.log('→ ACTION: UPDATE (topicId > 0)');
                        } else {
                            action = 'create_topic';
                            console.log('→ ACTION: CREATE (topicId <= 0)');
                        }
                        
                        submitBtn.textContent = action === 'create_topic' ? 'Erstellen...' : 'Aktualisieren...';
                        submitBtn.disabled = true;

                        try {
                            const formData = new FormData();
                            
                            // Action setzen
                            formData.append('action', action);
                            
                            // CSRF Token
                            formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
                            
                            // Für CREATE: topic_id explizit NICHT senden
                            if (action === 'create_topic') {
                                console.log('CREATE: topic_id wird NICHT gesendet');
                            } else {
                                // Für UPDATE: topic_id validieren und senden
                                if (topicIdNum <= 0) {
                                    throw new Error('Update ohne gültige Topic-ID nicht möglich');
                                }
                                formData.append('topic_id', topicIdNum.toString());
                                console.log('UPDATE: topic_id gesendet:', topicIdNum);
                            }
                            
                            // Restliche Formularfelder
                            formData.append('title', document.getElementById('topicTitle').value.trim());
                            formData.append('short_description', document.getElementById('shortDescription').value.trim());
                            formData.append('is_global', document.getElementById('isGlobal').checked ? '1' : '0');
                            
                            const selectedSubjects = [];
                            document.querySelectorAll('.subject-checkbox input:checked').forEach(input => {
                                selectedSubjects.push(input.value);
                            });
                            
                            console.log('Selected subjects:', selectedSubjects);
                            
                            if (selectedSubjects.length === 0) {
                                showFlashMessage('Bitte wählen Sie mindestens ein Fach aus.', 'error');
                                submitBtn.textContent = originalText;
                                submitBtn.disabled = false;
                                return;
                            }
                            
                            formData.append('subject_ids', JSON.stringify(selectedSubjects));

                            // Debug: FormData ausgeben
                            console.log('=== FormData being sent ===');
                            for (let [key, value] of formData.entries()) {
                                console.log(key + ': ' + value);
                            }

                            const response = await fetch(url, {
                                method: 'POST',
                                body: formData
                            });

                            if (!response.ok) throw new Error('Server-Fehler: ' + response.status);

                            const result = await response.json();
                            console.log('Server response:', result);
                            
                            if (result.success) {
                                showFlashMessage(result.message, 'success');
                                closeModal();
                                setTimeout(() => { window.location.reload(); }, 1000);
                            } else {
                                showFlashMessage(result.message, 'error');
                            }
                        } catch (error) {
                            showFlashMessage('Fehler: ' + error.message, 'error');
                            console.error('Submit error:', error);
                        } finally {
                            submitBtn.textContent = originalText;
                            submitBtn.disabled = false;
                        }
                    });
                }

                // View Toggle Handler
                document.querySelectorAll('.view-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        switchView(this.dataset.view);
                    });
                });

                // Checkbox Handler
                document.querySelectorAll('.subject-checkbox').forEach(label => {
                    const checkbox = label.querySelector('input');
                    
                    label.addEventListener('click', function(e) {
                        if (e.target.tagName !== 'INPUT') {
                            e.preventDefault();
                            checkbox.checked = !checkbox.checked;
                            this.classList.toggle('checked', checkbox.checked);
                        }
                    });
                    
                    checkbox.addEventListener('change', function() {
                        label.classList.toggle('checked', this.checked);
                    });
                });

                // Sort Handler
                const sortSelect = document.getElementById('sortSelect');
                if (sortSelect) {
                    sortSelect.addEventListener('change', function() {
                        const searchParams = new URLSearchParams(window.location.search);
                        searchParams.set('sort', this.value);
                        window.location.href = window.location.pathname + '?' + searchParams.toString();
                    });
                }

                // Modal Handler
                document.getElementById('topicModal').addEventListener('click', function(e) {
                    if (e.target === this) closeModal();
                });

                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && document.getElementById('topicModal').classList.contains('show')) {
                        closeModal();
                    }
                });

                updateCharCounter();
                console.log('Themen-Modul geladen und bereit!');
            });
        })();
    </script>
</body>
</html>
