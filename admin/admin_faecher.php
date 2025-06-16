<?php
require_once '../config.php';

// Überprüfen, ob der Benutzer angemeldet ist und ein Admin ist
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] !== 'schuladmin' && $_SESSION['user_type'] !== 'superadmin')) {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];
$school_id = $_SESSION['school_id'] ?? null;

// PDO-Verbindung holen
$db = getDB();

// Funktion zum Generieren einer zufälligen Farbe
function generateRandomColor() {
    return '#' . str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
}

// AJAX-Handler für Fach-Operationen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    try {
        switch ($_POST['action']) {
            case 'add':
                $short_name = trim($_POST['short_name'] ?? '');
                $full_name = trim($_POST['full_name'] ?? '');
                $color = $_POST['color'] ?? generateRandomColor();
                
                if (empty($short_name) || empty($full_name)) {
                    throw new Exception('Bitte alle Felder ausfüllen.');
                }
                
                // Prüfen, ob das Kürzel bereits existiert
                $check_stmt = $db->prepare("SELECT id FROM subjects WHERE school_id = ? AND short_name = ?");
                $check_stmt->execute([$school_id, $short_name]);
                if ($check_stmt->fetch()) {
                    throw new Exception('Ein Fach mit diesem Kürzel existiert bereits.');
                }
                
                $stmt = $db->prepare("INSERT INTO subjects (school_id, short_name, full_name, color) VALUES (?, ?, ?, ?)");
                
                if ($stmt->execute([$school_id, $short_name, $full_name, $color])) {
                    $response['success'] = true;
                    $response['message'] = 'Fach erfolgreich hinzugefügt.';
                    $response['subject'] = [
                        'id' => $db->lastInsertId(),
                        'short_name' => $short_name,
                        'full_name' => $full_name,
                        'color' => $color
                    ];
                } else {
                    throw new Exception('Fehler beim Hinzufügen des Fachs.');
                }
                break;
                
            case 'update':
                $id = intval($_POST['id'] ?? 0);
                $full_name = trim($_POST['full_name'] ?? '');
                
                if (empty($full_name)) {
                    throw new Exception('Bitte geben Sie einen Fachnamen ein.');
                }
                
                $stmt = $db->prepare("UPDATE subjects SET full_name = ? WHERE id = ? AND school_id = ?");
                
                if ($stmt->execute([$full_name, $id, $school_id])) {
                    $response['success'] = true;
                    $response['message'] = 'Fach erfolgreich aktualisiert.';
                } else {
                    throw new Exception('Fehler beim Aktualisieren des Fachs.');
                }
                break;
                
            case 'delete':
                $id = intval($_POST['id'] ?? 0);
                
                // Prüfen, ob das Fach in Gruppen verwendet wird
                $check_stmt = $db->prepare("SELECT COUNT(*) as count FROM group_subjects WHERE subject_id = ?");
                $check_stmt->execute([$id]);
                $result = $check_stmt->fetch();
                
                if ($result['count'] > 0) {
                    throw new Exception('Dieses Fach wird noch in Gruppen verwendet und kann nicht gelöscht werden.');
                }
                
                $stmt = $db->prepare("DELETE FROM subjects WHERE id = ? AND school_id = ?");
                
                if ($stmt->execute([$id, $school_id])) {
                    $response['success'] = true;
                    $response['message'] = 'Fach erfolgreich gelöscht.';
                } else {
                    throw new Exception('Fehler beim Löschen des Fachs.');
                }
                break;
                
            case 'update_color':
                $id = intval($_POST['id'] ?? 0);
                $color = $_POST['color'] ?? '';
                
                if (!preg_match('/^#[0-9A-F]{6}$/i', $color)) {
                    throw new Exception('Ungültiger Farbcode.');
                }
                
                $stmt = $db->prepare("UPDATE subjects SET color = ? WHERE id = ? AND school_id = ?");
                
                if ($stmt->execute([$color, $id, $school_id])) {
                    $response['success'] = true;
                    $response['message'] = 'Farbe erfolgreich aktualisiert.';
                } else {
                    throw new Exception('Fehler beim Aktualisieren der Farbe.');
                }
                break;
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit();
}

// Fächer abrufen
$subjects = [];
try {
    // Prüfen, ob die Tabelle existiert
    $check_table = $db->query("SHOW TABLES LIKE 'subjects'");
    
    if ($check_table->rowCount() == 0) {
        // Tabelle existiert nicht - Hinweis anzeigen
        $show_migration_notice = true;
    } else {
        $stmt = $db->prepare("SELECT * FROM subjects WHERE school_id = ? ORDER BY short_name");
        $stmt->execute([$school_id]);
        $subjects = $stmt->fetchAll();
    }
} catch (Exception $e) {
    $show_migration_notice = true;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fächer verwalten - Schülerverwaltung</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background-color: #0a0e27;
            color: #e0e0e0;
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: white;
            padding: 2rem;
            margin-bottom: 2rem;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }
        
        .header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            opacity: 0.9;
        }
        
        .subjects-grid {
            display: grid;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .subject-item {
            background: #1a1f3a;
            border: 1px solid #2a3450;
            padding: 1rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
        }
        
        .subject-item:hover {
            background: #202545;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        .subject-code {
            font-weight: bold;
            font-size: 1.2rem;
            min-width: 60px;
            text-align: center;
            padding: 0.5rem;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.1);
            text-shadow: 0 0 3px rgba(0, 0, 0, 0.5);
        }
        
        .subject-name {
            flex: 1;
            font-size: 1rem;
        }
        
        .subject-name input {
            background: transparent;
            border: 1px solid transparent;
            color: #e0e0e0;
            padding: 0.5rem;
            width: 100%;
            border-radius: 4px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .subject-name input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.05);
            border-color: #4a90e2;
        }
        
        .color-picker-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .color-picker {
            width: 50px;
            height: 40px;
            border: 2px solid #3a4560;
            border-radius: 4px;
            cursor: pointer;
            background: none;
            padding: 2px;
        }
        
        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
        }
        
        .btn-primary {
            background: #4a90e2;
            color: white;
        }
        
        .btn-primary:hover {
            background: #357abd;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(74, 144, 226, 0.3);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(40, 167, 69, 0.3);
        }
        
        .add-subject-form {
            background: #1a1f3a;
            border: 1px solid #2a3450;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        
        .add-subject-form h2 {
            margin-bottom: 1.5rem;
            color: #4a90e2;
        }
        
        .form-row {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #b0b0b0;
        }
        
        .form-group input {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid #3a4560;
            background: #0d1128;
            color: #e0e0e0;
            border-radius: 5px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #4a90e2;
            background: #141830;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            color: #4a90e2;
            text-decoration: none;
            margin-bottom: 1rem;
            transition: color 0.3s ease;
        }
        
        .back-link:hover {
            color: #6ba3e5;
        }
        
        .back-link::before {
            content: "← ";
            margin-right: 0.5rem;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            display: none;
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.2);
            border: 1px solid #28a745;
            color: #28a745;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.2);
            border: 1px solid #dc3545;
            color: #dc3545;
        }
        
        .save-all-btn {
            margin-top: 2rem;
            width: 100%;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header {
                padding: 1.5rem;
            }
            
            .header h1 {
                font-size: 1.5rem;
            }
            
            .subject-item {
                flex-direction: column;
                text-align: center;
            }
            
            .form-row {
                flex-direction: column;
            }
            
            .form-group {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="admin_dashboard.php" class="back-link">Zurück zum Dashboard</a>
        
        <div class="header">
            <h1>Fächer verwalten</h1>
            <p>Hier können Sie die verfügbaren Fächer bearbeiten.</p>
        </div>
        
        <div class="alert" id="alert"></div>
        
        <?php if (isset($show_migration_notice) && $show_migration_notice): ?>
        <div class="alert alert-danger" style="display: block;">
            Die Fächer-Tabelle existiert noch nicht. Bitte führen Sie die Migration aus: <code>php migrate_subjects.php</code>
        </div>
        <?php endif; ?>
        
        <div class="subjects-grid" id="subjectsGrid">
            <?php foreach ($subjects as $subject): ?>
            <div class="subject-item" data-id="<?php echo $subject['id']; ?>">
                <div class="subject-code" style="color: <?php echo htmlspecialchars($subject['color']); ?>">
                    <?php echo htmlspecialchars($subject['short_name']); ?>
                </div>
                <div class="subject-name">
                    <input type="text" 
                           value="<?php echo htmlspecialchars($subject['full_name']); ?>" 
                           data-original="<?php echo htmlspecialchars($subject['full_name']); ?>"
                           onchange="updateSubjectName(<?php echo $subject['id']; ?>, this.value)">
                </div>
                <div class="color-picker-wrapper">
                    <input type="color" 
                           class="color-picker" 
                           value="<?php echo htmlspecialchars($subject['color']); ?>"
                           onchange="updateSubjectColor(<?php echo $subject['id']; ?>, this.value)">
                </div>
                <button class="btn btn-danger" onclick="deleteSubject(<?php echo $subject['id']; ?>)">Löschen</button>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="add-subject-form">
            <h2>Neues Fach hinzufügen</h2>
            <form id="addSubjectForm" onsubmit="addSubject(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label for="shortName">Kürzel (z.B. M)</label>
                        <input type="text" id="shortName" name="short_name" required maxlength="10">
                    </div>
                    <div class="form-group">
                        <label for="fullName">Fachname (z.B. Mathematik)</label>
                        <input type="text" id="fullName" name="full_name" required maxlength="100">
                    </div>
                    <button type="submit" class="btn btn-success">Hinzufügen</button>
                </div>
            </form>
        </div>
        
        <button class="btn btn-primary save-all-btn" onclick="saveAllChanges()">Alle Änderungen speichern</button>
    </div>
    
    <script>
        let pendingChanges = new Map();
        
        function showAlert(message, type = 'success') {
            const alert = document.getElementById('alert');
            alert.className = `alert alert-${type}`;
            alert.textContent = message;
            alert.style.display = 'block';
            
            setTimeout(() => {
                alert.style.display = 'none';
            }, 5000);
        }
        
        function updateSubjectName(id, name) {
            pendingChanges.set(`name_${id}`, { id, full_name: name });
        }
        
        function updateSubjectColor(id, color) {
            // Sofort die Farbe aktualisieren
            fetch('admin_faecher.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=update_color&id=${id}&color=${encodeURIComponent(color)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update color in subject code
                    const item = document.querySelector(`[data-id="${id}"]`);
                    const code = item.querySelector('.subject-code');
                    code.style.color = color;
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(error => {
                showAlert('Ein Fehler ist aufgetreten.', 'danger');
            });
        }
        
        function deleteSubject(id) {
            if (!confirm('Möchten Sie dieses Fach wirklich löschen?')) {
                return;
            }
            
            fetch('admin_faecher.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message);
                    document.querySelector(`[data-id="${id}"]`).remove();
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(error => {
                showAlert('Ein Fehler ist aufgetreten.', 'danger');
            });
        }
        
        function addSubject(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            formData.append('action', 'add');
            
            // Generate random color
            const randomColor = '#' + Math.floor(Math.random()*16777215).toString(16).padStart(6, '0');
            formData.append('color', randomColor);
            
            fetch('admin_faecher.php', {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message);
                    form.reset();
                    
                    // Add new subject to the grid
                    const subjectHtml = `
                        <div class="subject-item" data-id="${data.subject.id}">
                            <div class="subject-code" style="color: ${data.subject.color}">
                                ${data.subject.short_name}
                            </div>
                            <div class="subject-name">
                                <input type="text" 
                                       value="${data.subject.full_name}" 
                                       data-original="${data.subject.full_name}"
                                       onchange="updateSubjectName(${data.subject.id}, this.value)">
                            </div>
                            <div class="color-picker-wrapper">
                                <input type="color" 
                                       class="color-picker" 
                                       value="${data.subject.color}"
                                       onchange="updateSubjectColor(${data.subject.id}, this.value)">
                            </div>
                            <button class="btn btn-danger" onclick="deleteSubject(${data.subject.id})">Löschen</button>
                        </div>
                    `;
                    document.getElementById('subjectsGrid').insertAdjacentHTML('beforeend', subjectHtml);
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(error => {
                showAlert('Ein Fehler ist aufgetreten.', 'danger');
            });
        }
        
        function saveAllChanges() {
            if (pendingChanges.size === 0) {
                showAlert('Keine Änderungen vorhanden.', 'info');
                return;
            }
            
            let successCount = 0;
            let errorCount = 0;
            const promises = [];
            
            pendingChanges.forEach((change, key) => {
                if (key.startsWith('name_')) {
                    const promise = fetch('admin_faecher.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=update&id=${change.id}&full_name=${encodeURIComponent(change.full_name)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            successCount++;
                            // Update original value
                            const input = document.querySelector(`[data-id="${change.id}"] input[type="text"]`);
                            if (input) {
                                input.setAttribute('data-original', change.full_name);
                            }
                        } else {
                            errorCount++;
                        }
                    })
                    .catch(() => errorCount++);
                    
                    promises.push(promise);
                }
            });
            
            Promise.all(promises).then(() => {
                if (successCount > 0 && errorCount === 0) {
                    showAlert(`${successCount} Änderung(en) erfolgreich gespeichert.`);
                    pendingChanges.clear();
                } else if (successCount > 0 && errorCount > 0) {
                    showAlert(`${successCount} Änderung(en) gespeichert, ${errorCount} Fehler aufgetreten.`, 'warning');
                } else {
                    showAlert('Fehler beim Speichern der Änderungen.', 'danger');
                }
            });
        }
        
        // Warn before leaving if there are unsaved changes
        window.addEventListener('beforeunload', (e) => {
            if (pendingChanges.size > 0) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>
</body>
</html>