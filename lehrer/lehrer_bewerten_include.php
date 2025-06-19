<?php
// Diese Datei wird von dashboard.php eingebunden
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

// Bewertungsmodus prüfen
$rating_mode = false;
$current_rating = null;
$student_id = null;
$group_id = null;
$active_tab = $_GET['tab'] ?? 'rating'; // Standardmäßig Bewertung-Tab

if (isset($_GET['rate']) && isset($_GET['group'])) {
    $rating_mode = true;
    $student_id = (int)$_GET['rate'];
    $group_id = (int)$_GET['group'];
    
    // Prüfen ob der Lehrer berechtigt ist
    $stmt = $db->prepare("
        SELECT gs.*, s.first_name, s.last_name, g.name as group_name,
               subj.short_name as subject_short, subj.full_name as subject_full
        FROM group_students gs
        JOIN students s ON gs.student_id = s.id
        JOIN groups g ON gs.group_id = g.id
        LEFT JOIN subjects subj ON gs.subject_id = subj.id
        WHERE gs.student_id = ? AND gs.group_id = ? AND gs.examiner_teacher_id = ?
    ");
    $stmt->execute([$student_id, $group_id, $teacher_id]);
    $student_info = $stmt->fetch();
    
    if (!$student_info) {
        $_SESSION['flash_message'] = 'Sie sind nicht berechtigt, diesen Schüler zu bewerten.';
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?page=bewerten');
        exit();
    }
    
    // Existierende Bewertung laden falls vorhanden
    $stmt = $db->prepare("
        SELECT r.*, rt.name as template_name
        FROM ratings r
        LEFT JOIN rating_templates rt ON r.template_id = rt.id
        WHERE r.student_id = ? AND r.group_id = ? AND r.teacher_id = ?
    ");
    $stmt->execute([$student_id, $group_id, $teacher_id]);
    $current_rating = $stmt->fetch();
    
    // Stärken-Kategorien und Items für die Schule laden
    if ($active_tab === 'strengths') {
        // Kategorien laden
        $stmt = $db->prepare("
            SELECT sc.*
            FROM strength_categories sc
            WHERE sc.school_id = ? AND sc.is_active = 1
            ORDER BY sc.display_order
        ");
        $stmt->execute([$school_id]);
        $strength_categories = $stmt->fetchAll();
        
        // Für jede Kategorie die Items laden (vermeidet GROUP_CONCAT Limits)
        foreach ($strength_categories as &$category) {
            $stmt = $db->prepare("
                SELECT si.*, 
                       CASE WHEN rs.id IS NOT NULL THEN 1 ELSE 0 END as selected
                FROM strength_items si
                LEFT JOIN rating_strengths rs ON rs.strength_item_id = si.id 
                    AND rs.rating_id = ?
                WHERE si.category_id = ? AND si.is_active = 1
                ORDER BY si.display_order
            ");
            $stmt->execute([$current_rating ? $current_rating['id'] : 0, $category['id']]);
            $category['items'] = $stmt->fetchAll();
        }
        unset($category); // Referenz aufheben
    }
}

// Filter und Sortierung
$status_filter = $_GET['status'] ?? 'all';
$sort_by = $_GET['sort'] ?? 'name';

$order_clause = "ORDER BY s.last_name, s.first_name";
if ($sort_by === 'status') {
    $order_clause = "ORDER BY r.id IS NULL, s.last_name, s.first_name";
} elseif ($sort_by === 'date') {
    $order_clause = "ORDER BY r.rating_date DESC, s.last_name, s.first_name";
}

// Schüler des Lehrers abrufen
$query = "
    SELECT gs.*, s.first_name, s.last_name, g.name as group_name,
           subj.short_name as subject_short, subj.full_name as subject_full,
           r.id as rating_id, r.final_grade, r.rating_date, r.is_complete,
           rt.name as template_name
    FROM group_students gs
    JOIN students s ON gs.student_id = s.id
    JOIN groups g ON gs.group_id = g.id AND g.is_active = 1
    LEFT JOIN subjects subj ON gs.subject_id = subj.id
    LEFT JOIN ratings r ON r.student_id = gs.student_id 
        AND r.group_id = gs.group_id 
        AND r.teacher_id = gs.examiner_teacher_id
    LEFT JOIN rating_templates rt ON r.template_id = rt.id
    WHERE gs.examiner_teacher_id = ? AND g.school_id = ?
";

if ($status_filter === 'rated') {
    $query .= " AND r.id IS NOT NULL";
} elseif ($status_filter === 'unrated') {
    $query .= " AND r.id IS NULL";
}

$query .= " " . $order_clause;

$stmt = $db->prepare($query);
$stmt->execute([$teacher_id, $school_id]);
$students = $stmt->fetchAll();

// Letzte verwendete Vorlage des Lehrers abrufen
$stmt = $db->prepare("
    SELECT template_id 
    FROM ratings 
    WHERE teacher_id = ? 
    ORDER BY created_at DESC 
    LIMIT 1
");
$stmt->execute([$teacher_id]);
$last_template_id = $stmt->fetchColumn();

// Verfügbare Bewertungsvorlagen abrufen
$stmt = $db->prepare("
    SELECT * FROM rating_templates 
    WHERE teacher_id = ? AND is_active = 1 
    ORDER BY is_standard DESC, name
");
$stmt->execute([$teacher_id]);
$templates = $stmt->fetchAll();

// Standard-Template ID finden
$standard_template_id = null;
foreach ($templates as $template) {
    if ($template['is_standard']) {
        $standard_template_id = $template['id'];
        break;
    }
}

// Wenn keine letzte Vorlage, nutze Standard
if (!$last_template_id) {
    $last_template_id = $standard_template_id;
}
?>

<style>
/* Spezifische Styles für Bewerten-Modul */
.rating-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 20px;
    background: rgba(255, 255, 255, 0.9);
    border-radius: 15px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.rating-title {
    font-size: 28px;
    color: #002b45;
    font-weight: 700;
}

.filter-controls {
    display: flex;
    gap: 15px;
    align-items: center;
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

.select-control:focus {
    outline: none;
    border-color: #ff9900;
    box-shadow: 0 0 0 3px rgba(255, 153, 0, 0.1);
}

.students-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.student-card {
    background: rgba(255, 255, 255, 0.95);
    border: 2px solid transparent;
    border-radius: 15px;
    padding: 25px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.student-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
    border-color: #ff9900;
}

.student-row {
    display: flex;
    align-items: center;
    gap: 20px;
}

.student-info {
    flex: 1;
}

.student-name {
    font-size: 20px;
    font-weight: 700;
    color: #002b45;
    margin-bottom: 5px;
}

.student-subject {
    font-size: 14px;
    color: #666;
}

.student-theme {
    font-size: 14px;
    color: #666;
    margin-top: 3px;
}

.student-status {
    display: flex;
    align-items: center;
    gap: 10px;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}

.status-badge.rated {
    background: rgba(34, 197, 94, 0.1);
    color: #15803d;
}

.status-badge.unrated {
    background: rgba(239, 68, 68, 0.1);
    color: #dc2626;
}

.grade-display {
    font-size: 24px;
    font-weight: 700;
    color: #002b45;
}

.rating-date {
    font-size: 12px;
    color: #666;
}

.template-select {
    width: 200px;
    padding: 8px 12px;
    background: rgba(255, 255, 255, 0.9);
    border: 2px solid rgba(0, 43, 69, 0.2);
    border-radius: 8px;
    color: #002b45;
    font-size: 14px;
}

.action-buttons {
    display: flex;
    gap: 10px;
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
    background: #6366f1;
    color: white;
}

.btn-primary:hover {
    background: #4f46e5;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3);
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

.btn-sm {
    padding: 8px 16px;
    font-size: 12px;
}

/* Rating Modal Styles */
.rating-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(to bottom, #999999 0%, #ff9900 100%);
    z-index: 1000;
    overflow-y: auto;
}

.rating-modal-content {
    max-width: 1200px;
    margin: 20px auto;
    padding: 20px;
}

.rating-modal-header {
    background: rgba(255, 255, 255, 0.95);
    padding: 20px 30px;
    border-radius: 15px;
    margin-bottom: 20px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.rating-modal-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.back-button {
    background: rgba(255, 255, 255, 0.9);
    color: #002b45;
    padding: 10px 20px;
    border: none;
    border-radius: 999px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
}

.back-button:hover {
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.rating-tabs {
    display: flex;
    gap: 10px;
    background: rgba(0, 43, 69, 0.1);
    padding: 4px;
    border-radius: 12px;
}

.tab-button {
    padding: 10px 20px;
    background: transparent;
    border: none;
    color: #002b45;
    cursor: pointer;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.tab-button.active {
    background: #6366f1;
    color: white;
}

.tab-button:hover:not(.active) {
    background: rgba(0, 43, 69, 0.1);
}

.tab-button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.student-info-header {
    text-align: center;
}

.student-info-name {
    font-size: 24px;
    font-weight: 700;
    color: #002b45;
    margin-bottom: 5px;
}

.student-info-details {
    font-size: 16px;
    color: #666;
}

.average-display {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: rgba(67, 83, 106, 0.95);
    color: white;
    padding: 15px 20px;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
    z-index: 100;
    min-width: 160px;
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
}

.average-display:hover {
    transform: scale(1.05);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
}

.average-label {
    font-size: 12px;
    opacity: 0.8;
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.average-value {
    font-size: 24px;
    font-weight: 700;
    display: flex;
    align-items: baseline;
    gap: 8px;
}

.average-value.empty {
    font-size: 20px;
}

.average-value small {
    font-size: 14px;
    opacity: 0.7;
}

.template-info {
    background: #6366f1;
    color: white;
    padding: 20px;
    border-radius: 12px;
    text-align: center;
    margin-bottom: 20px;
    font-size: 18px;
    font-weight: 600;
}

.rating-form {
    background: rgba(255, 255, 255, 0.95);
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.final-grade-section {
    background: linear-gradient(135deg, #43536a 0%, #536179 100%);
    color: white;
    padding: 20px 25px;
    border-radius: 12px;
    margin-bottom: 30px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.final-grade-row {
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.final-grade-label {
    font-size: 16px;
    font-weight: 600;
    min-width: 80px;
}

.final-grade-input {
    width: 80px;
    padding: 10px;
    background: white;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    color: #002b45;
    font-size: 16px;
    font-weight: 600;
    text-align: center;
}

.final-grade-input:focus {
    outline: none;
    border-color: #ff9900;
    box-shadow: 0 0 0 3px rgba(255, 153, 0, 0.3);
}

.btn-takeover {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 2px solid rgba(255, 255, 255, 0.5);
    padding: 10px 16px;
    font-size: 14px;
}

.btn-takeover:hover {
    background: rgba(255, 255, 255, 0.3);
    border-color: white;
}

.average-indicator {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    margin-left: auto;
    font-size: 14px;
}

.average-indicator strong {
    font-size: 18px;
}

.category-section {
    background: linear-gradient(135deg, #43536a 0%, #536179 100%);
    padding: 20px 25px;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.category-header {
    color: white;
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.grade-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.grade-button {
    width: 50px;
    height: 50px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    color: white;
}

.grade-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    border-color: rgba(255, 255, 255, 0.6);
}

.grade-button.selected {
    border-color: white;
    box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.3);
    transform: scale(1.1);
}

/* Farben für Noten */
.grade-1, .grade-1-5 { background: #22c55e; }
.grade-2, .grade-2-5 { background: #f97316; }
.grade-3, .grade-3-5 { background: #eab308; }
.grade-4, .grade-4-5 { background: #ef4444; }
.grade-5, .grade-5-5 { background: #991b1b; }
.grade-6 { background: #450a0a; }

.grade-button.reset {
    background: #6b7280;
    width: auto;
    padding: 0 20px;
}

.form-actions {
    display: flex;
    gap: 15px;
    justify-content: center;
    margin-top: 30px;
}

.btn-save {
    background: #22c55e;
    color: white;
}

.btn-save:hover {
    background: #16a34a;
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

/* Stärken Tab Styles */
.strengths-form {
    background: rgba(255, 255, 255, 0.95);
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.strength-category {
    background: linear-gradient(135deg, #43536a 0%, #536179 100%);
    padding: 20px 25px;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.strength-category-header {
    color: white;
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.strength-category-icon {
    font-size: 24px;
}

.strength-items {
    display: grid;
    gap: 10px;
}

.strength-item {
    background: rgba(255, 255, 255, 0.1);
    padding: 10px 15px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s ease;
}

.strength-item:hover {
    background: rgba(255, 255, 255, 0.2);
}

.strength-checkbox {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: #ff9900;
}

.strength-label {
    color: white;
    flex: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
}

.strength-name {
    font-weight: 500;
}

.strength-description {
    font-size: 14px;
    opacity: 0.8;
}

.strength-stats {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: rgba(67, 83, 106, 0.95);
    color: white;
    padding: 15px 20px;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
    z-index: 100;
    min-width: 180px;
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
}

.strength-stats:hover {
    transform: scale(1.05);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
}

.strength-stats-label {
    font-size: 12px;
    opacity: 0.8;
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.strength-stats-value {
    font-size: 24px;
    font-weight: 700;
    display: flex;
    align-items: baseline;
    gap: 8px;
}

.strength-stats-value small {
    font-size: 14px;
    opacity: 0.7;
}

.no-strengths-message {
    text-align: center;
    padding: 40px;
    background: rgba(255, 153, 0, 0.1);
    border: 2px dashed rgba(255, 153, 0, 0.3);
    border-radius: 12px;
    color: #002b45;
}

.no-strengths-message h3 {
    color: #ff9900;
    margin-bottom: 10px;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .rating-header {
        flex-direction: column;
        gap: 15px;
    }

    .filter-controls {
        flex-direction: column;
        width: 100%;
    }

    .student-row {
        flex-direction: column;
        text-align: center;
    }

    .student-status {
        flex-direction: column;
    }

    .action-buttons {
        flex-direction: column;
        width: 100%;
    }

    .btn {
        width: 100%;
        justify-content: center;
    }

    .average-display,
    .strength-stats {
        position: fixed;
        bottom: 10px;
        right: 10px;
        left: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        min-width: auto;
    }

    .average-value,
    .strength-stats-value {
        flex-direction: row;
    }

    .final-grade-row {
        flex-direction: column;
        align-items: stretch;
    }

    .final-grade-input {
        width: 100%;
    }

    .grade-buttons {
        justify-content: center;
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

<?php if (!$rating_mode): ?>
    <!-- Übersichtsansicht -->
    <div class="rating-header">
        <h1 class="rating-title">⭐ Schüler bewerten</h1>
        
        <div class="filter-controls">
            <label style="font-weight: 600; color: #002b45;">Filter Status:</label>
            <select class="select-control" onchange="changeFilter(this.value)">
                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>Alle</option>
                <option value="rated" <?= $status_filter === 'rated' ? 'selected' : '' ?>>Bewertet</option>
                <option value="unrated" <?= $status_filter === 'unrated' ? 'selected' : '' ?>>Nicht bewertet</option>
            </select>
            
            <label style="font-weight: 600; color: #002b45;">Namen sortieren:</label>
            <select class="select-control" onchange="changeSort(this.value)">
                <option value="name" <?= $sort_by === 'name' ? 'selected' : '' ?>>A-Z</option>
                <option value="status" <?= $sort_by === 'status' ? 'selected' : '' ?>>Nach Status</option>
                <option value="date" <?= $sort_by === 'date' ? 'selected' : '' ?>>Nach Datum</option>
            </select>
        </div>
    </div>

    <div class="students-list">
        <?php if (empty($students)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📚</div>
                <h3>Keine Schüler zur Bewertung</h3>
                <p>Ihnen wurden noch keine Schüler zur Bewertung zugewiesen.</p>
            </div>
        <?php else: ?>
            <?php foreach ($students as $student): ?>
                <div class="student-card">
                    <div class="student-row">
                        <div class="student-info">
                            <div class="student-name">
                                <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                                <?php if ($student['subject_short']): ?>
                                    <span style="font-size: 14px; color: #666;">
                                        (<?= htmlspecialchars($student['subject_full']) ?>)
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="student-theme">
                                Thema: <?= htmlspecialchars($student['group_name']) ?>
                            </div>
                        </div>
                        
                        <div class="student-status">
                            <span class="status-badge <?= $student['rating_id'] ? 'rated' : 'unrated' ?>">
                                <?= $student['rating_id'] ? 'Bewertet' : 'Noch nicht bewertet' ?>
                            </span>
                            
                            <?php if ($student['rating_id'] && $student['final_grade']): ?>
                                <div style="text-align: center;">
                                    <div class="grade-display">
                                        Note: <?= number_format($student['final_grade'], 1, ',', '') ?>
                                    </div>
                                    <div class="rating-date">
                                        Bewertet am: <?= date('d.m.Y', strtotime($student['rating_date'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="action-buttons">
                            <form method="GET" style="display: flex; gap: 10px; align-items: center;">
                                <input type="hidden" name="page" value="bewerten">
                                <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                <input type="hidden" name="group_id" value="<?= $student['group_id'] ?>">
                                
                                <select name="template_id" class="template-select" 
                                        data-student="<?= $student['student_id'] ?>"
                                        data-group="<?= $student['group_id'] ?>">
                                    <?php foreach ($templates as $template): ?>
                                        <option value="<?= $template['id'] ?>" 
                                            <?= ($student['rating_id'] && $student['template_name'] == $template['name']) || 
                                                (!$student['rating_id'] && $template['id'] == $last_template_id) ? 
                                                'selected' : '' ?>>
                                            <?= htmlspecialchars($template['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <a href="?page=bewerten&rate=<?= $student['student_id'] ?>&group=<?= $student['group_id'] ?>" 
                                   class="btn btn-primary btn-sm"
                                   onclick="saveTemplateSelection(<?= $student['student_id'] ?>, <?= $student['group_id'] ?>)">
                                    <?= $student['rating_id'] ? 'Bewertung bearbeiten' : 'Bewerten' ?>
                                </a>
                                
                                <?php if ($student['rating_id'] && $student['is_complete']): ?>
                                    <a href="generate_pdf.php?rating=<?= $student['rating_id'] ?>" 
                                       class="btn btn-danger btn-sm" target="_blank">
                                        PDF
                                    </a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- Bewertungsansicht -->
    <?php
    // Template-ID bestimmen
    $template_id = $_GET['template'] ?? $current_rating['template_id'] ?? $last_template_id;
    
    // Template-Kategorien laden
    $stmt = $db->prepare("
        SELECT * FROM rating_template_categories 
        WHERE template_id = ? 
        ORDER BY display_order, id
    ");
    $stmt->execute([$template_id]);
    $categories = $stmt->fetchAll();
    
    // Existierende Kategorie-Bewertungen laden
    $category_ratings = [];
    if ($current_rating) {
        $stmt = $db->prepare("
            SELECT category_id, points 
            FROM rating_categories 
            WHERE rating_id = ?
        ");
        $stmt->execute([$current_rating['id']]);
        while ($row = $stmt->fetch()) {
            $category_ratings[$row['category_id']] = $row['points'];
        }
    }
    
    // Durchschnitt berechnen
    $average = null;
    if (!empty($category_ratings)) {
        $total_weight = 0;
        $weighted_sum = 0;
        
        foreach ($categories as $category) {
            if (isset($category_ratings[$category['id']])) {
                $weighted_sum += $category_ratings[$category['id']] * $category['weight'];
                $total_weight += $category['weight'];
            }
        }
        
        if ($total_weight > 0) {
            $average = round($weighted_sum / $total_weight, 1);
        }
    }
    
    // Template-Name abrufen
    $stmt = $db->prepare("SELECT name FROM rating_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $template_name = $stmt->fetchColumn();
    ?>
    
    <div class="rating-modal">
        <div class="rating-modal-content">
            <div class="rating-modal-header">
                <div class="rating-modal-nav">
                    <button class="back-button" onclick="window.location.href='?page=bewerten'">
                        ← Zurück zur Übersicht
                    </button>
                    
                    <div class="rating-tabs">
                        <button class="tab-button <?= $active_tab === 'rating' ? 'active' : '' ?>" 
                                onclick="switchTab('rating')"
                                <?= !$current_rating ? 'title="Bitte speichern Sie zuerst die Bewertung"' : '' ?>>
                            Bewertung
                        </button>
                        <button class="tab-button <?= $active_tab === 'strengths' ? 'active' : '' ?>" 
                                onclick="switchTab('strengths')"
                                <?= !$current_rating ? 'disabled title="Bitte speichern Sie zuerst die Bewertung"' : '' ?>>
                            Stärken
                        </button>
                    </div>
                </div>
                
                <div class="student-info-header">
                    <h2 class="student-info-name">
                        <?= $active_tab === 'rating' ? 'Bewertung' : 'Stärken' ?>: <?= htmlspecialchars($student_info['first_name'] . ' ' . $student_info['last_name']) ?>
                    </h2>
                    <div class="student-info-details">
                        Thema: <?= htmlspecialchars($student_info['group_name']) ?>
                    </div>
                </div>
            </div>
            
            <?php if ($active_tab === 'rating'): ?>
                <!-- Bewertungs-Tab -->
                <div class="average-display" id="averageDisplay">
                    <div class="average-label">Durchschnitt</div>
                    <div class="average-value <?= $average === null ? 'empty' : '' ?>" id="averageValue">
                        <?= $average !== null ? number_format($average, 1, ',', '') : '-' ?>
                        <?php if ($average !== null): ?>
                            <small>Note</small>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="template-info">
                    Bewertungsvorlage: <?= htmlspecialchars($template_name) ?>
                </div>
                
                <form method="POST" class="rating-form" id="ratingForm">
                    <input type="hidden" name="form_action" value="save_rating">
                    <input type="hidden" name="student_id" value="<?= $student_id ?>">
                    <input type="hidden" name="group_id" value="<?= $group_id ?>">
                    <input type="hidden" name="template_id" value="<?= $template_id ?>">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="final-grade-section">
                        <div class="final-grade-row">
                            <label class="final-grade-label">Endnote:</label>
                            <input type="number" 
                                   name="final_grade" 
                                   id="finalGrade"
                                   class="final-grade-input" 
                                   min="1" 
                                   max="6" 
                                   step="0.1" 
                                   value="<?= $current_rating ? number_format($current_rating['final_grade'], 1, '.', '') : '' ?>"
                                   placeholder="-">
                            <button type="button" class="btn btn-takeover" onclick="takeOverAverage()">
                                Durchschnitt übernehmen
                            </button>
                        </div>
                    </div>
                    
                    <?php foreach ($categories as $category): ?>
                        <div class="category-section">
                            <div class="category-header">
                                <?= htmlspecialchars($category['name']) ?> (<?= $category['weight'] ?>%)
                            </div>
                            
                            <div class="grade-buttons">
                                <?php 
                                $grades = [1, 1.5, 2, 2.5, 3, 3.5, 4, 4.5, 5, 5.5, 6];
                                foreach ($grades as $grade): 
                                    $isSelected = isset($category_ratings[$category['id']]) && 
                                                $category_ratings[$category['id']] == $grade;
                                ?>
                                    <button type="button" 
                                            class="grade-button grade-<?= str_replace('.', '-', $grade) ?> <?= $isSelected ? 'selected' : '' ?>"
                                            onclick="selectGrade(<?= $category['id'] ?>, <?= $grade ?>)">
                                        <?= number_format($grade, 1, ',', '') ?>
                                    </button>
                                <?php endforeach; ?>
                                
                                <button type="button" 
                                        class="grade-button reset"
                                        onclick="resetGrade(<?= $category['id'] ?>)">
                                    —
                                </button>
                            </div>
                            
                            <input type="hidden" 
                                   name="category_rating[<?= $category['id'] ?>]" 
                                   id="category_<?= $category['id'] ?>"
                                   value="<?= $category_ratings[$category['id']] ?? '' ?>">
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="form-actions">
                        <button type="submit" name="save_and_continue" class="btn btn-save">
                            Speichern
                        </button>
                        <button type="submit" name="save_and_close" class="btn btn-save">
                            Speichern und schließen
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='?page=bewerten'">
                            Abbrechen
                        </button>
                    </div>
                </form>
                
            <?php else: ?>
                <!-- Stärken-Tab -->
                <div class="strength-stats" id="strengthStats">
                    <div class="strength-stats-label">Ausgewählte Stärken</div>
                    <div class="strength-stats-value" id="strengthCount">
                        <span id="selectedCount">0</span>
                        <small>Stärken</small>
                    </div>
                </div>
                
                <?php if (!empty($strength_categories)): ?>
                    <form method="POST" class="strengths-form" id="strengthsForm">
                        <input type="hidden" name="form_action" value="save_strengths">
                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                        <input type="hidden" name="group_id" value="<?= $group_id ?>">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        
                        <?php foreach ($strength_categories as $category): ?>
                            <div class="strength-category">
                                <div class="strength-category-header">
                                    <span class="strength-category-icon"><?= htmlspecialchars($category['icon'] ?: '📌') ?></span>
                                    <?= htmlspecialchars($category['name']) ?>
                                </div>
                                
                                <div class="strength-items">
                                    <?php foreach ($category['items'] as $item): ?>
                                        <div class="strength-item">
                                            <input type="checkbox" 
                                                   id="strength_<?= $item['id'] ?>"
                                                   name="strength_items[]" 
                                                   value="<?= $item['id'] ?>"
                                                   class="strength-checkbox"
                                                   <?= $item['selected'] ? 'checked' : '' ?>
                                                   onchange="updateStrengthCount()">
                                            <label for="strength_<?= $item['id'] ?>" class="strength-label">
                                                <span class="strength-name"><?= htmlspecialchars($item['name']) ?></span>
                                                <?php if ($item['description']): ?>
                                                    <span class="strength-description">(<?= htmlspecialchars($item['description']) ?>)</span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="form-actions">
                            <button type="submit" name="save_and_continue" class="btn btn-save">
                                Stärken speichern
                            </button>
                            <button type="submit" name="save_and_close" class="btn btn-save">
                                Speichern und schließen
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='?page=bewerten'">
                                Abbrechen
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="no-strengths-message">
                        <h3>Keine Stärken definiert</h3>
                        <p>Die Schule hat noch keine Stärkenkategorien definiert. 
                           Bitte kontaktieren Sie den Schuladministrator.</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
// Kategorien-Gewichtungen für Durchschnittsberechnung
const categoryWeights = <?= json_encode(array_column($categories ?? [], 'weight', 'id')) ?>;

function changeFilter(value) {
    const searchParams = new URLSearchParams(window.location.search);
    searchParams.set('page', 'bewerten');
    searchParams.set('status', value);
    window.location.href = window.location.pathname + '?' + searchParams.toString();
}

function changeSort(value) {
    const searchParams = new URLSearchParams(window.location.search);
    searchParams.set('page', 'bewerten');
    searchParams.set('sort', value);
    window.location.href = window.location.pathname + '?' + searchParams.toString();
}

function saveTemplateSelection(studentId, groupId) {
    // Template-Auswahl wird über URL-Parameter weitergegeben
    const select = document.querySelector(`select[data-student="${studentId}"][data-group="${groupId}"]`);
    if (select) {
        const templateId = select.value;
        const currentHref = event.target.href;
        event.preventDefault();
        window.location.href = currentHref + '&template=' + templateId;
    }
}

function switchTab(tab) {
    const searchParams = new URLSearchParams(window.location.search);
    searchParams.set('tab', tab);
    window.location.href = window.location.pathname + '?' + searchParams.toString();
}

function selectGrade(categoryId, grade) {
    // Alle Buttons dieser Kategorie deselektieren
    document.querySelectorAll(`button[onclick^="selectGrade(${categoryId},"]`).forEach(btn => {
        btn.classList.remove('selected');
    });
    
    // Ausgewählten Button markieren
    event.target.classList.add('selected');
    
    // Wert setzen
    document.getElementById(`category_${categoryId}`).value = grade;
    
    // Durchschnitt neu berechnen
    calculateAverage();
}

function resetGrade(categoryId) {
    // Alle Buttons dieser Kategorie deselektieren
    document.querySelectorAll(`button[onclick^="selectGrade(${categoryId},"]`).forEach(btn => {
        btn.classList.remove('selected');
    });
    
    // Wert löschen
    document.getElementById(`category_${categoryId}`).value = '';
    
    // Durchschnitt neu berechnen
    calculateAverage();
}

function calculateAverage() {
    let totalWeight = 0;
    let weightedSum = 0;
    
    for (const [categoryId, weight] of Object.entries(categoryWeights)) {
        const input = document.getElementById(`category_${categoryId}`);
        if (input && input.value) {
            const grade = parseFloat(input.value);
            weightedSum += grade * weight;
            totalWeight += weight;
        }
    }
    
    const averageDisplay = document.getElementById('averageValue');
    const averageContainer = document.getElementById('averageDisplay');
    
    if (totalWeight > 0) {
        const average = (weightedSum / totalWeight).toFixed(1);
        averageDisplay.innerHTML = average.replace('.', ',') + ' <small>Note</small>';
        averageDisplay.classList.remove('empty');
        
        // Animation für Änderung
        averageContainer.style.transform = 'scale(1.1)';
        setTimeout(() => {
            averageContainer.style.transform = 'scale(1)';
        }, 200);
    } else {
        averageDisplay.textContent = '-';
        averageDisplay.classList.add('empty');
    }
}

function takeOverAverage() {
    const averageText = document.getElementById('averageValue').textContent;
    const averageMatch = averageText.match(/[\d,]+/);
    if (averageMatch && averageMatch[0] !== '-') {
        document.getElementById('finalGrade').value = averageMatch[0].replace(',', '.');
    }
}

function updateStrengthCount() {
    const checkedCount = document.querySelectorAll('.strength-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = checkedCount;
    
    // Animation
    const statsContainer = document.getElementById('strengthStats');
    statsContainer.style.transform = 'scale(1.1)';
    setTimeout(() => {
        statsContainer.style.transform = 'scale(1)';
    }, 200);
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
    
    // Stärken-Anzahl initialisieren
    if (document.getElementById('strengthStats')) {
        updateStrengthCount();
    }
});
</script>