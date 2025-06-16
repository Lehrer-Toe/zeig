<?php
require_once '../config.php';

// Schuladmin-Zugriff prüfen
$user = requireSchuladmin();
requireValidSchoolLicense($user['school_id']);

// Schuldaten laden
$school = getSchoolById($user['school_id']);
if (!$school) {
    die('Schule nicht gefunden.');
}

$errors = [];
$success = '';

// Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Sicherheitsfehler.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_class':
                // Neue Klasse erstellen
                $className = trim($_POST['class_name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                
                if (empty($className)) {
                    $errors[] = 'Klassenname ist erforderlich.';
                } else {
                    // Prüfen ob Klassenlimit erreicht
                    if (!canCreateClass($user['school_id'])) {
                        $errors[] = 'Maximale Anzahl Klassen erreicht (' . $school['max_classes'] . ').';
                    } else {
                        // Klasse erstellen - OHNE created_by
                        $db = getDB();
                        $stmt = $db->prepare("
                            INSERT INTO classes (school_id, name, description) 
                            VALUES (?, ?, ?)
                        ");
                        
                        if ($stmt->execute([$user['school_id'], $className, $description])) {
                            $success = 'Klasse erfolgreich erstellt.';
                        } else {
                            $errors[] = 'Fehler beim Erstellen der Klasse.';
                        }
                    }
                }
                break;
                
            case 'upload_students':
                
            case 'add_student':
                // Schüler hinzufügen
                $classId = (int)($_POST['class_id'] ?? 0);
                $firstName = trim($_POST['first_name'] ?? '');
                $lastName = trim($_POST['last_name'] ?? '');
                $birthDate = formatDateForDB($_POST['birth_date'] ?? '');
                
                if (empty($firstName) || empty($lastName)) {
                    $errors[] = 'Vor- und Nachname sind erforderlich.';
                } elseif (!$classId) {
                    $errors[] = 'Ungültige Klassen-ID.';
                } else {
                    // Prüfen ob Schülerlimit erreicht
                    if (!canAddStudentToClass($classId)) {
                        $errors[] = 'Maximale Schülerzahl für diese Klasse erreicht (' . $school['max_students_per_class'] . ').';
                    } else {
                        $db = getDB();
                        $stmt = $db->prepare("
                            INSERT INTO students (school_id, class_id, first_name, last_name, birth_date) 
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        
                        if ($stmt->execute([$user['school_id'], $classId, $firstName, $lastName, $birthDate])) {
                            $success = 'Schüler erfolgreich hinzugefügt.';
                        } else {
                            $errors[] = 'Fehler beim Hinzufügen des Schülers.';
                        }
                    }
                }
                break;

            case 'update_student':
                // Schüler bearbeiten
                $studentId = (int)($_POST['student_id'] ?? 0);
                $firstName = trim($_POST['edit_first_name'] ?? '');
                $lastName = trim($_POST['edit_last_name'] ?? '');
                $birthDate = formatDateForDB($_POST['edit_birth_date'] ?? '');
                
                if (empty($firstName) || empty($lastName)) {
                    $errors[] = 'Vor- und Nachname sind erforderlich.';
                } elseif (!$studentId) {
                    $errors[] = 'Ungültige Schüler-ID.';
                } else {
                    $db = getDB();
                    $stmt = $db->prepare("
                        UPDATE students 
                        SET first_name = ?, last_name = ?, birth_date = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND school_id = ?
                    ");
                    
                    if ($stmt->execute([$firstName, $lastName, $birthDate, $studentId, $user['school_id']])) {
                        $success = 'Schüler erfolgreich aktualisiert.';
                    } else {
                        $errors[] = 'Fehler beim Aktualisieren des Schülers.';
                    }
                }
                break;
                
            case 'update_class':
                // Klasse bearbeiten
                $classId = (int)($_POST['edit_class_id'] ?? 0);
                $className = trim($_POST['edit_class_name'] ?? '');
                $description = trim($_POST['edit_description'] ?? '');
                
                if (empty($className)) {
                    $errors[] = 'Klassenname ist erforderlich.';
                } elseif (!$classId) {
                    $errors[] = 'Ungültige Klassen-ID.';
                } else {
                    $db = getDB();
                    $stmt = $db->prepare("
                        UPDATE classes 
                        SET name = ?, description = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND school_id = ?
                    ");
                    
                    if ($stmt->execute([$className, $description, $classId, $user['school_id']])) {
                        $success = 'Klasse erfolgreich aktualisiert.';
                    } else {
                        $errors[] = 'Fehler beim Aktualisieren der Klasse.';
                    }
                }
                break;
                // Schüler aus Datei hochladen
                $classId = (int)($_POST['upload_class_id'] ?? 0);
                $nameFormat = $_POST['name_format'] ?? 'firstname_lastname';
                
                if (!$classId) {
                    $errors[] = 'Bitte wählen Sie eine Klasse aus.';
                } elseif (!isset($_FILES['student_file']) || $_FILES['student_file']['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Bitte wählen Sie eine gültige Datei aus.';
                } else {
                    $file = $_FILES['student_file'];
                    $fileName = $file['name'];
                    $fileTmpName = $file['tmp_name'];
                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    
                    $allowedExtensions = ['csv', 'txt', 'xlsx', 'xls'];
                    
                    if (!in_array($fileExtension, $allowedExtensions)) {
                        $errors[] = 'Ungültiger Dateityp. Erlaubt: CSV, TXT, Excel (XLSX, XLS)';
                    } else {
                        // Datei verarbeiten
                        $students = [];
                        
                        if (in_array($fileExtension, ['csv', 'txt'])) {
                            // CSV/TXT verarbeiten
                            $fileContent = file_get_contents($fileTmpName);
                            $lines = explode("\n", $fileContent);
                            
                            foreach ($lines as $line) {
                                $line = trim($line);
                                if (empty($line)) continue;
                                
                                // Verschiedene Trennzeichen unterstützen
                                $parts = [];
                                if (strpos($line, ',') !== false) {
                                    $parts = explode(',', $line);
                                } elseif (strpos($line, ';') !== false) {
                                    $parts = explode(';', $line);
                                } elseif (strpos($line, "\t") !== false) {
                                    $parts = explode("\t", $line);
                                } else {
                                    $parts = explode(' ', $line, 2);
                                }
                                
                                if (count($parts) >= 2) {
                                    $part1 = trim($parts[0]);
                                    $part2 = trim($parts[1]);
                                    
                                    if ($nameFormat === 'firstname_lastname') {
                                        $students[] = ['first_name' => $part1, 'last_name' => $part2];
                                    } else {
                                        $students[] = ['first_name' => $part2, 'last_name' => $part1];
                                    }
                                } elseif (count($parts) === 1) {
                                    // Nur ein Name - als Nachname verwenden
                                    $students[] = ['first_name' => 'Vorname', 'last_name' => trim($parts[0])];
                                }
                            }
                        } elseif (in_array($fileExtension, ['xlsx', 'xls'])) {
                            // Excel verarbeiten (vereinfacht)
                            $errors[] = 'Excel-Import wird in einer zukünftigen Version unterstützt. Bitte verwenden Sie CSV/TXT.';
                        }
                        
                        if (!empty($students) && empty($errors)) {
                            // Schüler in Datenbank einfügen
                            $db = getDB();
                            $addedCount = 0;
                            $skippedCount = 0;
                            
                            foreach ($students as $student) {
                                if (empty($student['first_name']) || empty($student['last_name'])) {
                                    $skippedCount++;
                                    continue;
                                }
                                
                                // Prüfen ob Schülerlimit erreicht
                                $currentCount = getClassStudentCount($classId);
                                if ($currentCount >= $school['max_students_per_class']) {
                                    $errors[] = 'Klassenlimit erreicht. Weitere Schüler übersprungen.';
                                    break;
                                }
                                
                                $stmt = $db->prepare("
                                    INSERT INTO students (school_id, class_id, first_name, last_name) 
                                    VALUES (?, ?, ?, ?)
                                ");
                                
                                if ($stmt->execute([$user['school_id'], $classId, $student['first_name'], $student['last_name']])) {
                                    $addedCount++;
                                } else {
                                    $skippedCount++;
                                }
                            }
                            
                            if ($addedCount > 0) {
                                $success = $addedCount . ' Schüler erfolgreich hinzugefügt.';
                                if ($skippedCount > 0) {
                                    $success .= ' ' . $skippedCount . ' Einträge übersprungen.';
                                }
                            } else {
                                $errors[] = 'Keine Schüler konnten hinzugefügt werden.';
                            }
                        } elseif (empty($students)) {
                            $errors[] = 'Keine gültigen Schülerdaten in der Datei gefunden.';
                        }
                    }
                }
                break;
                
            case 'delete_class':
                $classId = (int)($_POST['class_id'] ?? 0);
                if ($classId) {
                    $db = getDB();
                    $stmt = $db->prepare("UPDATE classes SET is_active = 0 WHERE id = ? AND school_id = ?");
                    if ($stmt->execute([$classId, $user['school_id']])) {
                        $success = 'Klasse erfolgreich gelöscht.';
                    } else {
                        $errors[] = 'Fehler beim Löschen der Klasse.';
                    }
                }
                break;
                
            case 'delete_student':
                $studentId = (int)($_POST['student_id'] ?? 0);
                if ($studentId) {
                    $db = getDB();
                    $stmt = $db->prepare("
                        UPDATE students 
                        SET is_active = 0 
                        WHERE id = ? AND school_id = ?
                    ");
                    if ($stmt->execute([$studentId, $user['school_id']])) {
                        $success = 'Schüler erfolgreich entfernt.';
                    } else {
                        $errors[] = 'Fehler beim Entfernen des Schülers.';
                    }
                }
                break;
        }
    }
}

// Klassen laden - OHNE created_by und ohne users JOIN
$db = getDB();
$stmt = $db->prepare("
    SELECT c.*, 
           COUNT(s.id) as student_count
    FROM classes c 
    LEFT JOIN students s ON c.id = s.class_id AND s.is_active = 1
    WHERE c.school_id = ? AND c.is_active = 1 
    GROUP BY c.id 
    ORDER BY c.name ASC
");
$stmt->execute([$user['school_id']]);
$classes = $stmt->fetchAll();

// AJAX-Requests für Modal-Daten
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['ajax']) {
        case 'get_class':
            $classId = (int)($_GET['id'] ?? 0);
            if ($classId) {
                $stmt = $db->prepare("SELECT * FROM classes WHERE id = ? AND school_id = ?");
                $stmt->execute([$classId, $user['school_id']]);
                $class = $stmt->fetch();
                
                if ($class) {
                    echo json_encode(['success' => true, 'class' => $class]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Klasse nicht gefunden']);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Ungültige ID']);
            }
            exit;
            
        case 'get_students':
            $classId = (int)($_GET['class_id'] ?? 0);
            if ($classId) {
                $stmt = $db->prepare("
                    SELECT * FROM students 
                    WHERE class_id = ? AND school_id = ? AND is_active = 1 
                    ORDER BY last_name, first_name
                ");
                $stmt->execute([$classId, $user['school_id']]);
                $students = $stmt->fetchAll();
                
                echo json_encode(['success' => true, 'students' => $students]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Ungültige Klassen-ID']);
            }
            exit;
    }
}


?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Klassenverwaltung - <?php echo APP_NAME; ?></title>
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

        .header {
            background: rgba(0, 0, 0, 0.3);
            border-bottom: 1px solid rgba(59, 130, 246, 0.2);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(10px);
        }

        .header h1 {
            color: #3b82f6;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .breadcrumb {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-top: 0.25rem;
        }

        .breadcrumb a {
            color: #3b82f6;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.5rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(100, 116, 139, 0.2);
            color: #cbd5e1;
            border: 1px solid rgba(100, 116, 139, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(100, 116, 139, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #16a34a, #15803d);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 2rem;
            color: #3b82f6;
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            opacity: 0.8;
            line-height: 1.5;
        }

        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .flash-message {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 2rem;
            font-weight: 500;
        }

        .flash-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
        }

        .flash-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .error-list {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 2rem;
        }

        .error-list h4 {
            color: #fca5a5;
            margin-bottom: 0.5rem;
        }

        .error-list ul {
            color: #fca5a5;
            margin-left: 1.5rem;
        }

        .upload-section {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
        }

        .upload-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .upload-header h3 {
            color: #3b82f6;
            font-size: 1.3rem;
        }

        .upload-info {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .upload-info h4 {
            color: #fbbf24;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .upload-info ul {
            color: #fbbf24;
            margin-left: 1.5rem;
            font-size: 0.9rem;
        }

        .upload-form {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 1rem;
            align-items: end;
        }

        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 1.5rem;
        }

        .class-card {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(100, 116, 139, 0.2);
            border-radius: 1rem;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .class-card:hover {
            border-color: #3b82f6;
            transform: translateY(-2px);
        }

        .class-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .class-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: #3b82f6;
            margin-bottom: 0.25rem;
        }

        .class-stats {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 0.5rem;
            padding: 1rem;
            margin: 1rem 0;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .stat-item:last-child {
            margin-bottom: 0;
        }

        .class-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .students-list {
            margin-top: 1rem;
        }

        .students-list h4 {
            font-size: 1rem;
            margin-bottom: 0.5rem;
            color: #3b82f6;
        }

        .student-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: rgba(0, 0, 0, 0.9);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 1rem;
            margin: 5% auto;
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            backdrop-filter: blur(10px);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-title {
            color: #3b82f6;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .close {
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
        }

        .close:hover {
            color: #e2e8f0;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #cbd5e1;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            color: white;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: #64748b;
        }

        .form-group select option {
            background: #1e293b;
            color: white;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            opacity: 0.7;
        }

        .empty-state .icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #64748b;
        }

        .file-input-wrapper {
            position: relative;
            display: inline-block;
            width: 100%;
        }

        .file-input {
            position: absolute;
            left: -9999px;
        }

        .file-input-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: rgba(100, 116, 139, 0.2);
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            color: #cbd5e1;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            justify-content: center;
        }

        .file-input-button:hover {
            background: rgba(100, 116, 139, 0.3);
        }

        .file-selected {
            color: #3b82f6;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .classes-grid {
                grid-template-columns: 1fr;
            }
            
            .controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .upload-form {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                margin: 10% auto;
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>🏫 Klassenverwaltung</h1>
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a> / Klassenverwaltung
            </div>

    <!-- Modal: Klasse bearbeiten -->
    <div id="editClassModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3 class="modal-title">Klasse bearbeiten</h3>
                <span class="close" onclick="closeModal('editClassModal')">&times;</span>
            </div>
            
            <!-- Klassen-Info bearbeiten -->
            <form method="POST" action="" style="margin-bottom: 2rem;">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_class">
                <input type="hidden" id="edit_class_id" name="edit_class_id" value="">
                
                <div class="form-group">
                    <label for="edit_class_name">Klassenname *</label>
                    <input type="text" id="edit_class_name" name="edit_class_name" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_description">Beschreibung</label>
                    <textarea id="edit_description" name="edit_description" rows="2"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-success">Klasse aktualisieren</button>
                </div>
            </form>
            
            <hr style="border: 1px solid rgba(100, 116, 139, 0.3); margin: 2rem 0;">
            
            <!-- Schüler verwalten -->
            <h4 style="color: #3b82f6; margin-bottom: 1rem;">👥 Schüler verwalten</h4>
            
            <!-- Neuen Schüler hinzufügen -->
            <form method="POST" action="" style="margin-bottom: 1.5rem; padding: 1rem; background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 0.5rem;">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="add_student">
                <input type="hidden" id="add_to_class_id" name="class_id" value="">
                
                <h5 style="color: #22c55e; margin-bottom: 0.5rem;">➕ Neuen Schüler hinzufügen</h5>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 0.5rem; align-items: end;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <input type="text" name="first_name" placeholder="Vorname" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <input type="text" name="last_name" placeholder="Nachname" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <input type="date" name="birth_date">
                    </div>
                    <button type="submit" class="btn btn-success btn-sm">Hinzufügen</button>
                </div>
            </form>
            
            <!-- Schülerliste -->
            <div id="studentsList" style="max-height: 400px; overflow-y: auto;">
                <!-- Wird dynamisch gefüllt -->
            </div>
        </div>
    </div>

    <!-- Modal: Schüler bearbeiten -->
    <div id="editStudentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Schüler bearbeiten</h3>
                <span class="close" onclick="closeModal('editStudentModal')">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_student">
                <input type="hidden" id="edit_student_id" name="student_id" value="">
                
                <div class="form-group">
                    <label for="edit_first_name">Vorname *</label>
                    <input type="text" id="edit_first_name" name="edit_first_name" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_last_name">Nachname *</label>
                    <input type="text" id="edit_last_name" name="edit_last_name" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_birth_date">Geburtsdatum</label>
                    <input type="date" id="edit_birth_date" name="edit_birth_date">
                </div>
                
                <div class="form-actions">
                    <button type="button" onclick="closeModal('editStudentModal')" class="btn btn-secondary">
                        Abbrechen
                    </button>
                    <button type="submit" class="btn btn-success">
                        Speichern
                    </button>
                </div>
            </form>
        </div>
    </div>
        </div>
        <a href="dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
    </div>

    <div class="container">
        <?php if ($flashMessage): ?>
            <div class="flash-message flash-<?php echo $flashMessage['type']; ?>">
                <?php echo escape($flashMessage['message']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="error-list">
                <h4>⚠️ Fehler:</h4>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo escape($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="flash-message flash-success">
                <?php echo escape($success); ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h2 class="page-title">Klassenverwaltung</h2>
            <p class="page-subtitle">
                Verwalten Sie Ihre Schulklassen und Schüler. 
                Sie können bis zu <?php echo $school['max_classes']; ?> Klassen mit jeweils 
                maximal <?php echo $school['max_students_per_class']; ?> Schülern erstellen.
            </p>
        </div>

        <!-- Neue Klasse anlegen -->
        <div class="upload-section">
            <div class="upload-header">
                <h3>📝 Neue Klasse anlegen</h3>
            </div>
            <form method="POST" action="" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 1rem; align-items: end;">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="create_class">
                
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Klassenname (z.B. 9a, 10b)</label>
                    <input type="text" name="class_name" required placeholder="z.B. 9a, 10b">
                </div>
                
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Schuljahr</label>
                    <select name="school_year">
                        <option value="2025/26">2025/26</option>
                        <option value="2024/25">2024/25</option>
                        <option value="2026/27">2026/27</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-success">Klasse anlegen</button>
            </form>
        </div>

        <!-- Schüler aus Datei hochladen -->
        <div class="upload-section">
            <div class="upload-header">
                <h3>📁 Schüler aus Datei hochladen</h3>
            </div>
            
            <div class="upload-info">
                <h4>Unterstützte Formate:</h4>
                <ul>
                    <li><strong>CSV/TXT:</strong> Ein Schüler pro Zeile</li>
                    <li><strong>Excel (XLSX):</strong> Erste Spalte mit Schülernamen</li>
                </ul>
                <p style="margin-top: 0.5rem;"><strong>Beispiele:</strong></p>
                <ul>
                    <li>Vorname Nachname: "Max Mustermann"</li>
                    <li>Nachname, Vorname: "Mustermann, Max"</li>
                </ul>
            </div>

            <form method="POST" action="" enctype="multipart/form-data" class="upload-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="upload_students">
                
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Klasse auswählen...</label>
                    <select name="upload_class_id" required>
                        <option value="">Klasse auswählen...</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>">
                                <?php echo escape($class['name']); ?> 
                                (<?php echo $class['student_count']; ?>/<?php echo $school['max_students_per_class']; ?> Schüler)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Format</label>
                    <select name="name_format">
                        <option value="firstname_lastname">Vorname Nachname</option>
                        <option value="lastname_firstname">Nachname, Vorname</option>
                    </select>
                </div>
                
                <div class="form-group" style="margin-bottom: 0;">
                    <div class="file-input-wrapper">
                        <input type="file" id="student_file" name="student_file" class="file-input" 
                               accept=".csv,.txt,.xlsx,.xls" onchange="updateFileName(this)">
                        <label for="student_file" class="file-input-button">
                            📁 Datei auswählen
                        </label>
                        <div id="file-selected" class="file-selected" style="display: none;"></div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-success">Schüler hochladen</button>
            </form>
        </div>

        <div class="controls">
            <div>
                <strong><?php echo count($classes); ?></strong> von <strong><?php echo $school['max_classes']; ?></strong> Klassen erstellt
            </div>
            <button onclick="openAddStudentModal()" class="btn btn-primary">
                👤➕ Einzelnen Schüler hinzufügen
            </button>
        </div>

        <?php if (empty($classes)): ?>
            <div class="empty-state">
                <div class="icon">🏫</div>
                <h3>Noch keine Klassen erstellt</h3>
                <p>Erstellen Sie Ihre erste Klasse, um mit der Verwaltung zu beginnen.</p>
            </div>
        <?php else: ?>
            <div class="classes-grid">
                <?php foreach ($classes as $class): ?>
                    <div class="class-card">
                        <div class="class-header">
                            <div>
                                <div class="class-name"><?php echo escape($class['name']); ?></div>
                            </div>
                        </div>

                        <?php if ($class['description']): ?>
                            <p style="margin-bottom: 1rem; opacity: 0.8;">
                                <?php echo escape($class['description']); ?>
                            </p>
                        <?php endif; ?>

                        <div class="class-stats">
                            <div class="stat-item">
                                <span>👥 Schülerzahl:</span>
                                <span><?php echo $class['student_count']; ?> / <?php echo $school['max_students_per_class']; ?></span>
                            </div>
                            <div class="stat-item">
                                <span>📅 Erstellt:</span>
                                <span><?php echo formatDate($class['created_at']); ?></span>
                            </div>
                        </div>

                        <?php
                        // Schüler für diese Klasse laden
                        $stmt = $db->prepare("
                            SELECT * FROM students 
                            WHERE class_id = ? AND is_active = 1 
                            ORDER BY last_name, first_name
                        ");
                        $stmt->execute([$class['id']]);
                        $students = $stmt->fetchAll();
                        ?>

                        <?php if (!empty($students)): ?>
                            <div class="students-list">
                                <h4>Schüler (<?php echo count($students); ?>):</h4>
                                <?php foreach (array_slice($students, 0, 3) as $student): ?>
                                    <div class="student-item">
                                        <span><?php echo escape($student['first_name'] . ' ' . $student['last_name']); ?></span>
                                        <button onclick="deleteStudent(<?php echo $student['id']; ?>)" 
                                                class="btn btn-danger btn-sm">🗑️</button>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if (count($students) > 3): ?>
                                    <div style="text-align: center; margin-top: 0.5rem; opacity: 0.7;">
                                        ... und <?php echo count($students) - 3; ?> weitere
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="class-actions">
                            <button onclick="openEditClassModal(<?php echo $class['id']; ?>)" 
                                    class="btn btn-primary btn-sm">
                                ✏️ Klasse bearbeiten
                            </button>
                            <button onclick="deleteClass(<?php echo $class['id']; ?>)" 
                                    class="btn btn-danger btn-sm">
                                🗑️ Klasse löschen
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal: Schüler hinzufügen -->
    <div id="addStudentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Schüler hinzufügen</h3>
                <span class="close" onclick="closeModal('addStudentModal')">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="add_student">
                <input type="hidden" id="student_class_id" name="class_id" value="">
                
                <div class="form-group">
                    <label for="modal_class_select">Klasse</label>
                    <select id="modal_class_select" name="class_id" required>
                        <option value="">Klasse auswählen...</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>">
                                <?php echo escape($class['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="first_name">Vorname *</label>
                    <input type="text" id="first_name" name="first_name" required>
                </div>
                
                <div class="form-group">
                    <label for="last_name">Nachname *</label>
                    <input type="text" id="last_name" name="last_name" required>
                </div>
                
                <div class="form-group">
                    <label for="birth_date">Geburtsdatum</label>
                    <input type="date" id="birth_date" name="birth_date">
                </div>
                
                <div class="form-actions">
                    <button type="button" onclick="closeModal('addStudentModal')" class="btn btn-secondary">
                        Abbrechen
                    </button>
                    <button type="submit" class="btn btn-primary">
                        Schüler hinzufügen
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function updateFileName(input) {
            const fileSelected = document.getElementById('file-selected');
            if (input.files && input.files[0]) {
                fileSelected.textContent = '📎 ' + input.files[0].name;
                fileSelected.style.display = 'block';
            } else {
                fileSelected.style.display = 'none';
            }
        }

        function openAddStudentModal() {
            document.getElementById('addStudentModal').style.display = 'block';
        }

        function openAddStudentModalForClass(classId) {
            document.getElementById('student_class_id').value = classId;
            document.getElementById('modal_class_select').value = classId;
            document.getElementById('addStudentModal').style.display = 'block';
        }

        function openEditClassModal(classId) {
            // Modal öffnen
            document.getElementById('editClassModal').style.display = 'block';
            
            // Klassen-ID setzen
            document.getElementById('edit_class_id').value = classId;
            document.getElementById('add_to_class_id').value = classId;
            
            // Klassendaten laden und Formular befüllen
            fetch('admin_klassen.php?ajax=get_class&id=' + classId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('edit_class_name').value = data.class.name || '';
                        document.getElementById('edit_description').value = data.class.description || '';
                        loadStudentsList(classId);
                    }
                })
                .catch(error => {
                    console.error('Fehler beim Laden der Klassendaten:', error);
                    // Fallback: Schülerliste trotzdem laden
                    loadStudentsList(classId);
                });
        }

        function loadStudentsList(classId) {
            fetch('admin_klassen.php?ajax=get_students&class_id=' + classId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const studentsList = document.getElementById('studentsList');
                        let html = '';
                        
                        if (data.students.length === 0) {
                            html = '<div style="text-align: center; padding: 2rem; opacity: 0.7;">Noch keine Schüler in dieser Klasse</div>';
                        } else {
                            data.students.forEach(student => {
                                html += `
                                    <div class="student-item" style="margin-bottom: 0.5rem;">
                                        <span>${student.first_name} ${student.last_name}</span>
                                        <div style="display: flex; gap: 0.5rem;">
                                            <button onclick="editStudent(${student.id}, '${student.first_name}', '${student.last_name}', '${student.birth_date || ''}')" 
                                                    class="btn btn-secondary btn-sm">✏️</button>
                                            <button onclick="deleteStudent(${student.id})" 
                                                    class="btn btn-danger btn-sm">🗑️</button>
                                        </div>
                                    </div>
                                `;
                            });
                        }
                        
                        studentsList.innerHTML = html;
                    }
                })
                .catch(error => {
                    console.error('Fehler beim Laden der Schülerliste:', error);
                    document.getElementById('studentsList').innerHTML = 
                        '<div style="color: #ef4444;">Fehler beim Laden der Schülerliste</div>';
                });
        }

        function editStudent(studentId, firstName, lastName, birthDate) {
            document.getElementById('edit_student_id').value = studentId;
            document.getElementById('edit_first_name').value = firstName;
            document.getElementById('edit_last_name').value = lastName;
            document.getElementById('edit_birth_date').value = birthDate;
            document.getElementById('editStudentModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function deleteClass(classId) {
            if (confirm('Klasse wirklich löschen? Alle Schüler und Bewertungen gehen verloren!')) {
                const formData = new FormData();
                formData.append('action', 'delete_class');
                formData.append('class_id', classId);
                formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

                fetch('admin_klassen.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(() => {
                    location.reload();
                })
                .catch(error => {
                    alert('Ein Fehler ist aufgetreten.');
                    console.error(error);
                });
            }
        }

        function deleteStudent(studentId) {
            if (confirm('Schüler wirklich entfernen?')) {
                const formData = new FormData();
                formData.append('action', 'delete_student');
                formData.append('student_id', studentId);
                formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

                fetch('admin_klassen.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(() => {
                    location.reload();
                })
                .catch(error => {
                    alert('Ein Fehler ist aufgetreten.');
                    console.error(error);
                });
            }
        }

        function viewAllStudents(classId) {
            alert('Detaillierte Schülerübersicht wird in einer zukünftigen Version implementiert.');
        }

        // Modal schließen wenn außerhalb geklickt wird
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
