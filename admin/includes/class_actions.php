<?php
/**
 * AJAX Handler für Klassen-Aktionen
 */

function handleClassAction($action, $postData, $user) {
    ob_clean();
    
    switch ($action) {
        case 'create_class':
            $className = trim($postData['class_name'] ?? '');
            $schoolYear = $postData['school_year'] ?? '';
            
            if (empty($className)) {
                sendErrorResponse('Klassenname ist erforderlich.');
            }
            
            // Prüfen ob Klassenlimit erreicht
            if (!canCreateClass($user['school_id'])) {
                sendErrorResponse('Maximale Anzahl an Klassen erreicht.');
            }
            
            if (createClass($className, $schoolYear, $user['school_id'], $user['id'])) {
                sendSuccessResponse('Klasse erfolgreich erstellt.');
            } else {
                sendErrorResponse('Fehler beim Erstellen der Klasse.');
            }
            break;
            
        case 'update_class':
            $classId = (int)($postData['class_id'] ?? 0);
            $className = trim($postData['class_name'] ?? '');
            $schoolYear = $postData['school_year'] ?? '';
            
            if (empty($className)) {
                sendErrorResponse('Klassenname ist erforderlich.');
            }
            
            if (updateClass($classId, $className, $schoolYear, $user['school_id'])) {
                sendSuccessResponse('Klasse erfolgreich aktualisiert.');
            } else {
                sendErrorResponse('Fehler beim Aktualisieren der Klasse.');
            }
            break;
            
        case 'delete_class':
            $classId = (int)($postData['class_id'] ?? 0);
            
            if (deleteClass($classId, $user['school_id'])) {
                sendSuccessResponse('Klasse erfolgreich gelöscht.');
            } else {
                sendErrorResponse('Fehler beim Löschen der Klasse.');
            }
            break;
    }
}
?>