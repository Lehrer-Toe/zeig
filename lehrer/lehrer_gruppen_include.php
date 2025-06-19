<?php
// Diese Datei wird von dashboard.php eingebunden
// Formularverarbeitung erfolgt bereits in dashboard.php

// Wichtige Variablen aus dashboard.php verfügbar machen
$school_id = $_SESSION['school_id'] ?? null;
$can_create_groups = $_SESSION['can_create_groups'] ?? 0;

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
$edit_group = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $db->prepare("
        SELECT g.*, c.name as class_name
        FROM groups g
        JOIN classes c ON g.class_id = c.id
        WHERE g.id = ? AND g.school_id = ? AND g.is_active = 1
        AND (g.teacher_id = ? OR EXISTS (
            SELECT 1 FROM group_students gs 
            WHERE gs.group_id = g.id AND gs.examiner_teacher_id = ?
        ))
    ");
    $stmt->execute([$edit_id, $school_id, $teacher_id, $teacher_id]);
    $edit_group = $stmt->fetch();
    
    if ($edit_group) {
        // Schüler der Gruppe laden
        $stmt = $db->prepare("
            SELECT gs.*, s.first_name, s.last_name, s.id as student_id,
                   et.name as examiner_name, subj.short_name as subject_short,
                   subj.full_name as subject_full
            FROM group_students gs
            JOIN students s ON gs.student_id = s.id
            LEFT JOIN users et ON gs.examiner_teacher_id = et.id
            LEFT JOIN subjects subj ON gs.subject_id = subj.id
            WHERE gs.group_id = ?
            ORDER BY s.last_name, s.first_name
        ");
        $stmt->execute([$edit_id]);
        $edit_group['students'] = $stmt->fetchAll();
    }
}
unset($edit_group); // Referenz aufheben falls vorhanden

// Letzte ausgewählte Klasse des Lehrers abrufen (optional)
$last_class_id = null;
try {
    $stmt = $db->prepare("SELECT last_selected_class_id FROM users WHERE id = ?");
    $stmt->execute([$teacher_id]);
    $last_class_id = $stmt->fetchColumn();
} catch (PDOException $e) {
    // Spalte existiert nicht - kein Problem, wir nutzen einfach keine Vorauswahl
    $last_class_id = null;
}

// Klassen abrufen
$stmt = $db->prepare("
    SELECT c.*, COUNT(s.id) as student_count
    FROM classes c
    LEFT JOIN students s ON c.id = s.class_id AND s.is_active = 1
    WHERE c.school_id = ? AND c.is_active = 1
    GROUP BY c.id
    ORDER BY c.name
");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

// Themen der Schule abrufen
$stmt = $db->prepare("
    SELECT t.*, u.name as teacher_name
    FROM topics t
    JOIN users u ON t.teacher_id = u.id
    WHERE t.school_id = ? AND t.is_active = 1
    ORDER BY t.title
");
$stmt->execute([$school_id]);
$topics = $stmt->fetchAll();

// Lehrer der Schule abrufen
$stmt = $db->prepare("
    SELECT id, name, email
    FROM users
    WHERE school_id = ? AND user_type = 'lehrer' AND is_active = 1
    ORDER BY name
");
$stmt->execute([$school_id]);
$teachers = $stmt->fetchAll();

// Fächer abrufen
$stmt = $db->prepare("
    SELECT * FROM subjects 
    WHERE school_id = ? AND is_active = 1 
    ORDER BY short_name
");
$stmt->execute([$school_id]);
$subjects = $stmt->fetchAll();

// Debug-Modus (kann bei Bedarf aktiviert werden)
$debug_mode = false; // Setzen Sie auf true für Debug-Ausgaben

// Gruppen abrufen (eigene und zugewiesene) - verbesserte Query ohne Duplikate
$stmt = $db->prepare("
    SELECT g.*, t.title as topic_title, c.name as class_name,
           creator.name as creator_name,
           (SELECT COUNT(DISTINCT gs1.student_id) FROM group_students gs1 WHERE gs1.group_id = g.id) as student_count,
           (SELECT COUNT(DISTINCT gs2.student_id) FROM group_students gs2 WHERE gs2.group_id = g.id AND gs2.is_examined = 1) as examined_count,
           CASE 
               WHEN g.teacher_id = ? THEN 1
               WHEN EXISTS (SELECT 1 FROM group_students gs3 WHERE gs3.group_id = g.id AND gs3.examiner_teacher_id = ?) THEN 2
               ELSE 0
           END as teacher_role
    FROM groups g
    LEFT JOIN topics t ON g.name = t.title
    JOIN classes c ON g.class_id = c.id
    JOIN users creator ON g.teacher_id = creator.id
    WHERE g.school_id = ? 
    AND g.is_active = 1
    AND (g.teacher_id = ? OR EXISTS (
        SELECT 1 FROM group_students gs4 
        WHERE gs4.group_id = g.id AND gs4.examiner_teacher_id = ?
    ))
    GROUP BY g.id
    ORDER BY g.created_at DESC
");
$stmt->execute([$teacher_id, $teacher_id, $school_id, $teacher_id, $teacher_id]);
$groups = $stmt->fetchAll();

// Sicherstellen, dass keine Duplikate vorhanden sind
$unique_groups = [];
$seen_ids = [];
foreach ($groups as $group) {
    if (!in_array($group['id'], $seen_ids)) {
        $unique_groups[] = $group;
        $seen_ids[] = $group['id'];
    }
}
$groups = $unique_groups;

// Für jede Gruppe die Schüler laden - Alternative ohne Referenz
for ($i = 0; $i < count($groups); $i++) {
    $stmt = $db->prepare("
        SELECT gs.*, s.first_name, s.last_name,
               et.name as examiner_name, subj.short_name as subject_short
        FROM group_students gs
        JOIN students s ON gs.student_id = s.id
        LEFT JOIN users et ON gs.examiner_teacher_id = et.id
        LEFT JOIN subjects subj ON gs.subject_id = subj.id
        WHERE gs.group_id = ?
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute([$groups[$i]['id']]);
    $groups[$i]['students'] = $stmt->fetchAll();
    
    if ($debug_mode) {
        error_log("Gruppe ID " . $groups[$i]['id'] . " hat " . count($groups[$i]['students']) . " Schüler");
    }
}
?>

<style>
/* Spezifische Styles für Gruppen-Modul */
.groups-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 20px;
    background: rgba(255, 255, 255, 0.9);
    border-radius: 15px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.groups-title {
    font-size: 28px;
    color: #002b45;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 15px;
}

.permission-badge {
    background: rgba(34, 197, 94, 0.1);
    color: #15803d;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 14px;
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

.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
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
    font-size: 12px;
    padding: 8px 16px;
}

.btn-danger:hover {
    background: #c0392b;
    transform: translateY(-1px);
}

.groups-container {
    display: grid;
    gap: 25px;
}

.group-card {
    background: rgba(255, 255, 255, 0.95);
    border: 2px solid transparent;
    border-radius: 15px;
    padding: 25px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.group-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
    border-color: #ff9900;
}

.group-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
}

.group-info h3 {
    font-size: 22px;
    color: #002b45;
    margin-bottom: 8px;
}

.group-meta {
    font-size: 13px;
    color: #666;
    line-height: 1.6;
}

.group-actions {
    display: flex;
    gap: 10px;
}

.students-list {
    display: grid;
    gap: 12px;
    margin-top: 20px;
}

.student-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: rgba(0, 43, 69, 0.05);
    border-radius: 10px;
    border: 1px solid rgba(0, 43, 69, 0.1);
}

.student-item.examined {
    background: rgba(34, 197, 94, 0.05);
    border-color: rgba(34, 197, 94, 0.2);
}

.student-item.not-examined {
    background: rgba(231, 76, 60, 0.05);
    border-color: rgba(231, 76, 60, 0.2);
}

.student-info {
    display: flex;
    align-items: center;
    gap: 15px;
    flex: 1;
}

.student-name {
    font-weight: 600;
    color: #002b45;
}

.student-examiner {
    font-size: 13px;
    color: #666;
}

.student-subject {
    background: #007bff;
    color: white;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
}

.examined-badge {
    color: #15803d;
    font-size: 20px;
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
    max-width: 900px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
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

.form-section {
    background: rgba(0, 43, 69, 0.03);
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
}

.form-section-title {
    font-size: 18px;
    font-weight: 600;
    color: #002b45;
    margin-bottom: 15px;
}

.students-selection {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 12px;
    max-height: 300px;
    overflow-y: auto;
    padding: 15px;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 10px;
}

.student-checkbox {
    display: flex;
    align-items: center;
    padding: 12px;
    background: rgba(255, 255, 255, 0.8);
    border: 2px solid rgba(0, 43, 69, 0.2);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.student-checkbox:hover {
    background: rgba(255, 255, 255, 0.95);
    border-color: #ff9900;
}

.student-checkbox input {
    margin-right: 8px;
}

.student-checkbox.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.student-checkbox.disabled:hover {
    background: rgba(255, 255, 255, 0.8);
    border-color: rgba(0, 43, 69, 0.2);
}

.assignment-grid {
    display: grid;
    gap: 15px;
}

.assignment-row {
    display: grid;
    grid-template-columns: 200px 1fr 1fr auto;
    gap: 15px;
    align-items: center;
    padding: 15px;
    background: rgba(255, 255, 255, 0.8);
    border-radius: 10px;
    border: 1px solid rgba(0, 43, 69, 0.1);
}

.assignment-student {
    font-weight: 600;
    color: #002b45;
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

.warning-text {
    color: #ff9900;
    font-size: 13px;
    margin-top: 5px;
    font-style: italic;
}

.info-text {
    color: #666;
    font-size: 13px;
    margin-top: 5px;
}

@media (max-width: 768px) {
    .groups-header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }

    .assignment-row {
        grid-template-columns: 1fr;
        text-align: center;
    }

    .group-header {
        flex-direction: column;
        gap: 15px;
    }

    .modal-content {
        padding: 20px;
        margin: 20px;
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

<div class="groups-header">
    <div class="groups-title">
        <span>👥 Gruppen erstellen</span>
        <?php if ($can_create_groups): ?>
            <span class="permission-badge">✓ Berechtigung erteilt</span>
        <?php endif; ?>
    </div>
    
    <?php if ($can_create_groups): ?>
        <button class="btn btn-primary" onclick="openCreateModal()">
            ➕ Neue Gruppe erstellen
        </button>
    <?php else: ?>
        <button class="btn btn-primary" disabled title="Sie haben keine Berechtigung, Gruppen zu erstellen">
            🔒 Neue Gruppe erstellen
        </button>
    <?php endif; ?>
</div>

<div class="groups-container">
    <?php if (empty($groups)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">👥</div>
            <h3>Noch keine Gruppen vorhanden</h3>
            <p>
                <?php if ($can_create_groups): ?>
                    Erstellen Sie Ihre erste Arbeitsgruppe für die Schüler.
                <?php else: ?>
                    Sie haben keine Berechtigung, Gruppen zu erstellen. Wenden Sie sich an Ihren Schuladministrator.
                <?php endif; ?>
            </p>
            <?php if ($can_create_groups): ?>
                <button class="btn btn-primary" onclick="openCreateModal()" style="margin-top: 20px;">
                    ➕ Erste Gruppe erstellen
                </button>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($groups as $group): ?>
            <div class="group-card">
                <div class="group-header">
                    <div class="group-info">
                        <h3><?= htmlspecialchars($group['name']) ?></h3>
                        <div class="group-meta">
                            ID: <?= $group['id'] ?> | 
                            Klasse: <?= htmlspecialchars($group['class_name']) ?><br>
                            Erstellt von: <?= htmlspecialchars($group['creator_name']) ?><br>
                            Erstellt am: <?= date('d.m.Y H:i', strtotime($group['created_at'])) ?><br>
                            Schüler: <?= $group['student_count'] ?>/4 | 
                            Geprüft: <?= $group['examined_count'] ?>/<?= $group['student_count'] ?>
                        </div>
                    </div>
                    
                    <?php if ($group['teacher_id'] == $teacher_id && $can_create_groups): ?>
                        <div class="group-actions">
                            <a href="?page=gruppen&edit=<?= $group['id'] ?>" class="btn btn-secondary">
                                ✏️ Bearbeiten
                            </a>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Sind Sie sicher, dass Sie diese Gruppe löschen möchten?');">
                                <input type="hidden" name="form_action" value="delete_group">
                                <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <button type="submit" class="btn btn-danger">
                                    🗑️ Löschen
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="students-list">
                    <?php foreach ($group['students'] as $student): ?>
                        <div class="student-item <?= $student['is_examined'] ? 'examined' : 'not-examined' ?>">
                            <div class="student-info">
                                <?php if ($student['is_examined']): ?>
                                    <span class="examined-badge">✓</span>
                                <?php else: ?>
                                    <span class="examined-badge" style="color: #dc2626;">✗</span>
                                <?php endif; ?>
                                <span class="student-name">
                                    <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                                </span>
                                <?php if ($student['examiner_name']): ?>
                                    <span class="student-examiner">
                                        → <?= htmlspecialchars($student['examiner_name']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($student['subject_short']): ?>
                                    <span class="student-subject">
                                        <?= htmlspecialchars($student['subject_short']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal für Gruppe erstellen/bearbeiten -->
<div class="modal <?= $edit_group ? 'show' : '' ?>" id="groupModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><?= $edit_group ? 'Gruppe bearbeiten' : 'Neue Gruppe anlegen' ?></h2>
            <button type="button" class="close-modal" onclick="closeModal()">&times;</button>
        </div>

        <form id="groupForm" method="POST" action="<?= $_SERVER['PHP_SELF'] ?>?page=gruppen">
            <input type="hidden" name="form_action" value="<?= $edit_group ? 'update_group' : 'create_group' ?>">
            <?php if ($edit_group): ?>
                <input type="hidden" name="group_id" value="<?= $edit_group['id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <?php if (!$edit_group): ?>
                <!-- Nur beim Erstellen -->
                <div class="form-group">
                    <label class="form-label" for="groupTopic">Thema (oder aus Liste wählen)</label>
                    <input type="text" class="form-control" id="groupTopic" name="topic" list="topics-list" required>
                    <datalist id="topics-list">
                        <?php foreach ($topics as $topic): ?>
                            <option value="<?= htmlspecialchars($topic['title']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title">1. Klasse auswählen:</h3>
                    <select class="form-control" id="classSelect" name="class_id" required onchange="loadClassStudents(this.value)">
                        <option value="">-- Klasse wählen --</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= $class['id'] ?>" <?= ($last_class_id && $last_class_id == $class['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($class['name']) ?> (<?= $class['student_count'] ?> Schüler)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title">2. Schüler auswählen:</h3>
                    <p class="info-text">Verfügbare Schüler auswählen (max. 4 pro Gruppe):</p>
                    <div id="studentsContainer" class="students-selection">
                        <p class="info-text" style="grid-column: 1/-1; text-align: center;">
                            Bitte wählen Sie zuerst eine Klasse aus.
                        </p>
                    </div>
                    <p class="warning-text" id="studentLimitWarning" style="display: none;">
                        Maximal 4 Schüler pro Gruppe erlaubt!
                    </p>
                </div>
            <?php else: ?>
                <!-- Beim Bearbeiten -->
                <div class="form-group">
                    <label class="form-label">Thema:</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($edit_group['name']) ?>" name="topic" required>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title">Schüler:</h3>
                    <div class="assignment-grid">
                        <?php foreach ($edit_group['students'] as $student): ?>
                            <div class="assignment-row">
                                <div class="assignment-student">
                                    <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                                </div>
                                <select class="form-control" name="examiner[<?= $student['student_id'] ?>]">
                                    <option value="">-- Lehrer wählen --</option>
                                    <?php foreach ($teachers as $teacher): ?>
                                        <option value="<?= $teacher['id'] ?>" <?= $student['examiner_teacher_id'] == $teacher['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($teacher['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="form-control" name="subject[<?= $student['student_id'] ?>]">
                                    <option value="">-- Fach wählen --</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?= $subject['id'] ?>" <?= $student['subject_id'] == $subject['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($subject['short_name'] . ' - ' . $subject['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-danger" onclick="removeStudent(<?= $student['student_id'] ?>)">
                                    Entfernen
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-section" id="assignmentSection" style="<?= $edit_group ? 'display: none;' : '' ?>">
                <h3 class="form-section-title">3. Lehrer und Fächer zuweisen:</h3>
                <div id="assignmentsContainer" class="assignment-grid">
                    <p class="info-text" style="text-align: center;">
                        <?= $edit_group ? '' : 'Keine Schüler ausgewählt.' ?>
                    </p>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary"><?= $edit_group ? 'Speichern' : 'Gruppe erstellen' ?></button>
            </div>
        </form>
    </div>
</div>

<script>
let selectedStudents = [];
const maxStudents = 4;

function openCreateModal() {
    document.getElementById('groupForm').reset();
    selectedStudents = [];
    document.getElementById('groupModal').classList.add('show');
    updateAssignmentsDisplay();
    
    // Wenn letzte Klasse vorhanden, Schüler laden
    const classSelect = document.getElementById('classSelect');
    if (classSelect && classSelect.value) {
        loadClassStudents(classSelect.value);
    }
}

function closeModal() {
    <?php if ($edit_group): ?>
        window.location.href = '<?= $_SERVER['PHP_SELF'] ?>?page=gruppen';
    <?php else: ?>
        document.getElementById('groupModal').classList.remove('show');
    <?php endif; ?>
}

function loadClassStudents(classId) {
    if (!classId) {
        document.getElementById('studentsContainer').innerHTML = '<p class="info-text" style="grid-column: 1/-1; text-align: center;">Bitte wählen Sie zuerst eine Klasse aus.</p>';
        return;
    }

    // AJAX-Request simulieren (in Produktion würde hier ein echter AJAX-Call stehen)
    fetch(`get_class_students.php?class_id=${classId}`)
        .then(response => response.json())
        .then(data => {
            displayStudents(data.students);
        })
        .catch(() => {
            // Fallback für Demo
            displayStudents([]);
        });
}

function displayStudents(students) {
    const container = document.getElementById('studentsContainer');
    
    if (students.length === 0) {
        // Demo-Daten für Entwicklung
        <?php if (isset($classes[0])): ?>
            // Lade Schüler der ausgewählten Klasse
            const classId = document.getElementById('classSelect').value;
            if (classId) {
                fetch(`dashboard.php?page=gruppen&action=get_students&class_id=${classId}`)
                    .then(response => response.text())
                    .then(html => {
                        container.innerHTML = html;
                    });
            }
        <?php endif; ?>
        return;
    }
    
    let html = '';
    students.forEach(student => {
        const isDisabled = student.has_group ? 'disabled' : '';
        html += `
            <label class="student-checkbox ${isDisabled}">
                <input type="checkbox" name="students[]" value="${student.id}" 
                    onchange="toggleStudent(${student.id}, '${student.name}')" 
                    ${isDisabled}>
                <span>${student.name}</span>
                ${student.has_group ? '<small style="color: #999;"> (bereits in Gruppe)</small>' : ''}
            </label>
        `;
    });
    
    container.innerHTML = html;
}

function toggleStudent(studentId, studentName) {
    const checkbox = document.querySelector(`input[value="${studentId}"]`);
    
    if (checkbox.checked) {
        if (selectedStudents.length >= maxStudents) {
            checkbox.checked = false;
            document.getElementById('studentLimitWarning').style.display = 'block';
            setTimeout(() => {
                document.getElementById('studentLimitWarning').style.display = 'none';
            }, 3000);
            return;
        }
        selectedStudents.push({ id: studentId, name: studentName });
    } else {
        selectedStudents = selectedStudents.filter(s => s.id !== studentId);
    }
    
    updateAssignmentsDisplay();
}

function updateAssignmentsDisplay() {
    const container = document.getElementById('assignmentsContainer');
    const section = document.getElementById('assignmentSection');
    
    if (selectedStudents.length === 0) {
        container.innerHTML = '<p class="info-text" style="text-align: center;">Keine Schüler ausgewählt.</p>';
        section.style.display = 'none';
        return;
    }
    
    section.style.display = 'block';
    let html = '<p class="info-text">Ausgewählte Schüler - Lehrer und Fach zuweisen:</p>';
    
    selectedStudents.forEach(student => {
        html += `
            <div class="assignment-row">
                <div class="assignment-student">${student.name}</div>
                <select class="form-control" name="examiner[${student.id}]" required>
                    <option value="">-- Lehrer wählen --</option>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-control" name="subject[${student.id}]" required>
                    <option value="">-- Fach wählen --</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?= $subject['id'] ?>"><?= htmlspecialchars($subject['short_name'] . ' - ' . $subject['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function removeStudent(studentId) {
    if (!confirm('Möchten Sie diesen Schüler wirklich aus der Gruppe entfernen?')) {
        return;
    }
    
    // In Produktion würde hier ein AJAX-Call zum Entfernen erfolgen
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="form_action" value="remove_student">
        <input type="hidden" name="group_id" value="<?= $edit_group ? $edit_group['id'] : '' ?>">
        <input type="hidden" name="student_id" value="${studentId}">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    `;
    document.body.appendChild(form);
    form.submit();
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // Flash Messages automatisch ausblenden
    setTimeout(() => {
        document.querySelectorAll('.flash-message').forEach(msg => {
            msg.style.transition = 'opacity 0.3s';
            msg.style.opacity = '0';
            setTimeout(() => msg.remove(), 300);
        });
    }, 5000);
    
    // Modal Click-Outside Handler
    document.getElementById('groupModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
    
    // ESC Key Handler
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('groupModal');
            if (modal && modal.classList.contains('show')) {
                closeModal();
            }
        }
    });
});

// Demo-Funktion für AJAX-Fallback (in Produktion durch echte API ersetzen)
<?php if (!$edit_group && isset($_GET['action']) && $_GET['action'] === 'get_students'): ?>
    <?php
    $class_id = (int)($_GET['class_id'] ?? 0);
    if ($class_id > 0) {
        $stmt = $db->prepare("
            SELECT s.*, 
                   CASE WHEN gs.id IS NOT NULL THEN 1 ELSE 0 END as has_group
            FROM students s
            LEFT JOIN group_students gs ON s.id = gs.student_id
            WHERE s.class_id = ? AND s.is_active = 1
            ORDER BY s.last_name, s.first_name
        ");
        $stmt->execute([$class_id]);
        $students = $stmt->fetchAll();
        
        foreach ($students as $student) {
            $isDisabled = $student['has_group'] ? 'disabled' : '';
            $name = htmlspecialchars($student['first_name'] . ' ' . $student['last_name']);
            echo <<<HTML
                <label class="student-checkbox {$isDisabled}">
                    <input type="checkbox" name="students[]" value="{$student['id']}" 
                        onchange="toggleStudent({$student['id']}, '{$name}')" 
                        {$isDisabled}>
                    <span>{$name}</span>
HTML;
            if ($student['has_group']) {
                echo '<small style="color: #999;"> (bereits in Gruppe)</small>';
            }
            echo '</label>';
        }
    }
    exit();
    ?>
<?php endif; ?>
</script>