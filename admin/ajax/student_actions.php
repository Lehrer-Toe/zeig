<?php
/**
 * AJAX Handler für Schüler-Aktionen
 */

function handleStudentAction($action, $postData, $files, $user) {
    ob_clean();
    
    switch ($action) {
        case 'get_students':
            $classId = (int)($postData['class_id'] ?? 0);
            
            // Prüfen ob Klasse zur Schule gehört
            $db = getDB();
            $stmt = $db->prepare("SELECT id FROM classes WHERE id = ? AND school_id = ?");
            $stmt->execute([$classId, $user['school_id']]);
            if (!$stmt->fetch()) {
                sendErrorResponse('Klasse nicht gefunden.');
            }
            
            // Schüler laden
            $students = getClassStudentsSimple($classId);
            sendSuccessResponse('Schüler geladen.', $students);
            break;
            
        case 'upload_students':
            $classId = (int)($postData['class_id'] ?? 0);
            $nameFormat = $postData['name_format'] ?? 'vorname_nachname';
            
            if (!$classId) {
                sendErrorResponse('Bitte wählen Sie eine Klasse aus.');
            }
            
            if (!isset($files['student_file']) || $files['student_file']['error'] !== UPLOAD_ERR_OK) {
                $uploadError = 'Unbekannter Fehler';
                if (isset($files['student_file']['error'])) {
                    switch ($files['student_file']['error']) {
                        case UPLOAD_ERR_INI_SIZE:
                        case UPLOAD_ERR_FORM_SIZE:
                            $uploadError = 'Die Datei ist zu groß.';
                            break;
                        case UPLOAD_ERR_PARTIAL:
                            $uploadError = 'Die Datei wurde nur teilweise hochgeladen.';
                            break;
                        case UPLOAD_ERR_NO_FILE:
                            $uploadError = 'Es wurde keine Datei ausgewählt.';
                            break;
                        case UPLOAD_ERR_NO_TMP_DIR:
                            $uploadError = 'Temporäres Verzeichnis fehlt.';
                            break;
                        case UPLOAD_ERR_CANT_WRITE:
                            $uploadError = 'Datei konnte nicht geschrieben werden.';
                            break;
                        case UPLOAD_ERR_EXTENSION:
                            $uploadError = 'Upload wurde durch eine PHP-Erweiterung gestoppt.';
                            break;
                    }
                }
                sendErrorResponse('Datei-Upload fehlgeschlagen: ' . $uploadError);
            }
            
            // Prüfen ob Klasse zur Schule gehört
            $db = getDB();
            $stmt = $db->prepare("SELECT name FROM classes WHERE id = ? AND school_id = ?");
            $stmt->execute([$classId, $user['school_id']]);
            $class = $stmt->fetch();
            
            if (!$class) {
                sendErrorResponse('Klasse nicht gefunden.');
            }
            
            $file = $files['student_file'];
            $fileName = $file['name'];
            $tmpName = $file['tmp_name'];
            $fileSize = $file['size'];
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            // Debug-Informationen nur in Error-Log
            error_log("Upload Debug - Filename: $fileName, Size: $fileSize, Extension: $fileExt, TmpName: $tmpName");
            
            try {
                // Students-Tabelle sicherstellen
                ensureStudentsTable();
                
                $students = [];
                
                if (in_array($fileExt, ['csv', 'txt'])) {
                    $students = parseCSVFileSimple($tmpName, $nameFormat);
                    error_log("Upload Debug - Parsed " . count($students) . " students");
                } else {
                    sendErrorResponse('Nicht unterstütztes Dateiformat. Verwenden Sie CSV oder TXT.');
                }
                
                if (empty($students)) {
                    sendErrorResponse('Keine gültigen Schülerdaten in der Datei gefunden.');
                }
                
                // Schule holen für Limits
                $school = getSchoolById($user['school_id']);
                if (!$school) {
                    sendErrorResponse('Schuldaten konnten nicht geladen werden.');
                }
                
                // Prüfen ob Klassenlimit erreicht wird
                $currentCount = getCurrentStudentCount($classId);
                $newTotal = $currentCount + count($students);
                $maxStudents = $school['max_students_per_class'];
                
                error_log("Upload Debug - Current: $currentCount, Adding: " . count($students) . ", Max: $maxStudents");
                
                if ($newTotal > $maxStudents) {
                    sendErrorResponse("Klassenlimit überschritten. Aktuell: {$currentCount}, Hinzufügen: " . count($students) . ", Maximum: {$maxStudents}");
                }
                
                // Schüler einfügen
                $insertedCount = insertStudentsSimple($classId, $user['school_id'], $students);
                
                error_log("Upload Debug - Inserted $insertedCount students");
                
                // Erfolgsmeldung mit Details
                $message = "Erfolgreich {$insertedCount} Schüler in Klasse '{$class['name']}' hinzugefügt.";
                if ($insertedCount < count($students)) {
                    $skipped = count($students) - $insertedCount;
                    $message .= " ({$skipped} übersprungen wegen ungültiger Daten)";
                }
                
                sendSuccessResponse($message);
                
            } catch (Exception $e) {
                error_log("Upload error: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
                sendErrorResponse('Fehler beim Verarbeiten der Datei: ' . $e->getMessage());
            }
            break;
            
        case 'add_single_student':
            $classId = (int)($postData['class_id'] ?? 0);
            $firstName = trim($postData['first_name'] ?? '');
            $lastName = trim($postData['last_name'] ?? '');
            
            if (!$classId || empty($lastName)) {
                sendErrorResponse('Klasse und Nachname sind erforderlich.');
            }
            
            // Vorname kann leer sein
            if (empty($firstName)) {
                $firstName = '-';
            }
            
            // Prüfen ob Klasse zur Schule gehört
            $db = getDB();
            $stmt = $db->prepare("SELECT name FROM classes WHERE id = ? AND school_id = ?");
            $stmt->execute([$classId, $user['school_id']]);
            $class = $stmt->fetch();
            
            if (!$class) {
                sendErrorResponse('Klasse nicht gefunden.');
            }
            
            // Schule holen für Limits
            $school = getSchoolById($user['school_id']);
            if (!$school) {
                sendErrorResponse('Schuldaten konnten nicht geladen werden.');
            }
            
            // Prüfen ob Klassenlimit erreicht
            $currentCount = getCurrentStudentCount($classId);
            if ($currentCount >= $school['max_students_per_class']) {
                sendErrorResponse('Maximale Schüleranzahl für diese Klasse erreicht.');
            }
            
            ensureStudentsTable();
            
            $students = [['first_name' => $firstName, 'last_name' => $lastName]];
            $insertedCount = insertStudentsSimple($classId, $user['school_id'], $students);
            
            if ($insertedCount > 0) {
                $displayName = $firstName === '-' ? $lastName : "$firstName $lastName";
                sendSuccessResponse("Schüler '{$displayName}' erfolgreich hinzugefügt.");
            } else {
                sendErrorResponse('Fehler beim Hinzufügen des Schülers.');
            }
            break;
            
        case 'update_student':
            $studentId = (int)($postData['student_id'] ?? 0);
            $firstName = trim($postData['first_name'] ?? '');
            $lastName = trim($postData['last_name'] ?? '');
            
            if (!$studentId || empty($lastName)) {
                sendErrorResponse('Schüler-ID und Nachname sind erforderlich.');
            }
            
            // Vorname kann leer sein
            if (empty($firstName)) {
                $firstName = '-';
            }
            
            // Prüfen ob Schüler zur Schule gehört
            $db = getDB();
            $stmt = $db->prepare("
                SELECT s.* 
                FROM students s 
                JOIN classes c ON s.class_id = c.id 
                WHERE s.id = ? AND c.school_id = ?
            ");
            $stmt->execute([$studentId, $user['school_id']]);
            
            if (!$stmt->fetch()) {
                sendErrorResponse('Schüler nicht gefunden.');
            }
            
            if (updateStudent($studentId, $firstName, $lastName)) {
                $displayName = $firstName === '-' ? $lastName : "$firstName $lastName";
                sendSuccessResponse("Schüler '{$displayName}' erfolgreich aktualisiert.");
            } else {
                sendErrorResponse('Fehler beim Aktualisieren des Schülers.');
            }
            break;
            
        case 'delete_student':
            $studentId = (int)($postData['student_id'] ?? 0);
            
            $db = getDB();
            
            // Prüfen ob Schüler zur Schule gehört
            $stmt = $db->prepare("
                SELECT s.* 
                FROM students s 
                JOIN classes c ON s.class_id = c.id 
                WHERE s.id = ? AND c.school_id = ?
            ");
            $stmt->execute([$studentId, $user['school_id']]);
            
            if (!$stmt->fetch()) {
                sendErrorResponse('Schüler nicht gefunden.');
            }
            
            if (deleteStudent($studentId)) {
                sendSuccessResponse('Schüler erfolgreich entfernt.');
            } else {
                sendErrorResponse('Fehler beim Entfernen des Schülers.');
            }
            break;
    }
}
?>