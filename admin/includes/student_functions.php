<?php
/**
 * Schüler-Funktionen für das Admin-Dashboard
 */

function ensureStudentsTable() {
    $db = getDB();
    
    try {
        // Prüfen ob Students-Tabelle existiert
        $stmt = $db->prepare("SHOW TABLES LIKE 'students'");
        $stmt->execute();
        
        if ($stmt->rowCount() == 0) {
            // Minimale Tabelle erstellen
            $sql = "
                CREATE TABLE students (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    class_id INT NOT NULL,
                    school_id INT NOT NULL,
                    first_name VARCHAR(100) NOT NULL,
                    last_name VARCHAR(100) NOT NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_class (class_id),
                    INDEX idx_school (school_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ";
            $db->exec($sql);
        }
    } catch (Exception $e) {
        error_log("Error creating students table: " . $e->getMessage());
        throw new Exception("Fehler beim Erstellen der Schüler-Tabelle");
    }
}

function parseCSVFileSimple($filePath, $nameFormat) {
    $students = [];
    
    if (!file_exists($filePath) || !is_readable($filePath)) {
        error_log("Parse Debug - File not readable: $filePath");
        throw new Exception('Datei konnte nicht gelesen werden.');
    }
    
    $content = file_get_contents($filePath);
    if ($content === false || empty($content)) {
        error_log("Parse Debug - File empty or unreadable");
        throw new Exception('Datei ist leer oder konnte nicht gelesen werden.');
    }
    
    error_log("Parse Debug - File size: " . strlen($content) . " bytes");
    
    // BOM entfernen falls vorhanden
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    
    // Zeilen aufteilen
    $lines = preg_split('/\r\n|\r|\n/', $content);
    error_log("Parse Debug - Found " . count($lines) . " lines");
    
    $lineCount = 0;
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $lineCount++;
        error_log("Parse Debug - Line $lineCount: '$line'");
        
        // Wenn die Zeile keine Trennzeichen enthält, nehmen wir die ganze Zeile als Namen
        if (!strpos($line, ',') && !strpos($line, ';') && !strpos($line, "\t") && !strpos($line, '|')) {
            // Direkt als Name verwenden
            $nameData = parseStudentNameSimple($line, $nameFormat);
            if ($nameData) {
                error_log("Parse Debug - Direct parse: " . $nameData['first_name'] . " " . $nameData['last_name']);
                $students[] = $nameData;
            }
        } else {
            // Mit Trennzeichen parsen
            $possibleDelimiters = [',', ';', "\t", '|'];
            $maxParts = 0;
            $bestParts = [];
            
            foreach ($possibleDelimiters as $delimiter) {
                // str_getcsv mit explizitem escape Parameter für PHP 8.4+ Kompatibilität
                $parts = str_getcsv($line, $delimiter, '"', '\\');
                if (count($parts) > $maxParts) {
                    $maxParts = count($parts);
                    $bestParts = $parts;
                }
            }
            
            // Prüfen ob es zwei Teile gibt (Vorname und Nachname)
            if (count($bestParts) >= 2) {
                // Direkt als Vor- und Nachname verwenden
                $firstName = trim($bestParts[0]);
                $lastName = trim($bestParts[1]);
                if (!empty($firstName) && !empty($lastName)) {
                    error_log("Parse Debug - Two parts found: First='$firstName', Last='$lastName'");
                    $students[] = ['first_name' => $firstName, 'last_name' => $lastName];
                }
            } else {
                // Nur ein Teil - normales Parsing
                $nameToParse = isset($bestParts[0]) ? trim($bestParts[0]) : $line;
                $nameData = parseStudentNameSimple($nameToParse, $nameFormat);
                if ($nameData) {
                    error_log("Parse Debug - Single part parse: " . $nameData['first_name'] . " " . $nameData['last_name']);
                    $students[] = $nameData;
                }
            }
        }
    }
    
    error_log("Parse Debug - Total students parsed: " . count($students));
    return $students;
}

function parseStudentNameSimple($fullName, $format) {
    $fullName = trim($fullName);
    
    if (empty($fullName) || strlen($fullName) < 2) {
        return null;
    }
    
    // Debug-Ausgabe
    error_log("Parse Name Debug - Input: '$fullName', Format: $format");
    
    // Sonderzeichen entfernen (außer Buchstaben inkl. Umlaute, Leerzeichen, Bindestrich, Komma)
    $fullName = preg_replace('/[^\p{L}\s\-,\.]/u', '', $fullName);
    $fullName = trim($fullName);
    
    // Prüfen ob es ein einzelner Name ist (kein Leerzeichen)
    if (!strpos($fullName, ' ') && strpos($fullName, ',') === false) {
        // Einzelner Name - je nach Kontext als Vor- oder Nachname interpretieren
        error_log("Parse Name Debug - Single name detected: '$fullName'");
        
        // Bei einem einzelnen Namen nehmen wir an, dass es der Nachname ist
        // und lassen den Vornamen leer (wird später in der Anzeige behandelt)
        return ['first_name' => '', 'last_name' => $fullName];
    }
    
    switch ($format) {
        case 'vorname_nachname':
            // "Max Mustermann" oder "Max Peter Mustermann"
            $parts = preg_split('/\s+/', $fullName);
            if (count($parts) >= 2) {
                $firstName = $parts[0];
                $lastName = implode(' ', array_slice($parts, 1));
                error_log("Parse Name Debug - Parsed as: First='$firstName', Last='$lastName'");
                return ['first_name' => $firstName, 'last_name' => $lastName];
            }
            break;
            
        case 'nachname_vorname':
            // "Mustermann, Max" oder "Mustermann, Max Peter"
            if (strpos($fullName, ',') !== false) {
                $parts = array_map('trim', explode(',', $fullName, 2));
                $lastName = $parts[0];
                $firstName = isset($parts[1]) ? $parts[1] : '';
                if (!empty($lastName)) {
                    error_log("Parse Name Debug - Parsed with comma: First='$firstName', Last='$lastName'");
                    return ['first_name' => $firstName, 'last_name' => $lastName];
                }
            } else {
                // Fallback: letztes Wort als Nachname
                $parts = preg_split('/\s+/', $fullName);
                if (count($parts) >= 2) {
                    $lastName = array_pop($parts);
                    $firstName = implode(' ', $parts);
                    error_log("Parse Name Debug - Parsed without comma: First='$firstName', Last='$lastName'");
                    return ['first_name' => $firstName, 'last_name' => $lastName];
                }
            }
            break;
    }
    
    // Fallback: Ganzer Name als Nachname
    error_log("Parse Name Debug - Fallback: using '$fullName' as last name");
    return ['first_name' => '', 'last_name' => $fullName];
}

function insertStudentsSimple($classId, $schoolId, $students) {
    $db = getDB();
    $insertedCount = 0;
    
    try {
        // Prüfen ob students Tabelle die benötigten Spalten hat
        $stmt = $db->prepare("SHOW COLUMNS FROM students");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        error_log("Insert Debug - Available columns: " . implode(', ', $columns));
        
        // Basis-Insert-Statement
        $sql = "INSERT INTO students (class_id, school_id, first_name, last_name";
        $values = "(?, ?, ?, ?";
        
        // Optional: is_active wenn vorhanden
        if (in_array('is_active', $columns)) {
            $sql .= ", is_active";
            $values .= ", 1";
        }
        
        // Optional: created_at wenn vorhanden
        if (in_array('created_at', $columns)) {
            $sql .= ", created_at";
            $values .= ", NOW()";
        }
        
        $sql .= ") VALUES " . $values . ")";
        error_log("Insert Debug - SQL: $sql");
        
        $stmt = $db->prepare($sql);
        
        foreach ($students as $student) {
            // Bei leerem Vornamen einen Standard setzen
            $firstName = !empty($student['first_name']) ? $student['first_name'] : '-';
            $lastName = $student['last_name'];
            
            if (empty($lastName)) {
                error_log("Insert Debug - Skipping empty student");
                continue;
            }
            
            error_log("Insert Debug - Inserting: '$firstName' '$lastName'");
            
            if ($stmt->execute([$classId, $schoolId, $firstName, $lastName])) {
                $insertedCount++;
            } else {
                error_log("Insert Debug - Failed to insert student: " . implode(', ', $stmt->errorInfo()));
            }
        }
        
        error_log("Insert Debug - Total inserted: $insertedCount");
        return $insertedCount;
        
    } catch (Exception $e) {
        error_log("Insert Debug - Error: " . $e->getMessage());
        throw new Exception("Fehler beim Einfügen der Schüler: " . $e->getMessage());
    }
}

function updateStudent($studentId, $firstName, $lastName) {
    $db = getDB();
    
    try {
        $stmt = $db->prepare("
            UPDATE students 
            SET first_name = ?, last_name = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        
        return $stmt->execute([$firstName, $lastName, $studentId]);
        
    } catch (Exception $e) {
        error_log("Error updating student: " . $e->getMessage());
        return false;
    }
}

function getCurrentStudentCount($classId) {
    $db = getDB();
    
    try {
        // Prüfen ob Students-Tabelle existiert
        $stmt = $db->prepare("SHOW TABLES LIKE 'students'");
        $stmt->execute();
        
        if ($stmt->rowCount() == 0) {
            return 0;
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM students WHERE class_id = ? AND is_active = 1");
        $stmt->execute([$classId]);
        $result = $stmt->fetch();
        return $result ? (int)$result['count'] : 0;
        
    } catch (Exception $e) {
        error_log("Error counting students: " . $e->getMessage());
        return 0;
    }
}

function getClassStudentsSimple($classId) {
    $db = getDB();
    
    try {
        // Prüfen ob Students-Tabelle existiert
        $stmt = $db->prepare("SHOW TABLES LIKE 'students'");
        $stmt->execute();
        
        if ($stmt->rowCount() == 0) {
            return [];
        }
        
        $stmt = $db->prepare("
            SELECT id, first_name, last_name, created_at 
            FROM students 
            WHERE class_id = ? AND is_active = 1 
            ORDER BY last_name ASC, first_name ASC
        ");
        $stmt->execute([$classId]);
        $students = $stmt->fetchAll();
        
        // Leere Vornamen durch Bindestrich ersetzen für die Anzeige
        foreach ($students as &$student) {
            if (empty($student['first_name'])) {
                $student['first_name'] = '-';
            }
        }
        
        return $students;
        
    } catch (Exception $e) {
        error_log("Error loading students: " . $e->getMessage());
        return [];
    }
}

function getStudentById($studentId) {
    $db = getDB();
    
    try {
        $stmt = $db->prepare("
            SELECT * FROM students 
            WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$studentId]);
        return $stmt->fetch();
        
    } catch (Exception $e) {
        error_log("Error loading student: " . $e->getMessage());
        return null;
    }
}

function deleteStudent($studentId) {
    $db = getDB();
    
    try {
        // Soft delete
        $stmt = $db->prepare("UPDATE students SET is_active = 0 WHERE id = ?");
        return $stmt->execute([$studentId]);
        
    } catch (Exception $e) {
        error_log("Error deleting student: " . $e->getMessage());
        return false;
    }
}
?>