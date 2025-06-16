<?php
/**
 * Klassen-Funktionen für das Admin-Dashboard
 */

function getSchoolClasses($schoolId, $classFilter = 'all', $yearFilter = 'all') {
    $db = getDB();
    
    // Prüfen welche Spalten in classes existieren
    $stmt = $db->prepare("SHOW COLUMNS FROM classes");
    $stmt->execute();
    $availableColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $selectColumns = ['c.id', 'c.name', 'c.school_id'];
    $whereConditions = ['c.school_id = ?'];
    $params = [$schoolId];
    
    // Optional verfügbare Spalten hinzufügen
    if (in_array('school_year', $availableColumns)) {
        $selectColumns[] = 'c.school_year';
    }
    if (in_array('created_at', $availableColumns)) {
        $selectColumns[] = 'c.created_at';
    }
    if (in_array('created_by', $availableColumns)) {
        $selectColumns[] = 'c.created_by';
    }
    
    // WHERE Bedingungen basierend auf verfügbaren Spalten
    if (in_array('is_active', $availableColumns)) {
        $whereConditions[] = 'c.is_active = 1';
    }
    
    if ($classFilter !== 'all') {
        $whereConditions[] = 'c.id = ?';
        $params[] = $classFilter;
    }
    
    if ($yearFilter !== 'all' && in_array('school_year', $availableColumns)) {
        $whereConditions[] = 'c.school_year = ?';
        $params[] = $yearFilter;
    }
    
    // Schüleranzahl nur wenn students Tabelle existiert
    $studentCountSelect = "0 as student_count";
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE 'students'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $studentCountSelect = "COALESCE(COUNT(s.id), 0) as student_count";
        }
    } catch (Exception $e) {
        // Tabelle existiert nicht
    }
    
    $sql = "
        SELECT " . implode(', ', $selectColumns) . ", 
               " . $studentCountSelect . "
        FROM classes c";
    
    // JOIN nur wenn students Tabelle existiert
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE 'students'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $sql .= " LEFT JOIN students s ON c.id = s.class_id AND s.is_active = 1";
        }
    } catch (Exception $e) {
        // Tabelle existiert nicht
    }
    
    $sql .= " WHERE " . implode(' AND ', $whereConditions);
    $sql .= " GROUP BY c.id";
    
    // ORDER BY nur mit verfügbaren Spalten
    $orderBy = [];
    if (in_array('school_year', $availableColumns)) {
        $orderBy[] = 'c.school_year DESC';
    }
    $orderBy[] = 'c.name ASC';
    $sql .= " ORDER BY " . implode(', ', $orderBy);
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getSchoolYears($schoolId) {
    $db = getDB();
    
    // Prüfen ob school_year Spalte existiert
    $stmt = $db->prepare("SHOW COLUMNS FROM classes LIKE 'school_year'");
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        // Spalte existiert nicht, leeres Array zurückgeben
        return [];
    }
    
    // Prüfen ob is_active Spalte existiert
    $whereClause = "school_id = ?";
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM classes LIKE 'is_active'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $whereClause .= " AND is_active = 1";
        }
    } catch (Exception $e) {
        // Spalte existiert nicht, ignorieren
    }
    
    $stmt = $db->prepare("
        SELECT DISTINCT school_year 
        FROM classes 
        WHERE " . $whereClause . " AND school_year IS NOT NULL AND school_year != ''
        ORDER BY school_year DESC
    ");
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function createClass($className, $schoolYear, $schoolId, $userId) {
    $db = getDB();
    
    // Prüfen welche Spalten existieren
    $stmt = $db->prepare("SHOW COLUMNS FROM classes");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $insertColumns = ['name', 'school_id'];
    $insertValues = ['?', '?'];
    $insertParams = [$className, $schoolId];
    
    if (in_array('school_year', $columns) && !empty($schoolYear)) {
        $insertColumns[] = 'school_year';
        $insertValues[] = '?';
        $insertParams[] = $schoolYear;
    }
    
    if (in_array('created_by', $columns)) {
        $insertColumns[] = 'created_by';
        $insertValues[] = '?';
        $insertParams[] = $userId;
    }
    
    if (in_array('is_active', $columns)) {
        $insertColumns[] = 'is_active';
        $insertValues[] = '1';
    }
    
    if (in_array('created_at', $columns)) {
        $insertColumns[] = 'created_at';
        $insertValues[] = 'CURRENT_TIMESTAMP';
    }
    
    $sql = "INSERT INTO classes (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")";
    $stmt = $db->prepare($sql);
    
    return $stmt->execute($insertParams);
}

function updateClass($classId, $className, $schoolYear, $schoolId) {
    $db = getDB();
    
    // Prüfen ob Klasse zur Schule gehört
    $stmt = $db->prepare("SELECT id FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$classId, $schoolId]);
    if (!$stmt->fetch()) {
        return false;
    }
    
    // Prüfen welche Spalten existieren
    $stmt = $db->prepare("SHOW COLUMNS FROM classes");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $updateColumns = ['name = ?'];
    $updateParams = [$className];
    
    if (in_array('school_year', $columns)) {
        $updateColumns[] = 'school_year = ?';
        $updateParams[] = $schoolYear;
    }
    
    if (in_array('updated_at', $columns)) {
        $updateColumns[] = 'updated_at = CURRENT_TIMESTAMP';
    }
    
    $updateParams[] = $classId;
    $updateParams[] = $schoolId;
    
    $sql = "UPDATE classes SET " . implode(', ', $updateColumns) . " WHERE id = ? AND school_id = ?";
    $stmt = $db->prepare($sql);
    
    return $stmt->execute($updateParams);
}

function deleteClass($classId, $schoolId) {
    $db = getDB();
    
    // Prüfen ob is_active Spalte existiert
    $stmt = $db->prepare("SHOW COLUMNS FROM classes LIKE 'is_active'");
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        // Soft delete mit is_active
        $sql = "UPDATE classes SET is_active = 0";
        
        // Prüfen ob updated_at existiert
        $stmt = $db->prepare("SHOW COLUMNS FROM classes LIKE 'updated_at'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $sql .= ", updated_at = CURRENT_TIMESTAMP";
        }
        
        $sql .= " WHERE id = ? AND school_id = ?";
        $stmt = $db->prepare($sql);
    } else {
        // Hard delete falls is_active nicht existiert
        $stmt = $db->prepare("DELETE FROM classes WHERE id = ? AND school_id = ?");
    }
    
    return $stmt->execute([$classId, $schoolId]);
}

function getClassById($classId, $schoolId) {
    $db = getDB();
    
    $stmt = $db->prepare("SELECT * FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$classId, $schoolId]);
    return $stmt->fetch();
}

function countClassesForSchool($schoolId) {
    $db = getDB();
    
    $whereClause = "school_id = ?";
    
    // Prüfen ob is_active Spalte existiert
    $stmt = $db->prepare("SHOW COLUMNS FROM classes LIKE 'is_active'");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        $whereClause .= " AND is_active = 1";
    }
    
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM classes WHERE " . $whereClause);
    $stmt->execute([$schoolId]);
    $result = $stmt->fetch();
    
    return $result ? (int)$result['count'] : 0;
}
?>