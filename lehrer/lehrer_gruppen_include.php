<?php
// Diese Datei wird von dashboard.php eingebunden
// Formularverarbeitung erfolgt bereits in dashboard.php

// Wichtige Variablen aus dashboard.php verfügbar machen
$school_id = $_SESSION['school_id'] ?? null;

// WICHTIG: can_create_groups direkt aus der Datenbank laden, nicht nur aus Session
$stmt = $db->prepare("SELECT can_create_groups FROM users WHERE id = ? AND user_type = 'lehrer'");
$stmt->execute([$teacher_id]);
$result = $stmt->fetch();
$can_create_groups = isset($result['can_create_groups']) ? (int)$result['can_create_groups'] : 0;

// Session aktualisieren falls nötig
$_SESSION['can_create_groups'] = $can_create_groups;

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
        // Schüler der Gruppe laden mit Bewertungsstatus
        $stmt = $db->prepare("
            SELECT gs.*, s.first_name, s.last_name, s.id as student_id,
                   et.name as examiner_name, subj.short_name as subject_short,
                   subj.full_name as subject_full, subj.color as subject_color,
                   CASE 
                       WHEN r.id IS NOT NULL AND r.is_complete = 1 THEN 1 
                       ELSE 0 
                   END as is_complete
            FROM group_students gs
            JOIN students s ON gs.student_id = s.id
            LEFT JOIN users et ON gs.examiner_teacher_id = et.id
            LEFT JOIN subjects subj ON gs.subject_id = subj.id
            LEFT JOIN ratings r ON r.student_id = gs.student_id 
                AND r.group_id = gs.group_id 
                AND r.teacher_id = gs.examiner_teacher_id
                AND r.is_complete = 1
            WHERE gs.group_id = ?
            ORDER BY s.last_name, s.first_name
        ");
        $stmt->execute([$edit_id]);
        $edit_group['students'] = $stmt->fetchAll();
    }
}

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

// Klassen abrufen mit Anzahl VERFÜGBARER Schüler
$stmt = $db->prepare("
    SELECT c.*, 
           COUNT(DISTINCT CASE 
               WHEN NOT EXISTS (
                   SELECT 1 FROM group_students gs 
                   WHERE gs.student_id = s.id
               ) THEN s.id 
           END) as available_students,
           COUNT(s.id) as total_students
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

// Fächer abrufen mit Farben
$stmt = $db->prepare("
    SELECT * FROM subjects 
    WHERE school_id = ? AND is_active = 1 
    ORDER BY short_name
");
$stmt->execute([$school_id]);
$subjects = $stmt->fetchAll();

// Gruppen abrufen (eigene und zugewiesene) - verbesserte Query ohne Duplikate
$stmt = $db->prepare("
    SELECT g.*, t.title as topic_title, c.name as class_name,
           creator.name as creator_name,
           (SELECT COUNT(DISTINCT gs1.student_id) FROM group_students gs1 WHERE gs1.group_id = g.id) as student_count,
           (SELECT COUNT(DISTINCT gs2.student_id) 
            FROM group_students gs2 
            JOIN ratings r ON r.student_id = gs2.student_id 
                AND r.group_id = gs2.group_id 
                AND r.teacher_id = gs2.examiner_teacher_id 
                AND r.is_complete = 1
            WHERE gs2.group_id = g.id) as examined_count,
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

// Für jede Gruppe die Schüler laden mit Bewertungsstatus und Fachfarben
foreach ($groups as &$group) {
    $stmt = $db->prepare("
        SELECT gs.*, s.first_name, s.last_name, 
               et.name as examiner_name, 
               subj.short_name as subject_short,
               subj.full_name as subject_full,
               subj.color as subject_color,
               CASE 
                   WHEN r.id IS NOT NULL AND r.is_complete = 1 THEN 1 
                   ELSE 0 
               END as is_complete
        FROM group_students gs
        JOIN students s ON gs.student_id = s.id
        LEFT JOIN users et ON gs.examiner_teacher_id = et.id
        LEFT JOIN subjects subj ON gs.subject_id = subj.id
        LEFT JOIN ratings r ON r.student_id = gs.student_id 
            AND r.group_id = gs.group_id 
            AND r.teacher_id = gs.examiner_teacher_id
        WHERE gs.group_id = ?
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute([$group['id']]);
    $group['students'] = $stmt->fetchAll();
}
unset($group); // Referenz aufheben
?>

<style>
/* Spezifische Styles für Gruppen-Modul */
.groups-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    background: rgba(255, 255, 255, 0.9);
    padding: 25px;
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

.groups-actions {
    display: flex;
    gap: 15px;
    align-items: center;
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

.btn-primary:hover:not(:disabled) {
    background: #ff9900;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(255, 153, 0, 0.3);
}

.btn-primary:disabled {
    background: #e5e7eb;
    color: #9ca3af;
    border-color: #d1d5db;
    cursor: not-allowed;
    transform: none;
}

.btn-print {
    background: rgba(255, 255, 255, 0.9);
    color: #002b45;
    border: 2px solid rgba(0, 43, 69, 0.3);
}

.btn-print:hover {
    background: #002b45;
    color: white;
    border-color: #002b45;
    transform: translateY(-1px);
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
    background: #ef4444;
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
    transform: translateY(-1px);
}

.groups-container {
    display: flex;
    flex-direction: column;
    gap: 20px;
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
    border: 2px solid rgba(0, 43, 69, 0.1);
    transition: all 0.3s ease;
}

.student-item.completed {
    background: rgba(34, 197, 94, 0.05);
    border-color: rgba(34, 197, 94, 0.3);
}

.student-item.not-completed {
    background: rgba(239, 68, 68, 0.05);
    border-color: rgba(239, 68, 68, 0.2);
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
    color: white;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

.completion-badge {
    font-size: 20px;
}

.completion-badge.completed {
    color: #22c55e;
}

.completion-badge.not-completed {
    color: #ef4444;
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
    overflow-y: auto;
    padding: 20px 0;
}

.modal.show {
    display: block;
}

.modal-content {
    background: rgba(255, 255, 255, 0.98);
    border: 2px solid #ff9900;
    border-radius: 20px;
    padding: 30px;
    max-width: 900px;
    width: 90%;
    margin: 20px auto;
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

.form-control:disabled {
    background: rgba(156, 163, 175, 0.1);
    cursor: not-allowed;
}

.form-hint {
    font-size: 12px;
    color: #666;
    margin-top: 5px;
}

.student-selection {
    background: rgba(255, 255, 255, 0.8);
    border: 1px solid rgba(0, 43, 69, 0.2);
    border-radius: 10px;
    padding: 15px;
    max-height: 300px;
    overflow-y: auto;
}

.students-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 10px;
}

.student-card-select {
    position: relative;
    background: rgba(255, 255, 255, 0.95);
    border: 2px solid rgba(0, 43, 69, 0.2);
    border-radius: 12px;
    padding: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-align: center;
    min-height: 80px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    gap: 5px;
}

.student-card-select:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    border-color: #ff9900;
    background: rgba(255, 153, 0, 0.05);
}

@keyframes selectPulse {
    0% {
        transform: scale(1.05);
    }
    50% {
        transform: scale(1.1);
    }
    100% {
        transform: scale(1.05);
    }
}

.student-card-select.selected {
    background: linear-gradient(135deg, #ff9900 0%, #ffb347 100%);
    color: white;
    border-color: #ff9900;
    transform: scale(1.05);
    box-shadow: 0 6px 20px rgba(255, 153, 0, 0.3);
    animation: selectPulse 0.3s ease;
}

.student-card-select.selected .student-initials {
    background: rgba(255, 255, 255, 0.3);
    color: white;
}

.student-card-select input[type="checkbox"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.student-initials {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, #43536a 0%, #536179 100%);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    margin-bottom: 5px;
    transition: all 0.3s ease;
}

.student-name-short {
    font-size: 13px;
    font-weight: 600;
    line-height: 1.2;
    word-break: break-word;
}

.selected-counter {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #22c55e;
    color: white;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    opacity: 0;
    transform: scale(0);
    transition: all 0.3s ease;
}

.student-card-select.selected .selected-counter {
    opacity: 1;
    transform: scale(1);
}

.selection-info {
    text-align: center;
    margin-top: 15px;
    padding: 10px;
    background: rgba(99, 102, 241, 0.1);
    border-radius: 8px;
    font-size: 14px;
    color: #4f46e5;
    font-weight: 600;
}

.selection-info.warning {
    background: rgba(255, 153, 0, 0.1);
    color: #ff9900;
}

.selection-info.error {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
}

/* Scrollbar styling */
.student-selection::-webkit-scrollbar {
    width: 8px;
}

.student-selection::-webkit-scrollbar-track {
    background: rgba(0, 43, 69, 0.05);
    border-radius: 4px;
}

.student-selection::-webkit-scrollbar-thumb {
    background: rgba(0, 43, 69, 0.2);
    border-radius: 4px;
}

.student-selection::-webkit-scrollbar-thumb:hover {
    background: rgba(0, 43, 69, 0.3);
}

.assignment-row {
    display: grid;
    grid-template-columns: 1fr 150px 150px;
    gap: 15px;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid rgba(0, 43, 69, 0.1);
}

.assignment-row:last-child {
    border-bottom: none;
}

.student-label {
    font-weight: 600;
    color: #002b45;
}

.modal-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    margin-top: 25px;
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

/* Print Styles */
@media print {
    body {
        background: white !important;
    }
    
    .groups-header {
        background: white !important;
        box-shadow: none !important;
        border: 1px solid #ddd;
        page-break-after: avoid;
        padding: 15px !important;
        margin-bottom: 10px !important;
    }
    
    .groups-title {
        font-size: 24px !important;
        text-align: center;
        width: 100%;
    }
    
    .groups-actions {
        display: none !important;
    }
    
    .group-card {
        background: white !important;
        box-shadow: none !important;
        border: 2px solid #333 !important;
        page-break-inside: avoid;
        margin-bottom: 15px;
        padding: 15px !important;
    }
    
    .group-header {
        border-bottom: 1px solid #ccc;
        padding-bottom: 10px;
        margin-bottom: 10px;
    }
    
    .group-info h3 {
        font-size: 18px !important;
        margin-bottom: 5px !important;
    }
    
    .group-meta {
        font-size: 11px !important;
        line-height: 1.4 !important;
    }
    
    .group-actions {
        display: none !important;
    }
    
    .students-list {
        gap: 5px !important;
    }
    
    .student-item {
        padding: 8px 12px !important;
        border: 1px solid #ddd !important;
        background: #f9f9f9 !important;
    }
    
    .student-item.completed {
        background: #e8f5e9 !important;
        border-color: #4caf50 !important;
    }
    
    .student-subject {
        border: 1px solid #666 !important;
        color: #000 !important;
        background: #e0e0e0 !important;
        text-shadow: none !important;
        padding: 2px 6px !important;
        font-size: 10px !important;
    }
    
    .completion-badge {
        font-size: 14px;
    }
    
    .student-name {
        font-size: 13px !important;
    }
    
    .student-examiner {
        font-size: 11px !important;
    }
    
    .btn, .modal, .flash-message, .empty-state button {
        display: none !important;
    }
    
    /* Zusammenfassung am Ende */
    @page {
        margin: 1.5cm;
    }
    
    .groups-container::after {
        content: "Druckdatum: " attr(data-print-date);
        display: block;
        text-align: right;
        font-size: 10px;
        color: #666;
        margin-top: 20px;
        padding-top: 10px;
        border-top: 1px solid #ccc;
    }
}

@media (max-width: 768px) {
    .groups-header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }

    .groups-actions {
        flex-direction: column;
        width: 100%;
    }

    .btn {
        width: 100%;
        justify-content: center;
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
    
    .students-grid {
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        gap: 8px;
    }
    
    .student-card-select {
        min-height: 70px;
        padding: 10px;
    }
    
    .student-initials {
        width: 32px;
        height: 32px;
        font-size: 12px;
    }
    
    .student-name-short {
        font-size: 12px;
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
    </div>
    
    <div class="groups-actions">
        <?php if ($can_create_groups): ?>
            <button class="btn btn-primary" onclick="openCreateModal()">
                ➕ Neue Gruppe erstellen
            </button>
        <?php else: ?>
            <button class="btn btn-primary" disabled title="Sie haben keine Berechtigung, Gruppen zu erstellen">
                🔒 Neue Gruppe erstellen
            </button>
        <?php endif; ?>
        
        <?php if (!empty($groups)): ?>
            <button class="btn btn-print" onclick="printGroups()">
                🖨️ Drucken
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="groups-container" data-print-date="<?= date('d.m.Y H:i') ?>">
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
                            Vollständig bewertet: <?= $group['examined_count'] ?>/<?= $group['student_count'] ?>
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
                        <div class="student-item <?= $student['is_complete'] ? 'completed' : 'not-completed' ?>">
                            <div class="student-info">
                                <?php if ($student['is_complete']): ?>
                                    <span class="completion-badge completed">✅</span>
                                <?php else: ?>
                                    <span class="completion-badge not-completed">❌</span>
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
                                    <span class="student-subject" style="background-color: <?= htmlspecialchars($student['subject_color'] ?: '#6366f1') ?>;">
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

            <div class="form-group">
                <label class="form-label" for="topicSelect">Thema auswählen *</label>
                <select class="form-control" id="topicSelect" onchange="updateTopicField()" <?= $edit_group ? '' : 'required' ?>>
                    <option value="">-- Thema wählen --</option>
                    <?php foreach ($topics as $topic): ?>
                        <option value="<?= htmlspecialchars($topic['title']) ?>" 
                                <?= ($edit_group && $edit_group['name'] == $topic['title']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($topic['title']) ?>
                            <?php if ($topic['teacher_id'] != $teacher_id): ?>
                                (<?= htmlspecialchars($topic['teacher_name']) ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="custom">➕ Eigenes Thema eingeben</option>
                </select>
            </div>

            <div class="form-group" id="customTopicGroup" style="display: none;">
                <label class="form-label" for="customTopic">Eigenes Thema *</label>
                <input type="text" class="form-control" id="customTopic" placeholder="Thema eingeben...">
                <div class="form-hint">Geben Sie ein eigenes Thema ein, wenn kein passendes vorhanden ist.</div>
            </div>

            <input type="hidden" name="topic" id="topicInput" value="<?= $edit_group ? htmlspecialchars($edit_group['name']) : '' ?>" required>

            <div class="form-group">
                <label class="form-label" for="classSelect">Klasse *</label>
                <select class="form-control" id="classSelect" name="class_id" onchange="loadStudents()" required <?= $edit_group ? 'disabled' : '' ?>>
                    <option value="">-- Klasse wählen --</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= $class['id'] ?>" 
                                data-students="<?= $class['available_students'] ?>"
                                <?= ($edit_group && $edit_group['class_id'] == $class['id']) || 
                                    (!$edit_group && $last_class_id == $class['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($class['name']) ?> 
                            (<?= $class['available_students'] ?> verfügbar von <?= $class['total_students'] ?> Schülern)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($edit_group): ?>
                    <input type="hidden" name="class_id" value="<?= $edit_group['class_id'] ?>">
                <?php endif; ?>
            </div>

            <?php if (!$edit_group): ?>
                <div class="form-group" id="studentSelectionGroup" style="display: none;">
                    <label class="form-label">Schüler auswählen (max. 4) *</label>
                    <div class="student-selection" id="studentList">
                        <!-- Wird dynamisch geladen -->
                    </div>
                    <div class="form-hint">Wählen Sie 1-4 Schüler für diese Gruppe aus.</div>
                </div>
            <?php endif; ?>

            <div class="form-group" id="assignmentGroup" style="<?= !$edit_group ? 'display: none;' : '' ?>">
                <label class="form-label">Prüfer und Fächer zuweisen</label>
                <div id="assignmentList">
                    <?php if ($edit_group): ?>
                        <?php foreach ($edit_group['students'] as $student): ?>
                            <div class="assignment-row">
                                <div class="student-label">
                                    <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                                </div>
                                <select name="examiner[<?= $student['student_id'] ?>]" class="form-control">
                                    <option value="">-- Prüfer --</option>
                                    <?php foreach ($teachers as $teach): ?>
                                        <option value="<?= $teach['id'] ?>" 
                                                <?= $student['examiner_teacher_id'] == $teach['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($teach['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="subject[<?= $student['student_id'] ?>]" class="form-control">
                                    <option value="">-- Fach --</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?= $subject['id'] ?>" 
                                                <?= $student['subject_id'] == $subject['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($subject['short_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary"><?= $edit_group ? 'Aktualisieren' : 'Gruppe erstellen' ?></button>
            </div>
        </form>
    </div>
</div>

<?php
// Schülerdaten für JavaScript vorbereiten - NUR Schüler die noch in KEINER Gruppe sind!
$students_by_class = [];
foreach ($classes as $class) {
    $stmt = $db->prepare("
        SELECT s.id, s.first_name, s.last_name
        FROM students s
        WHERE s.class_id = ? 
        AND s.school_id = ? 
        AND s.is_active = 1
        AND NOT EXISTS (
            SELECT 1 
            FROM group_students gs 
            WHERE gs.student_id = s.id
        )
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute([$class['id'], $school_id]);
    $students_by_class[$class['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Debug: Überprüfung der geladenen Schüler (kann nach Verifikation entfernt werden)
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    echo '<pre style="background: #f0f0f0; padding: 10px; margin: 20px; border: 1px solid #ccc;">';
    echo "=== DEBUG: Verfügbare Schüler pro Klasse ===\n\n";
    foreach ($students_by_class as $class_id => $students) {
        $class_name = '';
        foreach ($classes as $c) {
            if ($c['id'] == $class_id) {
                $class_name = $c['name'];
                break;
            }
        }
        echo "Klasse $class_name (ID: $class_id): " . count($students) . " verfügbare Schüler\n";
        foreach ($students as $s) {
            // Prüfen ob dieser Schüler wirklich in keiner Gruppe ist
            $check = $db->prepare("SELECT g.id, g.name FROM group_students gs JOIN groups g ON gs.group_id = g.id WHERE gs.student_id = ?");
            $check->execute([$s['id']]);
            $inGroup = $check->fetch();
            if ($inGroup) {
                echo "  ⚠️ FEHLER: {$s['first_name']} {$s['last_name']} (ID: {$s['id']}) ist bereits in Gruppe '{$inGroup['name']}' (ID: {$inGroup['id']})\n";
            } else {
                echo "  ✓ {$s['first_name']} {$s['last_name']} (ID: {$s['id']})\n";
            }
        }
        echo "\n";
    }
    echo '</pre>';
}
?>

<script>
// Schülerdaten als JavaScript-Variable
const studentsByClass = <?= json_encode($students_by_class) ?>;
let selectedStudents = [];

function printGroups() {
    const container = document.querySelector('.groups-container');
    if (container) {
        container.setAttribute('data-print-date', new Date().toLocaleDateString('de-DE') + ' ' + new Date().toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'}));
    }
    window.print();
}

function openCreateModal() {
    document.getElementById('groupForm').reset();
    selectedStudents = [];
    document.getElementById('studentSelectionGroup').style.display = 'none';
    document.getElementById('assignmentGroup').style.display = 'none';
    document.getElementById('groupModal').classList.add('show');
    
    // Modal nach oben scrollen
    document.getElementById('groupModal').scrollTop = 0;
    
    // Reset der Auswahl-Info
    const infoDiv = document.getElementById('selectionInfo');
    if (infoDiv) {
        infoDiv.textContent = 'Wählen Sie 1-4 Schüler aus (0 ausgewählt)';
        infoDiv.className = 'selection-info';
    }
    
    // Wenn letzte Klasse vorhanden, Schüler laden
    const classSelect = document.getElementById('classSelect');
    if (classSelect.value) {
        loadStudents();
    }
}

function closeModal() {
    <?php if ($edit_group): ?>
        window.location.href = '<?= $_SERVER['PHP_SELF'] ?>?page=gruppen';
    <?php else: ?>
        document.getElementById('groupModal').classList.remove('show');
    <?php endif; ?>
}

function updateTopicField() {
    const select = document.getElementById('topicSelect');
    const customGroup = document.getElementById('customTopicGroup');
    const topicInput = document.getElementById('topicInput');
    
    if (select.value === 'custom') {
        customGroup.style.display = 'block';
        document.getElementById('customTopic').required = true;
        topicInput.value = '';
    } else {
        customGroup.style.display = 'none';
        document.getElementById('customTopic').required = false;
        topicInput.value = select.value;
    }
}

document.getElementById('customTopic')?.addEventListener('input', function() {
    document.getElementById('topicInput').value = this.value;
});

function loadStudents() {
    const classId = document.getElementById('classSelect').value;
    if (!classId) {
        document.getElementById('studentSelectionGroup').style.display = 'none';
        return;
    }
    
    const studentList = document.getElementById('studentList');
    studentList.innerHTML = '';
    
    // Verwende die vorgeladenen Schülerdaten
    const students = studentsByClass[classId] || [];
    
    if (students.length === 0) {
        studentList.innerHTML = '<p style="text-align: center; color: #666; padding: 30px;">Keine freien Schüler in dieser Klasse. Alle Schüler sind bereits einer Gruppe zugeordnet.</p>';
    } else {
        // Grid-Container erstellen
        const gridContainer = document.createElement('div');
        gridContainer.className = 'students-grid';
        
        students.forEach((student, index) => {
            // Initialen generieren
            const initials = (student.first_name.charAt(0) + student.last_name.charAt(0)).toUpperCase();
            
            const card = document.createElement('div');
            card.className = 'student-card-select';
            card.id = `card_${student.id}`;
            card.innerHTML = `
                <input type="checkbox" 
                       id="student_${student.id}" 
                       name="students[]" 
                       value="${student.id}">
                <div class="selected-counter">${index + 1}</div>
                <div class="student-initials">${initials}</div>
                <div class="student-name-short">
                    ${student.first_name}<br>${student.last_name}
                </div>
            `;
            
            // Click-Handler für die gesamte Karte
            card.addEventListener('click', function() {
                const checkbox = this.querySelector('input[type="checkbox"]');
                const isCurrentlyChecked = checkbox.checked;
                
                if (!isCurrentlyChecked && selectedStudents.length >= 4) {
                    updateSelectionInfo('error', 'Maximal 4 Schüler pro Gruppe erlaubt!');
                    // Kurz rot aufblinken lassen
                    this.style.borderColor = '#ef4444';
                    setTimeout(() => {
                        this.style.borderColor = '';
                    }, 300);
                    return;
                }
                
                checkbox.checked = !isCurrentlyChecked;
                toggleStudent(student.id, `${student.first_name} ${student.last_name}`);
                
                // Karte visuell aktualisieren
                if (checkbox.checked) {
                    this.classList.add('selected');
                } else {
                    this.classList.remove('selected');
                }
                
                // Nummerierung aktualisieren
                updateSelectionNumbers();
            });
            
            gridContainer.appendChild(card);
        });
        
        studentList.appendChild(gridContainer);
        
        // Info-Bereich hinzufügen
        const infoDiv = document.createElement('div');
        infoDiv.className = 'selection-info';
        infoDiv.id = 'selectionInfo';
        infoDiv.innerHTML = `Wählen Sie 1-4 Schüler aus (0 ausgewählt)`;
        studentList.appendChild(infoDiv);
    }
    
    document.getElementById('studentSelectionGroup').style.display = 'block';
}

function toggleStudent(studentId, studentName) {
    const checkbox = document.getElementById(`student_${studentId}`);
    const card = document.getElementById(`card_${studentId}`);
    
    if (checkbox.checked) {
        selectedStudents.push({id: studentId, name: studentName});
        card.classList.add('selected');
    } else {
        selectedStudents = selectedStudents.filter(s => s.id !== studentId);
        card.classList.remove('selected');
    }
    
    updateAssignmentList();
    updateSelectionInfo();
    updateSelectionNumbers();
}

function updateSelectionNumbers() {
    // Alle ausgewählten Karten finden und neu nummerieren
    const selectedCards = document.querySelectorAll('.student-card-select.selected');
    selectedCards.forEach((card, index) => {
        const counter = card.querySelector('.selected-counter');
        if (counter) {
            counter.textContent = index + 1;
        }
    });
}

function updateSelectionInfo(type = 'normal', customMessage = null) {
    const infoDiv = document.getElementById('selectionInfo');
    if (!infoDiv) return;
    
    if (customMessage) {
        infoDiv.textContent = customMessage;
        infoDiv.className = `selection-info ${type}`;
        setTimeout(() => {
            updateSelectionInfo();
        }, 3000);
        return;
    }
    
    const count = selectedStudents.length;
    let message = `Wählen Sie 1-4 Schüler aus (${count} ausgewählt)`;
    let className = 'selection-info';
    
    if (count === 0) {
        className = 'selection-info';
    } else if (count === 4) {
        message = `Maximum erreicht! (${count} von 4 ausgewählt)`;
        className = 'selection-info warning';
    } else if (count >= 1 && count < 4) {
        message = `${count} von 4 Schülern ausgewählt`;
        className = 'selection-info';
    }
    
    infoDiv.textContent = message;
    infoDiv.className = className;
}

function updateAssignmentList() {
    const assignmentList = document.getElementById('assignmentList');
    assignmentList.innerHTML = '';
    
    if (selectedStudents.length > 0) {
        selectedStudents.forEach(student => {
            const row = document.createElement('div');
            row.className = 'assignment-row';
            row.innerHTML = `
                <div class="student-label">${student.name}</div>
                <select name="examiner[${student.id}]" class="form-control">
                    <option value="">-- Prüfer --</option>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="subject[${student.id}]" class="form-control">
                    <option value="">-- Fach --</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?= $subject['id'] ?>"><?= htmlspecialchars($subject['short_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            `;
            assignmentList.appendChild(row);
        });
        
        document.getElementById('assignmentGroup').style.display = 'block';
    } else {
        document.getElementById('assignmentGroup').style.display = 'none';
    }
}

// Flash Messages automatisch ausblenden
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        document.querySelectorAll('.flash-message').forEach(msg => {
            msg.style.transition = 'opacity 0.3s';
            msg.style.opacity = '0';
            setTimeout(() => msg.remove(), 300);
        });
    }, 5000);
    
    // ESC Key Handler
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('groupModal');
            if (modal && modal.classList.contains('show')) {
                closeModal();
            }
        }
    });
    
    // Click outside modal handler
    document.getElementById('groupModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
    
    // Wenn Klasse vorausgewählt ist, Schüler laden
    <?php if (!$edit_group && $last_class_id): ?>
        setTimeout(() => {
            const classSelect = document.getElementById('classSelect');
            if (classSelect && classSelect.value) {
                loadStudents();
            }
        }, 100);
    <?php endif; ?>
    
    // Modal beim Bearbeiten nach oben scrollen
    <?php if ($edit_group): ?>
        document.getElementById('groupModal').scrollTop = 0;
    <?php endif; ?>
    
    // Druckfunktion mit aktuellem Datum
    const printButton = document.querySelector('.btn-print');
    if (printButton) {
        printButton.addEventListener('click', function(e) {
            e.preventDefault();
            const container = document.querySelector('.groups-container');
            if (container) {
                container.setAttribute('data-print-date', new Date().toLocaleDateString('de-DE') + ' ' + new Date().toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'}));
            }
            window.print();
        });
    }
});
</script>