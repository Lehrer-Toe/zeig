<?php
require_once '../config.php';

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Schuladmin-Zugriff prüfen
$user = requireSchuladmin();
requireValidSchoolLicense($user['school_id']);

// Schuldaten laden
$school = getSchoolById($user['school_id']);
if (!$school) {
    die('Schule nicht gefunden.');
}

$db = getDB();
$messages = [];
$errors = [];

// Datenbankschema prüfen und erweitern
$hasGroupsColumn = false;
$hasPasswordAdminColumn = false;

try {
    // Prüfen ob can_create_groups Spalte existiert
    $stmt = $db->prepare("SHOW COLUMNS FROM users LIKE 'can_create_groups'");
    $stmt->execute();
    $hasGroupsColumn = $stmt->rowCount() > 0;
    
    // Prüfen ob password_set_by_admin Spalte existiert, sonst hinzufügen
    $stmt = $db->prepare("SHOW COLUMNS FROM users LIKE 'password_set_by_admin'");
    $stmt->execute();
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE users ADD COLUMN password_set_by_admin TINYINT(1) DEFAULT 1 AFTER first_login");
        $hasPasswordAdminColumn = true;
    } else {
        $hasPasswordAdminColumn = true;
    }
    
    // Prüfen ob admin_password Spalte existiert, sonst hinzufügen
    $stmt = $db->prepare("SHOW COLUMNS FROM users LIKE 'admin_password'");
    $stmt->execute();
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE users ADD COLUMN admin_password VARCHAR(255) NULL AFTER password_set_by_admin");
    }
} catch (Exception $e) {
    error_log("Error updating schema: " . $e->getMessage());
}

// Helper function für sichere Passwort-Generierung
function generateSecurePassword($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

// Helper function für Namen-Parsing (ähnlich wie bei Schülern)
function parseTeacherName($fullName, $format) {
    $fullName = trim($fullName);
    
    if (empty($fullName) || strlen($fullName) < 2) {
        return null;
    }
    
    // Sonderzeichen entfernen (außer Buchstaben inkl. Umlaute, Leerzeichen, Bindestrich, Komma)
    $fullName = preg_replace('/[^\p{L}\s\-,\.]/u', '', $fullName);
    $fullName = trim($fullName);
    
    // Prüfen ob es ein einzelner Name ist (kein Leerzeichen)
    if (!strpos($fullName, ' ') && strpos($fullName, ',') === false) {
        // Einzelner Name - als Nachnamen interpretieren
        return $fullName;
    }
    
    switch ($format) {
        case 'vorname_nachname':
            // "Max Mustermann" oder "Max Peter Mustermann"
            $parts = preg_split('/\s+/', $fullName);
            if (count($parts) >= 2) {
                $firstName = $parts[0];
                $lastName = implode(' ', array_slice($parts, 1));
                return $firstName . ' ' . $lastName;
            }
            break;
            
        case 'nachname_vorname':
            // "Mustermann, Max" oder "Mustermann, Max Peter"
            if (strpos($fullName, ',') !== false) {
                $parts = array_map('trim', explode(',', $fullName, 2));
                $lastName = $parts[0];
                $firstName = isset($parts[1]) ? $parts[1] : '';
                return trim($firstName . ' ' . $lastName);
            } else {
                // Fallback: letztes Wort als Nachname, Rest als Vorname
                $parts = preg_split('/\s+/', $fullName);
                if (count($parts) >= 2) {
                    $lastName = array_pop($parts);
                    $firstName = implode(' ', $parts);
                    return trim($firstName . ' ' . $lastName);
                }
            }
            break;
    }
    
    // Fallback: Name so wie er ist
    return $fullName;
}

// Helper function für CSV/TXT-Verarbeitung
function parseTeacherFile($filePath, $nameFormat) {
    $teachers = [];
    
    if (!file_exists($filePath) || !is_readable($filePath)) {
        throw new Exception('Datei konnte nicht gelesen werden.');
    }
    
    $content = file_get_contents($filePath);
    if ($content === false || empty($content)) {
        throw new Exception('Datei ist leer oder konnte nicht gelesen werden.');
    }
    
    // BOM entfernen falls vorhanden
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    
    // Zeilen aufteilen
    $lines = preg_split('/\r\n|\r|\n/', $content);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Wenn die Zeile keine Trennzeichen enthält, nehmen wir die ganze Zeile als Namen
        if (!strpos($line, ',') && !strpos($line, ';') && !strpos($line, "\t") && !strpos($line, '|')) {
            // Direkt als Name verwenden
            $name = parseTeacherName($line, $nameFormat);
            if ($name) {
                $teachers[] = ['name' => $name, 'email' => ''];
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
            
            // Prüfen ob mindestens Name vorhanden
            if (count($bestParts) >= 1) {
                $name = parseTeacherName(trim($bestParts[0]), $nameFormat);
                $email = isset($bestParts[1]) ? trim($bestParts[1]) : '';
                
                if ($name) {
                    $teachers[] = ['name' => $name, 'email' => $email];
                }
            }
        }
    }
    
    return $teachers;
}

// POST-Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Ungültiger CSRF-Token.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_teacher':
                $name = trim($_POST['teacher_name'] ?? '');
                $email = trim($_POST['teacher_email'] ?? '');
                $password = trim($_POST['teacher_password'] ?? '');
                
                if (empty($name) || empty($email)) {
                    $errors[] = 'Name und E-Mail sind Pflichtfelder.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Ungültige E-Mail-Adresse.';
                } else {
                    // Prüfen ob E-Mail bereits existiert
                    $existingUser = getUserByEmail($email);
                    if ($existingUser) {
                        $errors[] = 'Ein Benutzer mit dieser E-Mail-Adresse existiert bereits.';
                    } else {
                        // Automatisches Passwort generieren wenn keines eingegeben
                        if (empty($password)) {
                            $password = generateSecurePassword();
                        }
                        
                        // Erweiterte User-Erstellung mit Admin-Passwort
                        $db = getDB();
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        
                        $stmt = $db->prepare("
                            INSERT INTO users (email, password_hash, user_type, name, school_id, first_login, password_set_by_admin, admin_password) 
                            VALUES (?, ?, 'lehrer', ?, ?, 1, 1, ?)
                        ");
                        
                        if ($stmt->execute([$email, $passwordHash, $name, $user['school_id'], $password])) {
                            $messages[] = "Lehrer '$name' wurde erfolgreich erstellt. Passwort: $password";
                        } else {
                            $errors[] = 'Fehler beim Erstellen des Lehrers.';
                        }
                    }
                }
                break;
                
            case 'update_teacher':
                $teacherId = (int)($_POST['teacher_id'] ?? 0);
                $newName = trim($_POST['teacher_name'] ?? '');
                $newEmail = trim($_POST['teacher_email'] ?? '');
                
                if (empty($newName) || empty($newEmail)) {
                    $errors[] = 'Name und E-Mail sind Pflichtfelder.';
                } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Ungültige E-Mail-Adresse.';
                } else {
                    // Prüfen ob E-Mail bereits von anderem User verwendet wird
                    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$newEmail, $teacherId]);
                    if ($stmt->fetch()) {
                        $errors[] = 'Diese E-Mail-Adresse wird bereits von einem anderen Benutzer verwendet.';
                    } else {
                        // Prüfen ob Lehrer zur Schule gehört
                        $stmt = $db->prepare("SELECT id, name FROM users WHERE id = ? AND school_id = ? AND user_type = 'lehrer'");
                        $stmt->execute([$teacherId, $user['school_id']]);
                        $teacher = $stmt->fetch();
                        
                        if (!$teacher) {
                            $errors[] = 'Lehrer nicht gefunden.';
                        } else {
                            $stmt = $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                            if ($stmt->execute([$newName, $newEmail, $teacherId])) {
                                $messages[] = "Lehrerdaten für '$newName' wurden erfolgreich aktualisiert.";
                            } else {
                                $errors[] = 'Fehler beim Aktualisieren der Lehrerdaten.';
                            }
                        }
                    }
                }
                break;
                
            case 'update_password':
                $teacherId = (int)($_POST['teacher_id'] ?? 0);
                $newPassword = trim($_POST['new_password'] ?? '');
                
                if (empty($newPassword)) {
                    $newPassword = generateSecurePassword();
                }
                
                // Prüfen ob Lehrer zur Schule gehört
                $stmt = $db->prepare("SELECT id, name FROM users WHERE id = ? AND school_id = ? AND user_type = 'lehrer'");
                $stmt->execute([$teacherId, $user['school_id']]);
                $teacher = $stmt->fetch();
                
                if (!$teacher) {
                    $errors[] = 'Lehrer nicht gefunden.';
                } else {
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("
                        UPDATE users 
                        SET password_hash = ?, first_login = 1, password_set_by_admin = 1, admin_password = ?, updated_at = CURRENT_TIMESTAMP 
                        WHERE id = ?
                    ");
                    if ($stmt->execute([$passwordHash, $newPassword, $teacherId])) {
                        $messages[] = "Passwort für '{$teacher['name']}' wurde erfolgreich geändert. Neues Passwort: $newPassword";
                    } else {
                        $errors[] = 'Fehler beim Ändern des Passworts.';
                    }
                }
                break;
                
            case 'delete_teacher':
                $teacherId = (int)($_POST['teacher_id'] ?? 0);
                
                // Prüfen ob Lehrer zur Schule gehört
                $stmt = $db->prepare("SELECT id, name FROM users WHERE id = ? AND school_id = ? AND user_type = 'lehrer'");
                $stmt->execute([$teacherId, $user['school_id']]);
                $teacher = $stmt->fetch();
                
                if (!$teacher) {
                    $errors[] = 'Lehrer nicht gefunden.';
                } else {
                    // Soft delete - is_active auf 0 setzen
                    $stmt = $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
                    if ($stmt->execute([$teacherId])) {
                        $messages[] = "Lehrer '{$teacher['name']}' wurde erfolgreich gelöscht.";
                    } else {
                        $errors[] = 'Fehler beim Löschen des Lehrers.';
                    }
                }
                break;
                
            case 'toggle_group_permission':
                if (!$hasGroupsColumn) {
                    $errors[] = 'Gruppenberechtigungen sind in dieser Installation nicht verfügbar.';
                    break;
                }
                
                $teacherId = (int)($_POST['teacher_id'] ?? 0);
                
                // Prüfen ob Lehrer zur Schule gehört
                $stmt = $db->prepare("SELECT id, name, can_create_groups FROM users WHERE id = ? AND school_id = ? AND user_type = 'lehrer'");
                $stmt->execute([$teacherId, $user['school_id']]);
                $teacher = $stmt->fetch();
                
                if (!$teacher) {
                    $errors[] = 'Lehrer nicht gefunden.';
                } else {
                    $newPermission = $teacher['can_create_groups'] ? 0 : 1;
                    $stmt = $db->prepare("UPDATE users SET can_create_groups = ? WHERE id = ?");
                    if ($stmt->execute([$newPermission, $teacherId])) {
                        $status = $newPermission ? 'erteilt' : 'entzogen';
                        $messages[] = "Gruppenberechtigung für '{$teacher['name']}' wurde $status.";
                    } else {
                        $errors[] = 'Fehler beim Ändern der Gruppenberechtigung.';
                    }
                }
                break;
                
            case 'upload_teachers':
                if (!isset($_FILES['teacher_file']) || $_FILES['teacher_file']['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Fehler beim Hochladen der Datei.';
                } else {
                    $file = $_FILES['teacher_file'];
                    $fileName = $file['name'];
                    $fileTmpName = $file['tmp_name'];
                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $nameFormat = $_POST['name_format'] ?? 'vorname_nachname';
                    
                    $allowedExtensions = ['csv', 'txt'];
                    
                    if (!in_array($fileExtension, $allowedExtensions)) {
                        $errors[] = 'Ungültiger Dateityp. Erlaubt: CSV, TXT';
                    } else {
                        try {
                            $teachers = parseTeacherFile($fileTmpName, $nameFormat);
                            $imported = 0;
                            $skipped = 0;
                            $passwordList = [];
                            
                            foreach ($teachers as $teacherData) {
                                $name = $teacherData['name'];
                                $email = $teacherData['email'];
                                
                                // E-Mail generieren falls leer
                                if (empty($email)) {
                                    // Einfache E-Mail-Generierung aus dem Namen
                                    $emailBase = strtolower(str_replace(' ', '.', $name));
                                    $emailBase = preg_replace('/[^a-z0-9\.]/', '', $emailBase);
                                    $email = $emailBase . '@schule.de';
                                }
                                
                                if (!empty($name) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                    // Prüfen ob bereits vorhanden
                                    $existingUser = getUserByEmail($email);
                                    if (!$existingUser) {
                                        $password = generateSecurePassword();
                                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                                        
                                        $stmt = $db->prepare("
                                            INSERT INTO users (email, password_hash, user_type, name, school_id, first_login, password_set_by_admin, admin_password) 
                                            VALUES (?, ?, 'lehrer', ?, ?, 1, 1, ?)
                                        ");
                                        
                                        if ($stmt->execute([$email, $passwordHash, $name, $user['school_id'], $password])) {
                                            $imported++;
                                            $passwordList[] = "$name ($email): $password";
                                        }
                                    } else {
                                        $skipped++;
                                    }
                                } else {
                                    $skipped++;
                                }
                            }
                            
                            if ($imported > 0) {
                                $messages[] = "$imported Lehrer wurden erfolgreich importiert.";
                                if (!empty($passwordList)) {
                                    $messages[] = "Generierte Zugangsdaten:<br>" . implode('<br>', $passwordList);
                                }
                            }
                            if ($skipped > 0) {
                                $messages[] = "$skipped Lehrer wurden übersprungen (bereits vorhanden oder ungültige Daten).";
                            }
                            
                        } catch (Exception $e) {
                            $errors[] = 'Fehler beim Verarbeiten der Datei: ' . $e->getMessage();
                        }
                    }
                }
                break;
                
            case 'generate_passwords':
                // Für alle Lehrer neue Passwörter generieren
                $stmt = $db->prepare("SELECT id, name FROM users WHERE school_id = ? AND user_type = 'lehrer' AND is_active = 1");
                $stmt->execute([$user['school_id']]);
                $teachers = $stmt->fetchAll();
                
                $passwordList = [];
                foreach ($teachers as $teacher) {
                    $newPassword = generateSecurePassword();
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    
                    $stmt = $db->prepare("
                        UPDATE users 
                        SET password_hash = ?, first_login = 1, password_set_by_admin = 1, admin_password = ?, updated_at = CURRENT_TIMESTAMP 
                        WHERE id = ?
                    ");
                    
                    if ($stmt->execute([$passwordHash, $newPassword, $teacher['id']])) {
                        $passwordList[] = "{$teacher['name']}: $newPassword";
                    }
                }
                
                if (!empty($passwordList)) {
                    $messages[] = "Neue Passwörter generiert:<br>" . implode('<br>', $passwordList);
                }
                break;
        }
    }
}

// Ansichtsmodus (Liste oder Kacheln)
$viewMode = $_GET['view'] ?? 'list'; // 'list' oder 'cards' - Liste ist Standard

// Bei Druckansicht immer Liste verwenden
if (isset($_GET['print'])) {
    $viewMode = 'list';
}

// Lehrer laden
$selectFields = "id, name, email, first_login, created_at, password_set_by_admin, admin_password";
if ($hasGroupsColumn) {
    $selectFields .= ", can_create_groups";
}

$stmt = $db->prepare("
    SELECT {$selectFields}
    FROM users 
    WHERE school_id = ? AND user_type = 'lehrer' AND is_active = 1
    ORDER BY name ASC
");
$stmt->execute([$user['school_id']]);
$teachers = $stmt->fetchAll();

// Für Kompatibilität: can_create_groups auf 0 setzen, wenn Spalte nicht existiert
if (!$hasGroupsColumn) {
    foreach ($teachers as &$teacher) {
        $teacher['can_create_groups'] = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lehrerverwaltung - <?php echo APP_NAME; ?></title>
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

        .main-controls {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            justify-content: flex-start;
        }

        .view-toggle {
            display: flex;
            gap: 0.5rem;
        }

        .view-btn {
            padding: 0.5rem 1rem;
            border: 1px solid rgba(100, 116, 139, 0.3);
            background: rgba(0, 0, 0, 0.3);
            color: #cbd5e1;
            text-decoration: none;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .view-btn.active {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            border-color: #3b82f6;
        }

        .view-btn:hover {
            background: rgba(59, 130, 246, 0.2);
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
            grid-template-columns: 1fr 200px auto;
            gap: 1rem;
            align-items: end;
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

        .form-group input, .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            background: rgba(0, 0, 0, 0.3);
            color: #e2e8f0;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
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

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #d97706, #b45309);
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
        }

        /* Kachel-Ansicht */
        .teachers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 1.5rem;
        }

        .teacher-card {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(100, 116, 139, 0.2);
            border-radius: 1rem;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .teacher-card:hover {
            border-color: #3b82f6;
            transform: translateY(-2px);
        }

        .teacher-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .teacher-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .teacher-info h3 {
            color: #e2e8f0;
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }

        .teacher-info p {
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .teacher-details {
            margin-bottom: 1rem;
        }

        .teacher-details p {
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .teacher-details strong {
            color: #cbd5e1;
        }

        .permission-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .permission-badge.allowed {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .permission-badge.denied {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .teacher-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Listen-Ansicht */
        .teachers-table {
            background: rgba(0, 0, 0, 0.4);
            border-radius: 1rem;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }

        .teachers-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .teachers-table th {
            background: rgba(59, 130, 246, 0.1);
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(100, 116, 139, 0.2);
            color: #3b82f6;
            font-weight: 600;
        }

        .teachers-table td {
            padding: 1rem;
            border-bottom: 1px solid rgba(100, 116, 139, 0.1);
            vertical-align: middle;
        }

        .teachers-table tr:hover {
            background: rgba(59, 130, 246, 0.05);
        }

        .table-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .editable-field {
            position: relative;
        }

        .edit-btn {
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 0.25rem;
            transition: all 0.3s ease;
        }

        .edit-btn:hover {
            color: #3b82f6;
            background: rgba(59, 130, 246, 0.1);
        }

        .password-display {
            font-family: monospace;
            background: rgba(0, 0, 0, 0.3);
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            border: 1px solid rgba(100, 116, 139, 0.2);
            font-size: 0.8rem;
        }

        .actions-header {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .view-actions {
            display: flex;
            gap: 1rem;
        }

        /* Druckstile */
        @media print {
            body {
                background: white !important;
                color: black !important;
            }
            
            .header, .upload-section, .main-controls, .actions-header, .view-actions, .view-toggle, .stats-grid, .teacher-actions, .table-actions, .modal {
                display: none !important;
            }
            
            .container {
                max-width: none !important;
                padding: 1rem !important;
            }
            
            .page-title {
                color: black !important;
                text-align: center;
                margin-bottom: 2rem;
            }
            
            .teachers-table {
                background: white !important;
                border: 1px solid black !important;
            }
            
            .teachers-table table {
                width: 100% !important;
            }
            
            .teachers-table th {
                background: #f0f0f0 !important;
                color: black !important;
                border: 1px solid black !important;
                padding: 1rem !important;
            }
            
            .teachers-table td {
                border: 1px solid black !important;
                padding: 1.5rem !important;
                color: black !important;
            }
            
            .teachers-table tr {
                page-break-inside: avoid;
                background: white !important;
            }
            
            .permission-badge {
                background: #f0f0f0 !important;
                color: black !important;
                border: 1px solid black !important;
            }
            
            .password-display {
                background: #f0f0f0 !important;
                color: black !important;
                border: 1px solid black !important;
            }
            
            /* Große Abstände zwischen Lehrern */
            .teachers-table tbody tr {
                border-bottom: 3px solid black !important;
            }
            
            .teachers-table tbody tr:not(:last-child) td {
                border-bottom: 3px solid black !important;
            }
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.2);
            border-radius: 0.5rem;
            padding: 1rem;
            text-align: center;
        }

        .stat-card h3 {
            font-size: 1.5rem;
            color: #3b82f6;
            margin-bottom: 0.25rem;
        }

        .stat-card p {
            color: #94a3b8;
            font-size: 0.9rem;
        }

        /* Modal für Bearbeitung */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: rgba(15, 23, 42, 0.95);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 1rem;
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            backdrop-filter: blur(10px);
        }

        .modal-header {
            margin-bottom: 1.5rem;
        }

        .modal-header h3 {
            color: #3b82f6;
            font-size: 1.3rem;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .main-controls {
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
            }
            
            .main-controls > * {
                width: 100%;
                justify-content: center;
            }
            
            .view-toggle {
                justify-content: center;
            }
            
            .teachers-grid {
                grid-template-columns: 1fr;
            }
            
            .upload-form {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .upload-form .form-group {
                margin-bottom: 1rem;
            }

            .teachers-table {
                overflow-x: auto;
            }

            .table-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>👨‍🏫 Lehrerverwaltung</h1>
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a> > Lehrer verwalten
            </div>
        </div>
        <a href="dashboard.php" class="btn btn-secondary">↩️ Zurück</a>
    </div>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title">
                <?php if (isset($_GET['print'])): ?>
                    Lehrerliste - <?php echo htmlspecialchars($school['name']); ?>
                <?php else: ?>
                    Lehrer verwalten
                <?php endif; ?>
            </h1>
            <?php if (!isset($_GET['print'])): ?>
            <p class="page-subtitle">
                Verwalten Sie die Lehrerkonten für <?php echo htmlspecialchars($school['name']); ?>.
            </p>
            <?php endif; ?>
        </div>

        <!-- Erfolgs- und Fehlermeldungen -->
        <?php if (!empty($messages)): ?>
            <div class="flash-message flash-success">
                <?php foreach ($messages as $message): ?>
                    <div><?php echo $message; ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="error-list">
                <h4>⚠️ Fehler beim Verarbeiten:</h4>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Statistiken -->
        <?php if (!isset($_GET['print'])): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <h3><?php echo count($teachers); ?></h3>
                <p>Aktive Lehrer</p>
            </div>
            <?php if ($hasGroupsColumn): ?>
            <div class="stat-card">
                <h3><?php echo count(array_filter($teachers, function($t) { return $t['can_create_groups']; })); ?></h3>
                <p>Mit Gruppenberechtigung</p>
            </div>
            <?php endif; ?>
            <div class="stat-card">
                <h3><?php echo count(array_filter($teachers, function($t) { return $t['first_login']; })); ?></h3>
                <p>Erste Anmeldung ausstehend</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- CSV-Upload Sektion -->
        <div class="upload-section">
            <div class="upload-header">
                <h3>📤 Lehrerliste importieren</h3>
            </div>
            
            <div class="upload-info">
                <h4>📋 Unterstützte Formate:</h4>
                <ul>
                    <li><strong>CSV/TXT:</strong> Ein Lehrer pro Zeile</li>
                    <li><strong>Nur Name:</strong> "Max Mustermann" oder "Mustermann, Max"</li>
                    <li><strong>Name + E-Mail:</strong> "Max Mustermann;max.mustermann@schule.de"</li>
                    <li><strong>Trennzeichen:</strong> Semikolon (;), Komma (,), Tab oder Pipe (|)</li>
                </ul>
                <h4>📧 E-Mail-Generierung:</h4>
                <ul>
                    <li>Wenn keine E-Mail angegeben: Automatische Generierung aus dem Namen</li>
                    <li>Beispiel: "Max Mustermann" → "max.mustermann@schule.de"</li>
                    <li>Automatische Passwort-Generierung für alle importierten Lehrer</li>
                </ul>
            </div>
            
            <form method="POST" action="" enctype="multipart/form-data" class="upload-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="upload_teachers">
                
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Datei auswählen</label>
                    <input type="file" name="teacher_file" accept=".csv,.txt" required>
                </div>
                
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Namensformat</label>
                    <select name="name_format">
                        <option value="vorname_nachname">Vorname Nachname</option>
                        <option value="nachname_vorname">Nachname, Vorname</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary">📤 Importieren</button>
            </form>
        </div>

        <!-- Neuen Lehrer anlegen -->
        <div class="upload-section">
            <div class="upload-header">
                <h3>👨‍🏫 Neuen Lehrer anlegen</h3>
            </div>
            <form method="POST" action="" style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 1rem; align-items: end;">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="create_teacher">
                
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Name</label>
                    <input type="text" name="teacher_name" required placeholder="z.B. Max Mustermann">
                </div>
                
                <div class="form-group" style="margin-bottom: 0;">
                    <label>E-Mail</label>
                    <input type="email" name="teacher_email" required placeholder="max.mustermann@schule.de">
                </div>
                
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Passwort (optional)</label>
                    <input type="text" name="teacher_password" placeholder="Leer = Automatisch generiert">
                </div>
                
                <button type="submit" class="btn btn-success">✅ Erstellen</button>
            </form>
        </div>

        <!-- Aktionen und Ansichtsumschaltung - NACH dem "Neuen Lehrer anlegen" Formular -->
        <div class="controls">
            <div class="actions-header">
                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="generate_passwords">
                    <button type="submit" class="btn btn-warning" onclick="return confirm('Möchten Sie wirklich für alle Lehrer neue Zufallspasswörter generieren?')">
                        🎲 Zufallspasswörter für alle
                    </button>
                </form>
                
                <div class="view-toggle">
                    <a href="?view=cards" class="view-btn <?php echo $viewMode === 'cards' ? 'active' : ''; ?>">
                        🔲 Kacheln
                    </a>
                    <a href="?view=list" class="view-btn <?php echo $viewMode === 'list' ? 'active' : ''; ?>">
                        📋 Liste
                    </a>
                </div>
                
                <button type="button" class="btn btn-success" onclick="printTeacherList()">
                    🖨️ Liste ausdrucken
                </button>
            </div>
        </div>

        <!-- Lehrerliste -->
        <?php if (empty($teachers)): ?>
            <div class="upload-section">
                <p style="text-align: center; padding: 2rem; color: #94a3b8;">
                    Noch keine Lehrer vorhanden. Legen Sie den ersten Lehrer an oder importieren Sie eine CSV-Datei.
                </p>
            </div>
        <?php else: ?>
            <?php if ($viewMode === 'cards'): ?>
                <!-- Kachel-Ansicht -->
                <div class="teachers-grid">
                    <?php foreach ($teachers as $teacher): ?>
                        <div class="teacher-card">
                            <div class="teacher-header">
                                <div class="teacher-icon">👨‍🏫</div>
                                <div class="teacher-info">
                                    <h3><?php echo htmlspecialchars($teacher['name']); ?></h3>
                                    <p><?php echo htmlspecialchars($teacher['email']); ?></p>
                                </div>
                            </div>
                            
                            <div class="teacher-details">
                                <p><strong>Passwort:</strong> 
                                    <?php if ($teacher['password_set_by_admin'] && !empty($teacher['admin_password'])): ?>
                                        <span class="password-display"><?php echo htmlspecialchars($teacher['admin_password']); ?></span>
                                    <?php else: ?>
                                        <span class="password-display">***</span>
                                    <?php endif; ?>
                                </p>
                                <?php if ($hasGroupsColumn): ?>
                                <p><strong>Gruppenberechtigung:</strong> 
                                    <span class="permission-badge <?php echo $teacher['can_create_groups'] ? 'allowed' : 'denied'; ?>">
                                        <?php echo $teacher['can_create_groups'] ? '✅ Kann Gruppen anlegen' : '❌ Keine Gruppenberechtigung'; ?>
                                    </span>
                                </p>
                                <?php endif; ?>
                                <p><strong>Status:</strong> 
                                    <?php if ($teacher['first_login']): ?>
                                        <span style="color: #f59e0b;">⏳ Erste Anmeldung ausstehend</span>
                                    <?php else: ?>
                                        <span style="color: #22c55e;">✅ Aktiv</span>
                                    <?php endif; ?>
                                </p>
                                <p><strong>Erstellt:</strong> <?php echo date('d.m.Y H:i', strtotime($teacher['created_at'])); ?></p>
                            </div>
                            
                            <div class="teacher-actions">
                                <!-- Bearbeiten -->
                                <button type="button" class="btn btn-secondary btn-sm" onclick="openEditModal(<?php echo $teacher['id']; ?>, '<?php echo htmlspecialchars($teacher['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($teacher['email'], ENT_QUOTES); ?>')">
                                    ✏️ Bearbeiten
                                </button>
                                
                                <!-- Passwort ändern -->
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="update_password">
                                    <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>">
                                    <button type="submit" class="btn btn-warning btn-sm">🔑 Neues Passwort</button>
                                </form>
                                
                                <?php if ($hasGroupsColumn): ?>
                                <!-- Gruppenberechtigung togglen -->
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="toggle_group_permission">
                                    <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm">
                                        <?php echo $teacher['can_create_groups'] ? '🚫 Berechtigung entziehen' : '👥 Berechtigung erteilen'; ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <!-- Löschen -->
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="delete_teacher">
                                    <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" 
                                            onclick="return confirm('Sind Sie sicher, dass Sie <?php echo htmlspecialchars($teacher['name']); ?> löschen möchten?')">
                                        🗑️ Löschen
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <!-- Listen-Ansicht -->
                <div class="teachers-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>E-Mail</th>
                                <th>Passwort</th>
                                <?php if ($hasGroupsColumn): ?>
                                <th>Gruppenberechtigung</th>
                                <?php endif; ?>
                                <th>Status</th>
                                <th>Erstellt</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teachers as $teacher): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($teacher['name']); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['email']); ?></td>
                                    <td>
                                        <?php if ($teacher['password_set_by_admin'] && !empty($teacher['admin_password'])): ?>
                                            <span class="password-display"><?php echo htmlspecialchars($teacher['admin_password']); ?></span>
                                        <?php else: ?>
                                            <span class="password-display">***</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($hasGroupsColumn): ?>
                                    <td>
                                        <span class="permission-badge <?php echo $teacher['can_create_groups'] ? 'allowed' : 'denied'; ?>">
                                            <?php echo $teacher['can_create_groups'] ? '✅ Ja' : '❌ Nein'; ?>
                                        </span>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php if ($teacher['first_login']): ?>
                                            <span style="color: #f59e0b;">⏳ Erste Anmeldung ausstehend</span>
                                        <?php else: ?>
                                            <span style="color: #22c55e;">✅ Aktiv</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($teacher['created_at'])); ?></td>
                                    <td>
                                        <div class="table-actions">
                                            <!-- Bearbeiten -->
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="openEditModal(<?php echo $teacher['id']; ?>, '<?php echo htmlspecialchars($teacher['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($teacher['email'], ENT_QUOTES); ?>')">
                                                ✏️
                                            </button>
                                            
                                            <!-- Passwort ändern -->
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="update_password">
                                                <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>">
                                                <button type="submit" class="btn btn-warning btn-sm" title="Neues Passwort">🔑</button>
                                            </form>
                                            
                                            <?php if ($hasGroupsColumn): ?>
                                            <!-- Gruppenberechtigung togglen -->
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="toggle_group_permission">
                                                <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm" title="Gruppenberechtigung ändern">
                                                    <?php echo $teacher['can_create_groups'] ? '🚫' : '👥'; ?>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            
                                            <!-- Löschen -->
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="delete_teacher">
                                                <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="Löschen"
                                                        onclick="return confirm('Sind Sie sicher, dass Sie <?php echo htmlspecialchars($teacher['name']); ?> löschen möchten?')">
                                                    🗑️
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal für Bearbeitung -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>👨‍🏫 Lehrer bearbeiten</h3>
            </div>
            <form method="POST" action="" id="editForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_teacher">
                <input type="hidden" name="teacher_id" id="editTeacherId">
                
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="teacher_name" id="editTeacherName" required>
                </div>
                
                <div class="form-group">
                    <label>E-Mail</label>
                    <input type="email" name="teacher_email" id="editTeacherEmail" required>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Abbrechen</button>
                    <button type="submit" class="btn btn-success">💾 Speichern</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Scroll-Position speichern und wiederherstellen
        function saveScrollPosition() {
            sessionStorage.setItem('teacherScrollPosition', window.scrollY);
        }
        
        function restoreScrollPosition() {
            const scrollPos = sessionStorage.getItem('teacherScrollPosition');
            if (scrollPos) {
                window.scrollTo(0, parseInt(scrollPos));
                sessionStorage.removeItem('teacherScrollPosition');
            }
        }
        
        // Bei Seitenload Scroll-Position wiederherstellen
        window.addEventListener('load', function() {
            restoreScrollPosition();
        });
        
        // Alle Formulare mit Scroll-Position speichern ausstatten
        document.addEventListener('DOMContentLoaded', function() {
            // Alle Formulare finden und Event-Listener hinzufügen
            const forms = document.querySelectorAll('form');
            forms.forEach(function(form) {
                // Nur bei POST-Formularen (nicht bei Modals oder GET-Requests)
                if (form.method.toLowerCase() === 'post') {
                    form.addEventListener('submit', function(e) {
                        // Scroll-Position speichern bevor das Formular abgesendet wird
                        saveScrollPosition();
                    });
                }
            });
            
            // Modal-Formular separat behandeln
            const editForm = document.getElementById('editForm');
            if (editForm) {
                editForm.addEventListener('submit', function(e) {
                    saveScrollPosition();
                });
            }
        });
        
        function openEditModal(id, name, email) {
            document.getElementById('editTeacherId').value = id;
            document.getElementById('editTeacherName').value = name;
            document.getElementById('editTeacherEmail').value = email;
            document.getElementById('editModal').classList.add('show');
        }
        
        function closeEditModal() {
            document.getElementById('editModal').classList.remove('show');
        }
        
        function printTeacherList() {
            // Zur Listenansicht wechseln falls in Kacheln
            const currentView = '<?php echo $viewMode; ?>';
            if (currentView === 'cards') {
                // Zur Liste umleiten und dann drucken
                const url = new URL(window.location);
                url.searchParams.set('view', 'list');
                url.searchParams.set('print', '1');
                window.location.href = url.toString();
                return;
            }
            
            // Drucken ausführen
            window.print();
        }
        
        // Auto-print wenn print Parameter gesetzt ist
        <?php if (isset($_GET['print'])): ?>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
                // Print parameter nach dem Drucken entfernen
                const url = new URL(window.location);
                url.searchParams.delete('print');
                window.history.replaceState({}, '', url);
            }, 500);
        });
        <?php endif; ?>
        
        // Modal außerhalb schließen
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
        
        // ESC-Taste zum Schließen
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditModal();
            }
        });
    </script>
</body>
</html>