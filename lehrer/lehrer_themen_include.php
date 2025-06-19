<?php
// Diese Datei wird von dashboard.php eingebunden
// Formularverarbeitung erfolgt bereits in dashboard.php

// Wichtige Variablen aus dashboard.php verfügbar machen
$school_id = $_SESSION['school_id'] ?? null;

// Flash-Messages verarbeiten
$flash_message = null;
$flash_type = null;
if (isset($_SESSION['flash_message'])) {
    $flash_message = $_SESSION['flash_message'];
    $flash_type = $_SESSION['flash_type'];
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

// Bearbeitungsmodus prüfen
$edit_topic = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $db->prepare("
        SELECT t.*
        FROM topics t
        WHERE t.id = ? AND t.teacher_id = ? AND t.school_id = ? AND t.is_active = 1
    ");
    $stmt->execute([$edit_id, $teacher_id, $school_id]);
    $edit_topic = $stmt->fetch();
    
    if ($edit_topic) {
        // Fächer separat laden
        $stmt = $db->prepare("SELECT subject_id FROM topic_subjects WHERE topic_id = ?");
        $stmt->execute([$edit_id]);
        $edit_topic['subject_ids'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Fächer abrufen
$stmt = $db->prepare("SELECT * FROM subjects WHERE school_id = ? AND is_active = 1 ORDER BY short_name");
$stmt->execute([$school_id]);
$subjects = $stmt->fetchAll();

// Filter und Sortierung
$filter = $_GET['filter'] ?? 'school';
$sort_by = $_GET['sort'] ?? 'alphabetic';
$subject_filter = $_GET['subject'] ?? 'all';

$order_clause = "ORDER BY t.title ASC";
switch ($sort_by) {
    case 'date':
        $order_clause = "ORDER BY t.created_at DESC";
        break;
    case 'updated':
        $order_clause = "ORDER BY t.updated_at DESC";
        break;
}

// Themen abrufen
if ($filter === 'global') {
    $query = "
        SELECT t.*, u.name as teacher_name, s.name as school_name
        FROM topics t 
        LEFT JOIN users u ON t.teacher_id = u.id
        LEFT JOIN schools s ON t.school_id = s.id
        WHERE t.is_global = 1 AND t.is_active = 1
    ";
    
    if ($subject_filter !== 'all') {
        $query .= " AND EXISTS (SELECT 1 FROM topic_subjects ts WHERE ts.topic_id = t.id AND ts.subject_id = ?)";
    }
    
    $query .= " $order_clause";
    
    $stmt = $db->prepare($query);
    if ($subject_filter !== 'all') {
        $stmt->execute([(int)$subject_filter]);
    } else {
        $stmt->execute();
    }
} else {
    // FIX: Zeige alle Themen der Schule, nicht nur die des aktuellen Lehrers
    $query = "
        SELECT t.*, u.name as teacher_name
        FROM topics t 
        LEFT JOIN users u ON t.teacher_id = u.id
        WHERE t.school_id = ? AND t.is_active = 1
    ";
    
    if ($subject_filter !== 'all') {
        $query .= " AND EXISTS (SELECT 1 FROM topic_subjects ts WHERE ts.topic_id = t.id AND ts.subject_id = ?)";
    }
    
    $query .= " $order_clause";
    
    $stmt = $db->prepare($query);
    if ($subject_filter !== 'all') {
        $stmt->execute([$school_id, (int)$subject_filter]);
    } else {
        $stmt->execute([$school_id]);
    }
}
$topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fächer für jedes Thema separat laden
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
unset($topic);
?>

<style>
/* Spezifische Styles für Themen-Modul */
.topics-controls {
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
    font-size: 14px;
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
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        transform: translateY(-20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
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

.subject-hint {
    font-size: 12px;
    color: #666;
    margin-top: 5px;
    font-style: italic;
}

@media (max-width: 768px) {
    .topics-controls {
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

<!-- Flash Messages -->
<div id="flash-messages">
    <?php if ($flash_message): ?>
        <div class="flash-message flash-<?= htmlspecialchars($flash_type) ?>">
            <?= htmlspecialchars($flash_message) ?>
        </div>
    <?php endif; ?>
</div>

<div class="topics-controls">
    <div class="filter-controls">
        <div class="toggle-group">
            <button class="toggle-btn <?= $filter === 'school' ? 'active' : '' ?>" onclick="changeFilter('school')">🏫 Schule</button>
            <button class="toggle-btn <?= $filter === 'global' ? 'active' : '' ?>" onclick="changeFilter('global')">🌍 Global</button>
        </div>

        <div class="view-toggle">
            <button class="view-btn active" data-view="list">📋 Liste</button>
            <button class="view-btn" data-view="grid">🎯 Kacheln</button>
        </div>

        <select class="select-control" id="subjectFilter" onchange="changeSubjectFilter(this.value)">
            <option value="all" <?= $subject_filter === 'all' ? 'selected' : '' ?>>Alle Fächer</option>
            <?php foreach ($subjects as $subject): ?>
                <option value="<?= $subject['id'] ?>" <?= $subject_filter == $subject['id'] ? 'selected' : '' ?> style="color: <?= htmlspecialchars($subject['color']) ?>">
                    <?= htmlspecialchars($subject['short_name']) ?> - <?= htmlspecialchars($subject['full_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="select-control" id="sortSelect" onchange="changeSort(this.value)">
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

<div class="topics-list" id="topicsContainer">
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
                                    ID: <?= $topic['id'] ?> | 
                                    <?php if ($filter === 'global' && isset($topic['school_name'])): ?>
                                        🏫 <?= htmlspecialchars($topic['school_name']) ?><br>
                                    <?php endif; ?>
                                    <?php // Zeige den Lehrer-Namen auch bei Schul-Themen, wenn es nicht der aktuelle Lehrer ist ?>
                                    <?php if ($topic['teacher_id'] != $teacher_id): ?>
                                        👤 <?= htmlspecialchars($topic['teacher_name']) ?><br>
                                    <?php endif; ?>
                                    Erstellt: <?= date('d.m.Y H:i', strtotime($topic['created_at'])) ?>
                                    <?php if ($topic['updated_at'] !== $topic['created_at']): ?>
                                        | Geändert: <?= date('d.m.Y H:i', strtotime($topic['updated_at'])) ?>
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
                            <?php if (!empty($topic['subjects'])): ?>
                                <?php foreach ($topic['subjects'] as $subject): ?>
                                    <span class="subject-tag" style="background-color: <?= htmlspecialchars($subject['color']) ?>">
                                        <?= htmlspecialchars($subject['short_name']) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span style="color: #999; font-size: 12px;">Keine Fächer zugeordnet</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php // Bearbeitungs- und Löschbuttons nur für eigene Themen anzeigen ?>
                <?php if ($topic['teacher_id'] == $teacher_id): ?>
                    <div class="topic-actions">
                        <a href="?page=themen&edit=<?= $topic['id'] ?>&filter=<?= $filter ?>&sort=<?= $sort_by ?>&subject=<?= $subject_filter ?>" class="btn btn-secondary btn-sm">
                            ✏️ Bearbeiten
                        </a>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Sind Sie sicher, dass Sie dieses Thema löschen möchten?');">
                            <input type="hidden" name="form_action" value="delete_topic">
                            <input type="hidden" name="topic_id" value="<?= $topic['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">
                                🗑️ Löschen
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal für Thema erstellen/bearbeiten -->
<div class="modal <?= $edit_topic ? 'show' : '' ?>" id="topicModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle"><?= $edit_topic ? 'Thema bearbeiten' : 'Neues Thema' ?></h2>
            <button type="button" class="close-modal" onclick="closeModal()">&times;</button>
        </div>

        <form id="topicForm" method="POST" action="<?= $_SERVER['PHP_SELF'] ?>?page=themen&filter=<?= $filter ?>&sort=<?= $sort_by ?>&subject=<?= $subject_filter ?>" autocomplete="off">
            <input type="hidden" name="form_action" value="<?= $edit_topic ? 'update_topic' : 'create_topic' ?>">
            <?php if ($edit_topic): ?>
                <input type="hidden" name="topic_id" value="<?= $edit_topic['id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <label class="form-label" for="topicTitle">Titel *</label>
                <input type="text" class="form-control" id="topicTitle" name="title" required maxlength="255" autocomplete="off" value="<?= $edit_topic ? htmlspecialchars($edit_topic['title']) : '' ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="shortDescription">Kurzbeschreibung (240 Zeichen)</label>
                <textarea class="form-control" id="shortDescription" name="short_description" maxlength="240" rows="4" oninput="updateCharCounter()" autocomplete="off"><?= $edit_topic ? htmlspecialchars($edit_topic['short_description']) : '' ?></textarea>
                <div id="charCounter" class="char-counter">0 / 240</div>
            </div>

            <div class="form-group">
                <label class="form-label">Fächer auswählen (optional)</label>
                <div class="subjects-grid" id="subjectsGrid">
                    <?php foreach ($subjects as $subject): ?>
                        <label class="subject-checkbox <?= ($edit_topic && in_array($subject['id'], $edit_topic['subject_ids'])) ? 'checked' : '' ?>">
                            <input type="checkbox" name="subjects[]" value="<?= $subject['id'] ?>" <?= ($edit_topic && in_array($subject['id'], $edit_topic['subject_ids'])) ? 'checked' : '' ?>>
                            <span style="color: <?= htmlspecialchars($subject['color']) ?>">
                                <?= htmlspecialchars($subject['short_name']) ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="subject-hint">Sie können später jederzeit Fächer hinzufügen oder entfernen</div>
            </div>

            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" id="isGlobal" name="is_global" value="1" <?= ($edit_topic && $edit_topic['is_global']) ? 'checked' : '' ?>>
                    <label for="isGlobal">🌍 Thema global für andere Schulen sichtbar machen</label>
                </div>
                <small style="color: #666; margin-top: 5px; display: block; font-size: 12px;">
                    Globale Themen können von anderen Schulen eingesehen werden
                </small>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary" id="submitBtn"><?= $edit_topic ? 'Aktualisieren' : 'Speichern' ?></button>
            </div>
        </form>
    </div>
</div>

<script>
// JavaScript für UI-Interaktionen
let currentView = 'list';

function openCreateModal() {
    // Form reset
    document.getElementById('topicForm').reset();
    document.querySelectorAll('.subject-checkbox').forEach(label => {
        label.classList.remove('checked');
        const checkbox = label.querySelector('input');
        if (checkbox) checkbox.checked = false;
    });
    
    // Modal zeigen
    document.getElementById('modalTitle').textContent = 'Neues Thema';
    document.getElementById('topicModal').classList.add('show');
    updateCharCounter();
    
    setTimeout(() => {
        document.getElementById('topicTitle').focus();
    }, 100);
}

function closeModal() {
    <?php if ($edit_topic): ?>
        // Bei Bearbeitung: zurück zur Liste
        window.location.href = '<?= $_SERVER['PHP_SELF'] ?>?page=themen&filter=<?= $filter ?>&sort=<?= $sort_by ?>&subject=<?= $subject_filter ?>';
    <?php else: ?>
        document.getElementById('topicModal').classList.remove('show');
    <?php endif; ?>
}

function updateCharCounter() {
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

function switchView(view) {
    currentView = view;
    document.querySelectorAll('.view-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.view === view);
    });
    const container = document.getElementById('topicsContainer');
    container.className = view === 'list' ? 'topics-list' : 'topics-grid';
}

function changeFilter(filter) {
    const searchParams = new URLSearchParams(window.location.search);
    searchParams.set('page', 'themen');
    searchParams.set('filter', filter);
    window.location.href = window.location.pathname + '?' + searchParams.toString();
}

function changeSort(sort) {
    const searchParams = new URLSearchParams(window.location.search);
    searchParams.set('page', 'themen');
    searchParams.set('sort', sort);
    window.location.href = window.location.pathname + '?' + searchParams.toString();
}

function changeSubjectFilter(subject) {
    const searchParams = new URLSearchParams(window.location.search);
    searchParams.set('page', 'themen');
    searchParams.set('subject', subject);
    window.location.href = window.location.pathname + '?' + searchParams.toString();
}

// DOM Ready
document.addEventListener('DOMContentLoaded', function() {
    // Flash Messages automatisch ausblenden
    setTimeout(() => {
        document.querySelectorAll('.flash-message').forEach(msg => {
            msg.style.transition = 'opacity 0.3s';
            msg.style.opacity = '0';
            setTimeout(() => msg.remove(), 300);
        });
    }, 5000);
    
    // View Toggle Handler
    document.querySelectorAll('.view-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            switchView(this.dataset.view);
        });
    });
    
    // Subject Checkbox Handler
    document.querySelectorAll('.subject-checkbox').forEach(label => {
        const checkbox = label.querySelector('input');
        if (!checkbox) return;
        
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
    
    // Modal Click-Outside Handler
    document.getElementById('topicModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
    
    // ESC Key Handler
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('topicModal');
            if (modal && modal.classList.contains('show')) {
                closeModal();
            }
        }
    });
    
    // Initial Character Counter
    updateCharCounter();
    
    // Wenn im Edit-Modus, Modal öffnen lassen
    <?php if ($edit_topic): ?>
        updateCharCounter();
    <?php endif; ?>
});
</script>