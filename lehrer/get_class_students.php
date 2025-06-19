<?php
require_once '../config.php';

// Lehrer-Zugriff prüfen
if (!isLoggedIn() || $_SESSION['user_type'] !== 'lehrer') {
    http_response_code(403);
    echo json_encode(['error' => 'Keine Berechtigung']);
    exit();
}

$class_id = (int)($_GET['class_id'] ?? 0);

if ($class_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Klassen-ID']);
    exit();
}

$db = getDB();

// Prüfe ob Klasse zur Schule gehört
$stmt = $db->prepare("SELECT id FROM classes WHERE id = ? AND school_id = ? AND is_active = 1");
$stmt->execute([$class_id, $_SESSION['school_id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Klasse nicht gefunden']);
    exit();
}

// Schüler der Klasse abrufen
$stmt = $db->prepare("
    SELECT s.id, s.first_name, s.last_name,
           CASE WHEN gs.id IS NOT NULL THEN 1 ELSE 0 END as has_group,
           g.name as group_name
    FROM students s
    LEFT JOIN group_students gs ON s.id = gs.student_id
    LEFT JOIN groups g ON gs.group_id = g.id AND g.is_active = 1
    WHERE s.class_id = ? AND s.is_active = 1
    ORDER BY s.last_name, s.first_name
");
$stmt->execute([$class_id]);
$students = $stmt->fetchAll();

$result = ['students' => []];
foreach ($students as $student) {
    $result['students'][] = [
        'id' => $student['id'],
        'name' => $student['first_name'] . ' ' . $student['last_name'],
        'has_group' => (bool)$student['has_group'],
        'group_name' => $student['group_name']
    ];
}

header('Content-Type: application/json');
echo json_encode($result);