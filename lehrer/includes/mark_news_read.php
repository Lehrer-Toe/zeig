<?php
session_start();
header('Content-Type: application/json');

// Sicherheitsprüfung
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'lehrer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

// Datenbankverbindung einbinden (relativer Pfad anpassen je nach Verzeichnisstruktur)
require_once '../../config/database.php';

// JSON-Daten empfangen
$input = json_decode(file_get_contents('php://input'), true);
$news_id = $input['news_id'] ?? null;

if (!$news_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'News ID fehlt']);
    exit;
}

try {
    // Prüfen ob bereits als gelesen markiert
    $stmt = $pdo->prepare("
        SELECT id FROM news_read_status 
        WHERE news_id = ? AND teacher_id = ?
    ");
    $stmt->execute([$news_id, $_SESSION['user_id']]);
    
    if (!$stmt->fetch()) {
        // Als gelesen markieren
        $stmt = $pdo->prepare("
            INSERT INTO news_read_status (news_id, teacher_id, read_at) 
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$news_id, $_SESSION['user_id']]);
    }
    
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankfehler']);
}
?>