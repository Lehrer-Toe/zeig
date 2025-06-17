<?php
require_once '../config.php';

// Schuladmin-Zugriff prüfen
$user = requireSchuladmin();
requireValidSchoolLicense($user['school_id']);

// PDO-Verbindung holen (vor allem anderen!)
$db = getDB();

// Schuldaten laden
$school = getSchoolById($user['school_id']);
if (!$school) {
    die('Schule nicht gefunden.');
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];
$school_id = $_SESSION['school_id'] ?? null;

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
                
            case 'update_color':
                $id = intval($_POST['id'] ?? 0);
                $color = $_POST['color'] ?? '';
                
                if (empty($color)) {
                    throw new Exception('Bitte wählen Sie eine Farbe.');
                }
                
                $stmt = $db->prepare("UPDATE subjects SET color = ? WHERE id = ? AND school_id = ?");
                
                if ($stmt->execute([$color, $id, $school_id])) {
                    $response['success'] = true;
                    $response['message'] = 'Farbe erfolgreich aktualisiert.';
                } else {
                    throw new Exception('Fehler beim Aktualisieren der Farbe.');
                }
                break;
                
            case 'delete':
                $id = intval($_POST['id'] ?? 0);
                
                $stmt = $db->prepare("DELETE FROM subjects WHERE id = ? AND school_id = ?");
                
                if ($stmt->execute([$id, $school_id])) {
                    $response['success'] = true;
                    $response['message'] = 'Fach erfolgreich gelöscht.';
                } else {
                    throw new Exception('Fehler beim Löschen des Fachs.');
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
    <title>Fächerverwaltung - <?php echo APP_NAME; ?></title>
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

        .btn-secondary {
            background: rgba(100, 116, 139, 0.2);
            color: #cbd5e1;
            border: 1px solid rgba(100, 116, 139, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(100, 116, 139, 0.3);
            transform: translateY(-1px);
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #2563eb, #1e40af);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 2rem;
            display: none;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .subjects-grid {
            display: grid;
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .subject-item {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.3);
            padding: 1.5rem;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .subject-item:hover {
            background: rgba(0, 0, 0, 0.4);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .subject-code {
            font-size: 1.5rem;
            font-weight: bold;
            min-width: 60px;
            text-align: center;
            background: rgba(0, 0, 0, 0.3);
            padding: 0.75rem;
            border-radius: 0.5rem;
            border: 1px solid rgba(100, 116, 139, 0.3);
        }

        .subject-name {
            flex: 1;
        }

        .subject-name input {
            width: 100%;
            padding: 0.75rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            color: #e2e8f0;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .subject-name input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }

        .color-picker-wrapper {
            position: relative;
        }

        .color-picker {
            width: 50px;
            height: 50px;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 3px solid rgba(100, 116, 139, 0.3);
        }

        .color-picker:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .add-subject-form {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.3);
            padding: 2rem;
            border-radius: 1rem;
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
        }

        .add-subject-form h2 {
            color: #3b82f6;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
        }

        .form-row {
            display: flex;
            gap: 1.5rem;
            align-items: end;
            flex-wrap: wrap;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            min-width: 200px;
            flex: 1;
        }

        .form-group label {
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #cbd5e1;
            font-size: 0.9rem;
        }

        .form-group input {
            padding: 0.75rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            color: #e2e8f0;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }

        .save-all-btn {
            width: 100%;
            padding: 1rem;
            font-size: 1.1rem;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .header {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .subject-item {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
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
    <div class="header">
        <div>
            <h1>📚 Fächerverwaltung</h1>
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a> > Fächer verwalten
            </div>
        </div>
        <a href="dashboard.php" class="btn btn-secondary">↩️ Zurück</a>
    </div>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Fächer verwalten</h1>
            <p class="page-subtitle">
                Verwalten Sie die verfügbaren Schulfächer für <?php echo htmlspecialchars($school['name']); ?>.
            </p>
        </div>
        
        <div class="alert" id="alert"></div>
        
        <?php if (isset($show_migration_notice) && $show_migration_notice): ?>
        <div class="alert alert-danger" style="display: block;">
            <strong>⚠️ Hinweis:</strong> Die Fächer-Tabelle existiert noch nicht. 
            Bitte führen Sie die Migration aus: <code>php migrate_subjects.php</code>
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
                           onchange="updateSubjectName(<?php echo $subject['id']; ?>, this.value)"
                           placeholder="Fachname eingeben">
                </div>
                <div class="color-picker-wrapper">
                    <input type="color" 
                           class="color-picker" 
                           value="<?php echo htmlspecialchars($subject['color']); ?>"
                           onchange="updateSubjectColor(<?php echo $subject['id']; ?>, this.value)"
                           title="Fachfarbe ändern">
                </div>
                <button class="btn btn-danger" onclick="deleteSubject(<?php echo $subject['id']; ?>)">
                    🗑️ Löschen
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="add-subject-form">
            <h2>➕ Neues Fach hinzufügen</h2>
            <form id="addSubjectForm" onsubmit="addSubject(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label for="shortName">Kürzel (z.B. M)</label>
                        <input type="text" id="shortName" name="short_name" required maxlength="10" placeholder="z.B. M, D, E">
                    </div>
                    <div class="form-group">
                        <label for="fullName">Fachname (z.B. Mathematik)</label>
                        <input type="text" id="fullName" name="full_name" required maxlength="100" placeholder="z.B. Mathematik">
                    </div>
                    <button type="submit" class="btn btn-success">✅ Hinzufügen</button>
                </div>
            </form>
        </div>
        
        <button class="btn btn-primary save-all-btn" onclick="saveAllChanges()">
            💾 Alle Änderungen speichern
        </button>
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
            
            const formData = new FormData(event.target);
            formData.append('action', 'add');
            
            fetch('admin_faecher.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message);
                    
                    // Neues Fach zur Liste hinzufügen
                    const grid = document.getElementById('subjectsGrid');
                    const newItem = document.createElement('div');
                    newItem.className = 'subject-item';
                    newItem.setAttribute('data-id', data.subject.id);
                    newItem.innerHTML = `
                        <div class="subject-code" style="color: ${data.subject.color}">
                            ${data.subject.short_name}
                        </div>
                        <div class="subject-name">
                            <input type="text" 
                                   value="${data.subject.full_name}" 
                                   data-original="${data.subject.full_name}"
                                   onchange="updateSubjectName(${data.subject.id}, this.value)"
                                   placeholder="Fachname eingeben">
                        </div>
                        <div class="color-picker-wrapper">
                            <input type="color" 
                                   class="color-picker" 
                                   value="${data.subject.color}"
                                   onchange="updateSubjectColor(${data.subject.id}, this.value)"
                                   title="Fachfarbe ändern">
                        </div>
                        <button class="btn btn-danger" onclick="deleteSubject(${data.subject.id})">
                            🗑️ Löschen
                        </button>
                    `;
                    grid.appendChild(newItem);
                    
                    // Formular zurücksetzen
                    event.target.reset();
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
