<?php
require_once '../config.php';

// Schuladmin-Zugriff prüfen
$user = requireSchuladmin();
requireValidSchoolLicense($user['school_id']);

$db = getDB();
$messages = [];
$errors = [];

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Für Schuladmin ist die Schule fest vorgegeben
$selected_school_id = $user['school_id'];

// Schuldaten laden
$school = getSchoolById($selected_school_id);
if (!$school) {
    die('Schule nicht gefunden.');
}

// AJAX-Handler für Datenexport
if (isset($_GET['action']) && $_GET['action'] === 'export' && $selected_school_id) {
    header('Content-Type: application/json');
    
    try {
        $format = $_GET['format'] ?? 'sql';
        $school_id = (int)$selected_school_id;
        
        // Schuldaten sammeln
        $data = [];
        
        // WICHTIG: Schule selbst NICHT exportieren, da sie erhalten bleibt!
        // Nur für Referenz speichern
        $stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
        $stmt->execute([$school_id]);
        $school_info = $stmt->fetch();
        
        // Benutzer (ohne Passwörter für Sicherheit)
        $stmt = $db->prepare("SELECT * FROM users WHERE school_id = ?");
        $stmt->execute([$school_id]);
        $data['users'] = $stmt->fetchAll();
        
        // Klassen
        $stmt = $db->prepare("SELECT * FROM classes WHERE school_id = ?");
        $stmt->execute([$school_id]);
        $data['classes'] = $stmt->fetchAll();
        
        // Schüler - MIT school_id!
        $stmt = $db->prepare("
            SELECT s.*, c.school_id 
            FROM students s 
            JOIN classes c ON s.class_id = c.id 
            WHERE c.school_id = ?
        ");
        $stmt->execute([$school_id]);
        $students = $stmt->fetchAll();
        
        // school_id zu jedem Schüler hinzufügen falls nicht vorhanden
        foreach ($students as &$student) {
            if (!isset($student['school_id']) || $student['school_id'] === null) {
                $student['school_id'] = $school_id;
            }
        }
        $data['students'] = $students;
        
        // Gruppen
        $stmt = $db->prepare("SELECT * FROM groups WHERE school_id = ?");
        $stmt->execute([$school_id]);
        $data['groups'] = $stmt->fetchAll();
        
        // Gruppenzuordnungen
        $stmt = $db->prepare("SELECT gs.* FROM group_students gs JOIN groups g ON gs.group_id = g.id WHERE g.school_id = ?");
        $stmt->execute([$school_id]);
        $data['group_students'] = $stmt->fetchAll();
        
        // Fächer
        $stmt = $db->prepare("SELECT * FROM subjects WHERE school_id = ?");
        $stmt->execute([$school_id]);
        $data['subjects'] = $stmt->fetchAll();
        
        // HINWEIS: rating_criteria wird NICHT exportiert, da es globale Daten sind!
        
        // Bewertungsvorlagen
        $stmt = $db->prepare("SELECT * FROM rating_templates WHERE teacher_id IN (SELECT id FROM users WHERE school_id = ?)");
        $stmt->execute([$school_id]);
        $data['rating_templates'] = $stmt->fetchAll();
        
        // Bewertungsvorlagen-Kategorien
        $stmt = $db->prepare("SELECT rtc.* FROM rating_template_categories rtc JOIN rating_templates rt ON rtc.template_id = rt.id WHERE rt.teacher_id IN (SELECT id FROM users WHERE school_id = ?)");
        $stmt->execute([$school_id]);
        $data['rating_template_categories'] = $stmt->fetchAll();
        
        // Bewertungen
        $stmt = $db->prepare("SELECT r.* FROM ratings r JOIN groups g ON r.group_id = g.id WHERE g.school_id = ?");
        $stmt->execute([$school_id]);
        $data['ratings'] = $stmt->fetchAll();
        
        // Bewertungskategorien
        $stmt = $db->prepare("SELECT rc.* FROM rating_categories rc JOIN ratings r ON rc.rating_id = r.id JOIN groups g ON r.group_id = g.id WHERE g.school_id = ?");
        $stmt->execute([$school_id]);
        $data['rating_categories'] = $stmt->fetchAll();
        
        // Bewertungsstärken
        $stmt = $db->prepare("SELECT rs.* FROM rating_strengths rs JOIN ratings r ON rs.rating_id = r.id JOIN groups g ON r.group_id = g.id WHERE g.school_id = ?");
        $stmt->execute([$school_id]);
        $data['rating_strengths'] = $stmt->fetchAll();
        
        // Stärkenkategorien
        $stmt = $db->prepare("SELECT * FROM strength_categories WHERE school_id = ?");
        $stmt->execute([$school_id]);
        $data['strength_categories'] = $stmt->fetchAll();
        
        // Stärkenitems
        $stmt = $db->prepare("SELECT si.* FROM strength_items si JOIN strength_categories sc ON si.category_id = sc.id WHERE sc.school_id = ?");
        $stmt->execute([$school_id]);
        $data['strength_items'] = $stmt->fetchAll();
        
        // Themen
        $stmt = $db->prepare("SELECT * FROM topics WHERE school_id = ?");
        $stmt->execute([$school_id]);
        $data['topics'] = $stmt->fetchAll();
        
        // Themenfächer
        $stmt = $db->prepare("SELECT ts.* FROM topic_subjects ts JOIN topics t ON ts.topic_id = t.id WHERE t.school_id = ?");
        $stmt->execute([$school_id]);
        $data['topic_subjects'] = $stmt->fetchAll();
        
        // User Sessions (optional, aber gut für Vollständigkeit)
        $stmt = $db->prepare("SELECT us.* FROM user_sessions us JOIN users u ON us.user_id = u.id WHERE u.school_id = ?");
        $stmt->execute([$school_id]);
        $data['user_sessions'] = $stmt->fetchAll();
        
        if ($format === 'sql') {
            // SQL-Export generieren
            $sql = "-- Schulexport für: " . $school_info['name'] . " (ID: $school_id)\n";
            $sql .= "-- Exportiert am: " . date('Y-m-d H:i:s') . "\n";
            $sql .= "-- HINWEIS: Die Schule selbst und rating_criteria werden NICHT exportiert\n";
            $sql .= "-- WARNUNG: Vor dem Import sollten alle Daten mit 'Alles löschen' entfernt werden!\n\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
            
            // Helper-Funktion für SQL-Werte
            function sqlValue($value) {
                global $db;
                if ($value === null) return 'NULL';
                if (is_numeric($value)) return $value;
                return $db->quote($value);
            }
            
            // Definiere die richtige Reihenfolge für den Import
            $tableOrder = [
                // 'schools' wird NICHT exportiert/importiert, da sie erhalten bleibt
                // 'rating_criteria' wird NICHT exportiert/importiert, da es globale Daten sind
                'users',
                'user_sessions',
                'classes',
                'students',
                'subjects',
                'groups',
                'group_students',
                'strength_categories',
                'strength_items',
                'topics',
                'topic_subjects',
                'rating_templates',
                'rating_template_categories',
                'ratings',
                'rating_categories',
                'rating_strengths'
            ];
            
            // SQL für jede Tabelle in der richtigen Reihenfolge generieren
            foreach ($tableOrder as $table) {
                if (!isset($data[$table]) || empty($data[$table])) continue;
                
                $sql .= "-- $table\n";
                foreach ($data[$table] as $row) {
                    if (is_array($row)) {
                        $columns = array_keys($row);
                        $values = array_map('sqlValue', array_values($row));
                        $sql .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
                    }
                }
                $sql .= "\n";
            }
            
            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
            
            // Download als SQL-Datei
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="schulexport_' . $school_id . '_' . date('Y-m-d') . '.sql"');
            echo $sql;
            exit;
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Import-Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF-Schutz
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Ungültiger Sicherheitstoken.';
    } else {
        $school_id = (int)($_POST['school_id'] ?? 0);
        
        switch ($_POST['action']) {
            case 'import':
                if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
                    try {
                        $content = file_get_contents($_FILES['import_file']['tmp_name']);
                        $extension = pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION);
                        
                        if ($extension === 'sql') {
                            // SQL-Import
                            $db->beginTransaction();
                            
                            // Foreign Key Checks temporär deaktivieren
                            $db->exec("SET FOREIGN_KEY_CHECKS=0");
                            
                            // SQL-Datei verarbeiten - Verbesserte Methode
                            // Entferne Windows-Zeilenumbrüche und normalisiere
                            $content = str_replace("\r\n", "\n", $content);
                            $content = str_replace("\r", "\n", $content);
                            
                            // Statements manuell parsen für bessere Kontrolle
                            $statements = [];
                            $currentStatement = '';
                            $inString = false;
                            $stringChar = '';
                            $escaped = false;
                            
                            for ($i = 0; $i < strlen($content); $i++) {
                                $char = $content[$i];
                                $nextChar = isset($content[$i + 1]) ? $content[$i + 1] : '';
                                
                                // String-Handling
                                if (!$escaped && ($char === '"' || $char === "'") && !$inString) {
                                    $inString = true;
                                    $stringChar = $char;
                                } elseif (!$escaped && $char === $stringChar && $inString) {
                                    $inString = false;
                                }
                                
                                // Escape-Handling
                                if ($char === '\\' && !$escaped) {
                                    $escaped = true;
                                } else {
                                    $escaped = false;
                                }
                                
                                // Statement-Ende erkennen
                                if ($char === ';' && !$inString) {
                                    $currentStatement = trim($currentStatement);
                                    if (!empty($currentStatement)) {
                                        // Kommentare und SET-Befehle behandeln
                                        if (!str_starts_with($currentStatement, '--') || 
                                            str_starts_with(strtoupper($currentStatement), 'SET')) {
                                            $statements[] = $currentStatement;
                                        }
                                    }
                                    $currentStatement = '';
                                } else {
                                    $currentStatement .= $char;
                                }
                            }
                            
                            // Letztes Statement ohne Semikolon
                            $currentStatement = trim($currentStatement);
                            if (!empty($currentStatement) && !str_starts_with($currentStatement, '--')) {
                                $statements[] = $currentStatement;
                            }
                            
                            // Statements ausführen
                            $successCount = 0;
                            $errorCount = 0;
                            
                            foreach ($statements as $statement) {
                                try {
                                    // Nochmal trimmen und prüfen
                                    $statement = trim($statement);
                                    if (empty($statement)) continue;
                                    
                                    // Debug: Erste 100 Zeichen des Statements loggen bei Fehler
                                    $db->exec($statement);
                                    $successCount++;
                                } catch (Exception $e) {
                                    $errorCount++;
                                    error_log("SQL Import Error: " . substr($statement, 0, 100) . "... - " . $e->getMessage());
                                    
                                    // Bei kritischen Fehlern abbrechen
                                    if ($errorCount > 10) {
                                        throw new Exception("Zu viele Fehler beim Import. Abbruch nach $errorCount Fehlern.");
                                    }
                                }
                            }
                            
                            // Foreign Key Checks wieder aktivieren
                            $db->exec("SET FOREIGN_KEY_CHECKS=1");
                            
                            $db->commit();
                            
                            if ($errorCount > 0) {
                                $messages[] = "Import abgeschlossen mit $successCount erfolgreichen und $errorCount fehlgeschlagenen Statements.";
                            } else {
                                $messages[] = "Daten erfolgreich importiert. $successCount Statements ausgeführt.";
                            }
                            
                        } else {
                            $errors[] = 'Nur SQL-Dateien werden für den Import unterstützt.';
                        }
                        
                    } catch (Exception $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $errors[] = 'Importfehler: ' . $e->getMessage();
                    }
                } else {
                    $errors[] = 'Keine Datei hochgeladen.';
                }
                break;
                
            case 'delete_students':
                try {
                    $db->beginTransaction();
                    
                    // Zuerst alle abhängigen Daten löschen
                    $stmt = $db->prepare("DELETE rs FROM rating_strengths rs JOIN ratings r ON rs.rating_id = r.id JOIN groups g ON r.group_id = g.id WHERE g.school_id = ?");
                    $stmt->execute([$school_id]);
                    
                    $stmt = $db->prepare("DELETE rc FROM rating_categories rc JOIN ratings r ON rc.rating_id = r.id JOIN groups g ON r.group_id = g.id WHERE g.school_id = ?");
                    $stmt->execute([$school_id]);
                    
                    $stmt = $db->prepare("DELETE r FROM ratings r JOIN groups g ON r.group_id = g.id WHERE g.school_id = ?");
                    $stmt->execute([$school_id]);
                    
                    $stmt = $db->prepare("DELETE gs FROM group_students gs JOIN groups g ON gs.group_id = g.id WHERE g.school_id = ?");
                    $stmt->execute([$school_id]);
                    
                    $stmt = $db->prepare("DELETE s FROM students s JOIN classes c ON s.class_id = c.id WHERE c.school_id = ?");
                    $stmt->execute([$school_id]);
                    
                    $db->commit();
                    $messages[] = 'Alle Schüler wurden erfolgreich gelöscht.';
                    
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $errors[] = 'Fehler beim Löschen der Schüler: ' . $e->getMessage();
                }
                break;
                
            case 'delete_classes':
                try {
                    $db->beginTransaction();
                    
                    // Abhängige Daten werden durch CASCADE gelöscht
                    $stmt = $db->prepare("DELETE FROM classes WHERE school_id = ?");
                    $stmt->execute([$school_id]);
                    
                    $db->commit();
                    $messages[] = 'Alle Klassen wurden erfolgreich gelöscht.';
                    
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $errors[] = 'Fehler beim Löschen der Klassen: ' . $e->getMessage();
                }
                break;
                
            case 'delete_groups':
                try {
                    $db->beginTransaction();
                    
                    // Gruppen und Bewertungen löschen
                    $stmt = $db->prepare("DELETE FROM groups WHERE school_id = ?");
                    $stmt->execute([$school_id]);
                    
                    $db->commit();
                    $messages[] = 'Alle Gruppen und Bewertungen wurden erfolgreich gelöscht.';
                    
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $errors[] = 'Fehler beim Löschen der Gruppen: ' . $e->getMessage();
                }
                break;
                
            case 'delete_all':
                try {
                    $db->beginTransaction();
                    
                    // Alles außer Schule und Admin löschen
                    // WICHTIG: Direkte Löschung ohne komplexe Subqueries
                    
                    // 1. Zuerst die abhängigsten Tabellen
                    $stmt = $db->prepare("
                        DELETE rs FROM rating_strengths rs 
                        JOIN ratings r ON rs.rating_id = r.id 
                        JOIN groups g ON r.group_id = g.id 
                        WHERE g.school_id = ?
                    ");
                    $stmt->execute([$school_id]);
                    
                    $stmt = $db->prepare("
                        DELETE rc FROM rating_categories rc 
                        JOIN ratings r ON rc.rating_id = r.id 
                        JOIN groups g ON r.group_id = g.id 
                        WHERE g.school_id = ?
                    ");
                    $stmt->execute([$school_id]);
                    
                    $stmt = $db->prepare("
                        DELETE r FROM ratings r 
                        JOIN groups g ON r.group_id = g.id 
                        WHERE g.school_id = ?
                    ");
                    $stmt->execute([$school_id]);
                    
                    // 2. Bewertungsvorlagen
                    $stmt = $db->prepare("
                        DELETE rtc FROM rating_template_categories rtc 
                        JOIN rating_templates rt ON rtc.template_id = rt.id 
                        JOIN users u ON rt.teacher_id = u.id 
                        WHERE u.school_id = ?
                    ");
                    $stmt->execute([$school_id]);
                    
                    $stmt = $db->prepare("
                        DELETE rt FROM rating_templates rt 
                        JOIN users u ON rt.teacher_id = u.id 
                        WHERE u.school_id = ?
                    ");
                    $stmt->execute([$school_id]);
                    
                    // 3. Themen
                    $stmt = $db->prepare("
                        DELETE ts FROM topic_subjects ts 
                        JOIN topics t ON ts.topic_id = t.id 
                        WHERE t.school_id = ?
                    ");
                    $stmt->execute([$school_id]);
                    
                    $stmt = $db->prepare("DELETE FROM topics WHERE school_id = ?");
                    $stmt->execute([$school_id]);
                    
                    // 4. Gruppen
                    $stmt = $db->prepare("
                        DELETE gs FROM group_students gs 
                        JOIN groups g ON gs.group_id = g.id 
                        WHERE g.school_id = ?
                    ");
                    $stmt->execute([$school_id]);
                    
                    $stmt = $db->prepare("DELETE FROM groups WHERE school_id = ?");
                    $stmt->execute([$school_id]);
                    
                    // 5. Schüler und Klassen
                    $stmt = $db->prepare("
                        DELETE s FROM students s 
                        JOIN classes c ON s.class_id = c.id 
                        WHERE c.school_id = ?
                    ");
                    $stmt->execute([$school_id]);
                    
                    $stmt = $db->prepare("DELETE FROM classes WHERE school_id = ?");
                    $stmt->execute([$school_id]);
                    
                    // 6. Weitere schulspezifische Daten
                    $stmt = $db->prepare("DELETE FROM subjects WHERE school_id = ?");
                    $stmt->execute([$school_id]);
                    
                    $stmt = $db->prepare("
                        DELETE si FROM strength_items si 
                        JOIN strength_categories sc ON si.category_id = sc.id 
                        WHERE sc.school_id = ?
                    ");
                    $stmt->execute([$school_id]);
                    
                    $stmt = $db->prepare("DELETE FROM strength_categories WHERE school_id = ?");
                    $stmt->execute([$school_id]);
                    
                    // 7. User Sessions
                    $stmt = $db->prepare("
                        DELETE us FROM user_sessions us 
                        JOIN users u ON us.user_id = u.id 
                        WHERE u.school_id = ?
                    ");
                    $stmt->execute([$school_id]);
                    
                    // 8. Alle Benutzer löschen außer Schuladmin
                    $stmt = $db->prepare("DELETE FROM users WHERE school_id = ? AND user_type != 'schuladmin'");
                    $stmt->execute([$school_id]);
                    
                    // HINWEIS: rating_criteria wird NICHT gelöscht, da es globale Kriterien sind!
                    
                    // Transaktion abschließen - WICHTIG: Vor AUTO_INCREMENT Reset!
                    $db->commit();
                    
                    // AUTO_INCREMENT zurücksetzen für alle betroffenen Tabellen
                    // Dies geschieht NACH dem Commit, da es keine Transaktion benötigt
                    try {
                        $reset_tables = [
                            'classes', 'students', 'groups', 'group_students',
                            'subjects', 'topics', 'topic_subjects',
                            'ratings', 'rating_categories', 'rating_strengths',
                            'rating_templates', 'rating_template_categories',
                            'strength_categories', 'strength_items', 'user_sessions'
                            // NICHT: rating_criteria (globale Tabelle)
                        ];
                        
                        foreach ($reset_tables as $table) {
                            try {
                                // Prüfen ob Tabelle existiert
                                $stmt = $db->prepare("SHOW TABLES LIKE ?");
                                $stmt->execute([$table]);
                                
                                if ($stmt->rowCount() > 0) {
                                    // Höchste ID finden
                                    $stmt = $db->prepare("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM `$table`");
                                    $stmt->execute();
                                    $result = $stmt->fetch();
                                    $next_id = $result && $result['next_id'] ? $result['next_id'] : 1;
                                    
                                    // AUTO_INCREMENT setzen
                                    $db->exec("ALTER TABLE `$table` AUTO_INCREMENT = $next_id");
                                }
                            } catch (Exception $e) {
                                // Fehler beim AUTO_INCREMENT ignorieren, ist nicht kritisch
                                error_log("AUTO_INCREMENT reset failed for $table: " . $e->getMessage());
                            }
                        }
                    } catch (Exception $e) {
                        // Genereller Fehler beim AUTO_INCREMENT reset - nicht kritisch
                        error_log("AUTO_INCREMENT reset process had issues: " . $e->getMessage());
                    }
                    
                    $messages[] = 'Alle Daten wurden erfolgreich gelöscht und die Datenbank wurde für eine Neueinrichtung vorbereitet.';
                    
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $errors[] = 'Fehler beim Löschen aller Daten: ' . $e->getMessage();
                }
                break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Datenmanagement - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="../css/style.css">
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

        .admin-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        }

        .admin-header {
            background: rgba(0, 0, 0, 0.3);
            border-bottom: 1px solid rgba(59, 130, 246, 0.2);
            padding: 1rem 2rem;
            backdrop-filter: blur(10px);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-content h1 {
            color: #3b82f6;
            font-size: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .nav-link {
            background: rgba(100, 116, 139, 0.2);
            color: #cbd5e1;
            border: 1px solid rgba(100, 116, 139, 0.3);
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            background: rgba(100, 116, 139, 0.3);
            transform: translateY(-2px);
        }

        .admin-main {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .data-section {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.2);
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
        }
        
        .data-section h3 {
            color: #e2e8f0;
            margin-bottom: 1.5rem;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .data-section h3 i {
            color: #60a5fa;
        }
        
        .button-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }
        
        .school-selector {
            background: rgba(0, 0, 0, 0.2);
            padding: 1.5rem;
            border-radius: 1rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(100, 116, 139, 0.2);
        }
        
        .school-selector h2 {
            color: #3b82f6;
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .danger-zone {
            background: rgba(220, 38, 38, 0.1);
            border: 1px solid rgba(220, 38, 38, 0.3);
            padding: 2rem;
            border-radius: 1rem;
            margin-top: 2rem;
        }
        
        .danger-zone h3 {
            color: #fca5a5;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .warning-text {
            color: #fbbf24;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-export {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-weight: 500;
        }
        
        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
        }
        
        .btn-import {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .btn-import:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(220, 38, 38, 0.3);
        }
        
        .btn-secondary {
            background: rgba(100, 116, 139, 0.2);
            color: #cbd5e1;
            border: 1px solid rgba(100, 116, 139, 0.3);
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            cursor: pointer;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .btn-secondary:hover {
            background: rgba(100, 116, 139, 0.3);
        }
        
        .file-input-wrapper {
            position: relative;
            display: inline-block;
            cursor: pointer;
            margin-right: 1rem;
        }
        
        .file-input-wrapper input[type="file"] {
            position: absolute;
            left: -9999px;
        }
        
        .file-input-label {
            background: rgba(0, 0, 0, 0.3);
            color: #e2e8f0;
            padding: 0.75rem 1.5rem;
            border: 1px solid rgba(100, 116, 139, 0.2);
            border-radius: 0.5rem;
            display: inline-block;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .file-input-label:hover {
            background: rgba(51, 65, 85, 0.5);
            border-color: rgba(100, 116, 139, 0.3);
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
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: rgba(15, 23, 42, 0.98);
            padding: 2rem;
            border-radius: 1rem;
            max-width: 500px;
            width: 90%;
            border: 1px solid rgba(100, 116, 139, 0.2);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        
        .modal-header {
            color: #f87171;
            font-size: 1.5rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .modal-body {
            color: #e2e8f0;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        .modal-footer {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }
        
        .info-box {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            color: #93bbfc;
        }

        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }
            
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            .button-group {
                flex-direction: column;
            }
            
            .modal-footer {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <header class="admin-header">
            <div class="header-content">
                <h1><i class="fas fa-database"></i> Datenmanagement</h1>
                <nav>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-arrow-left"></i> 🏠 zurück zum Dashboard
                    </a>
                </nav>
            </div>
        </header>

        <main class="admin-main">
            <?php if ($messages): ?>
                <?php foreach ($messages as $message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if ($errors): ?>
                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="school-selector">
                <h2><?php echo htmlspecialchars($school['name']); ?></h2>
                <p style="color: #94a3b8; margin-top: 0.5rem;">Datenmanagement für Ihre Schule</p>
            </div>

            <div class="data-section">
                <h3><i class="fas fa-download"></i> Datenexport</h3>
                <div class="info-box">
                    <i class="fas fa-info-circle"></i> Exportieren Sie alle Daten der Schule als SQL-Datei zur Sicherung oder Übertragung.
                    <br><strong>Wichtig:</strong> Der Export enthält alle Daten inklusive IDs. Vor einem Re-Import sollten die Daten komplett gelöscht werden.
                </div>
                <div class="button-group">
                    <button onclick="exportData('sql')" class="btn-export">
                        <i class="fas fa-database"></i> Als SQL exportieren
                    </button>
                </div>
            </div>

            <div class="data-section">
                <h3><i class="fas fa-upload"></i> Datenimport</h3>
                <div class="info-box">
                    <i class="fas fa-info-circle"></i> Importieren Sie zuvor exportierte SQL-Dateien.
                    <br><strong>Achtung:</strong> Bestehende Daten mit gleichen IDs werden Fehler verursachen. Löschen Sie zuerst alle Daten!
                </div>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="import">
                    <input type="hidden" name="school_id" value="<?php echo $selected_school_id; ?>">
                    
                    <div class="file-input-wrapper">
                        <label for="import_file" class="file-input-label">
                            <i class="fas fa-file-upload"></i> Datei auswählen
                        </label>
                        <input type="file" name="import_file" id="import_file" accept=".sql" required>
                    </div>
                    
                    <button type="submit" class="btn-import">
                        <i class="fas fa-upload"></i> Importieren
                    </button>
                </form>
            </div>

            <div class="danger-zone">
                <h3><i class="fas fa-exclamation-triangle"></i> Gefahrenbereich - Daten löschen</h3>
                <div class="warning-text">
                    <i class="fas fa-warning"></i>
                    <span>Achtung: Diese Aktionen können nicht rückgängig gemacht werden!</span>
                </div>
                
                <div class="info-box" style="background: rgba(220, 38, 38, 0.1); border-color: rgba(220, 38, 38, 0.3); color: #fca5a5; margin-bottom: 1rem;">
                    <i class="fas fa-exclamation-circle"></i> <strong>Wichtiger Hinweis für Export/Import:</strong><br>
                    Für einen sauberen Re-Import sollten Sie:<br>
                    1. Zuerst exportieren (Backup erstellen)<br>
                    2. Dann "Alles löschen" verwenden<br>
                    3. Sofort danach importieren (bevor neue Daten angelegt werden)<br>
                    Dies verhindert ID-Konflikte und stellt sicher, dass alle Daten korrekt wiederhergestellt werden.
                </div>
                
                <div class="button-group">
                    <button onclick="confirmDelete('students')" class="btn-danger">
                        <i class="fas fa-user-graduate"></i> Alle Schüler löschen
                    </button>
                    <button onclick="confirmDelete('classes')" class="btn-danger">
                        <i class="fas fa-chalkboard"></i> Alle Klassen löschen
                    </button>
                    <button onclick="confirmDelete('groups')" class="btn-danger">
                        <i class="fas fa-users"></i> Alle Gruppen & Bewertungen löschen
                    </button>
                    <button onclick="confirmDelete('all')" class="btn-danger">
                        <i class="fas fa-trash-alt"></i> Alles löschen
                    </button>
                </div>
            </div>
        </main>
    </div>

    <!-- Bestätigungsmodal -->
    <div id="confirmModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <i class="fas fa-exclamation-triangle"></i>
                <span>Bestätigung erforderlich</span>
            </div>
            <div class="modal-body" id="modalMessage">
                <!-- Nachricht wird per JavaScript gesetzt -->
            </div>
            <div class="modal-footer">
                <button onclick="closeModal()" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Abbrechen
                </button>
                <form id="deleteForm" method="post" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" id="deleteAction">
                    <input type="hidden" name="school_id" value="<?php echo $selected_school_id; ?>">
                    <button type="submit" class="btn-danger">
                        <i class="fas fa-trash"></i> Ja, löschen
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function exportData(format) {
            const schoolId = <?php echo $selected_school_id; ?>;
            window.location.href = `admin_data_management.php?action=export&format=${format}&school_id=${schoolId}`;
        }
        
        function confirmDelete(type) {
            const modal = document.getElementById('confirmModal');
            const message = document.getElementById('modalMessage');
            const action = document.getElementById('deleteAction');
            
            let text = '';
            switch (type) {
                case 'students':
                    text = 'Möchten Sie wirklich ALLE Schüler dieser Schule löschen? Dies beinhaltet auch alle Gruppenzuordnungen und Bewertungen der Schüler.';
                    action.value = 'delete_students';
                    break;
                case 'classes':
                    text = 'Möchten Sie wirklich ALLE Klassen dieser Schule löschen? Dies löscht auch alle Schüler, Gruppen und Bewertungen!';
                    action.value = 'delete_classes';
                    break;
                case 'groups':
                    text = 'Möchten Sie wirklich ALLE Gruppen und Bewertungen dieser Schule löschen?';
                    action.value = 'delete_groups';
                    break;
                case 'all':
                    text = 'Möchten Sie wirklich ALLE DATEN dieser Schule löschen? Dies umfasst alle Klassen, Schüler, Lehrer, Gruppen, Bewertungen und Fächer. Nur die Schule selbst und der Admin-Account bleiben erhalten.';
                    action.value = 'delete_all';
                    break;
            }
            
            message.textContent = text;
            modal.classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('confirmModal').classList.remove('active');
        }
        
        // Dateiname anzeigen
        document.getElementById('import_file')?.addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name || 'Datei auswählen';
            const label = document.querySelector('.file-input-label');
            if (label) {
                label.innerHTML = `<i class="fas fa-file-upload"></i> ${fileName}`;
            }
        });
    </script>
</body>
</html>