<?php
require_once '../config.php';

// Schuladmin-Zugriff prüfen
$user = requireSchuladmin();
requireValidSchoolLicense($user['school_id']);
$db = getDB();
$school = getSchoolById($user['school_id']);
if (!$school) die('Schule nicht gefunden.');

$school_id = $_SESSION['school_id'];

// Zufallsfarbe generieren
function generateRandomColor() {
    return '#' . str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
}

// AJAX-Handler
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
                
                $check = $db->prepare("SELECT id FROM subjects WHERE school_id = ? AND short_name = ?");
                $check->execute([$school_id, $short_name]);
                if ($check->fetch()) throw new Exception('Ein Fach mit diesem Kürzel existiert bereits.');
                
                $stmt = $db->prepare("INSERT INTO subjects (school_id, short_name, full_name, color) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$school_id, $short_name, $full_name, $color])) {
                    $response = ['success' => true, 'message' => 'Fach hinzugefügt.', 'subject' => [
                        'id' => $db->lastInsertId(), 'short_name' => $short_name, 'full_name' => $full_name, 'color' => $color
                    ]];
                }
                break;
                
            case 'update':
                $id = intval($_POST['id'] ?? 0);
                $full_name = trim($_POST['full_name'] ?? '');
                $short_name = trim($_POST['short_name'] ?? '');
                
                if (empty($full_name)) throw new Exception('Fachname erforderlich.');
                
                $updateFields = ['full_name = ?'];
                $params = [$full_name];
                
                if (!empty($short_name)) {
                    $updateFields[] = 'short_name = ?';
                    $params[] = $short_name;
                }
                
                $params[] = $id;
                $params[] = $school_id;
                
                $sql = "UPDATE subjects SET " . implode(', ', $updateFields) . " WHERE id = ? AND school_id = ?";
                $stmt = $db->prepare($sql);
                
                if ($stmt->execute($params)) {
                    $response = ['success' => true, 'message' => 'Fach aktualisiert.'];
                }
                break;
                
            case 'update_color':
                $id = intval($_POST['id'] ?? 0);
                $color = $_POST['color'] ?? '';
                
                if (empty($color)) throw new Exception('Farbe erforderlich.');
                
                $stmt = $db->prepare("UPDATE subjects SET color = ? WHERE id = ? AND school_id = ?");
                if ($stmt->execute([$color, $id, $school_id])) {
                    $response = ['success' => true, 'message' => 'Farbe aktualisiert.'];
                }
                break;
                
            case 'delete':
                $id = intval($_POST['id'] ?? 0);
                if ($id <= 0) throw new Exception('Ungültige Fach-ID.');
                
                $stmt = $db->prepare("DELETE FROM subjects WHERE id = ? AND school_id = ?");
                if ($stmt->execute([$id, $school_id])) {
                    $response = ['success' => true, 'message' => 'Fach gelöscht.'];
                } else {
                    throw new Exception('Fehler beim Löschen. Möglicherweise wird das Fach noch verwendet.');
                }
                break;
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit();
}

// Fächer laden
$subjects = [];
$show_migration_notice = false;
try {
    $check_table = $db->query("SHOW TABLES LIKE 'subjects'");
    if ($check_table->rowCount() == 0) {
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #0f172a, #1e293b); color: #e2e8f0; min-height: 100vh; }
        
        .header { background: rgba(0,0,0,0.3); border-bottom: 1px solid rgba(59,130,246,0.2); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; backdrop-filter: blur(10px); }
        .header h1 { color: #3b82f6; font-size: 1.5rem; font-weight: 600; }
        .breadcrumb { font-size: 0.9rem; opacity: 0.8; margin-top: 0.25rem; }
        .breadcrumb a { color: #3b82f6; text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
        .page-header { margin-bottom: 2rem; }
        .page-title { font-size: 2rem; color: #3b82f6; margin-bottom: 0.5rem; }
        .page-subtitle { opacity: 0.8; line-height: 1.5; }
        
        .btn { padding: 0.5rem 1rem; border: none; border-radius: 0.5rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; cursor: pointer; transition: all 0.3s ease; font-weight: 500; }
        .btn-secondary { background: rgba(100,116,139,0.2); color: #cbd5e1; border: 1px solid rgba(100,116,139,0.3); }
        .btn-secondary:hover { background: rgba(100,116,139,0.3); transform: translateY(-1px); }
        .btn-primary { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; border: 1px solid rgba(59,130,246,0.3); }
        .btn-primary:hover { background: linear-gradient(135deg, #2563eb, #1e40af); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
        .btn-success { background: linear-gradient(135deg, #10b981, #059669); color: white; border: 1px solid rgba(16,185,129,0.3); }
        .btn-success:hover { background: linear-gradient(135deg, #059669, #047857); transform: translateY(-1px); }
        .btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: 1px solid rgba(239,68,68,0.3); }
        .btn-danger:hover { background: linear-gradient(135deg, #dc2626, #b91c1c); transform: translateY(-1px); }
        .btn-edit { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; border: 1px solid rgba(245,158,11,0.3); }
        .btn-edit:hover { background: linear-gradient(135deg, #d97706, #b45309); transform: translateY(-1px); }
        
        .alert { padding: 1rem; border-radius: 0.5rem; margin-bottom: 2rem; display: none; font-weight: 500; }
        .alert-success { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: #86efac; }
        .alert-danger { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }
        
        .subjects-grid { display: grid; gap: 1rem; margin-bottom: 2rem; }
        .subject-item { background: rgba(0,0,0,0.3); border: 1px solid rgba(100,116,139,0.3); padding: 1rem; border-radius: 0.5rem; display: flex; align-items: center; gap: 1rem; transition: all 0.3s ease; }
        .subject-item:hover { background: rgba(0,0,0,0.4); transform: translateY(-1px); }
        .subject-item.editing { background: rgba(59,130,246,0.1); border-color: #3b82f6; }
        
        .subject-code { font-size: 1.2rem; font-weight: bold; min-width: 50px; text-align: center; background: rgba(0,0,0,0.3); padding: 0.5rem; border-radius: 0.25rem; border: 1px solid rgba(100,116,139,0.3); }
        .subject-name { flex: 1; }
        .subject-name input, .subject-code input { width: 100%; padding: 0.5rem; background: rgba(0,0,0,0.3); border: 1px solid rgba(100,116,139,0.3); border-radius: 0.25rem; color: #e2e8f0; transition: all 0.3s ease; }
        .subject-name input:focus, .subject-code input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.2); }
        .subject-name.readonly input { background: transparent; border: none; cursor: default; }
        .subject-name.readonly input:focus { box-shadow: none; }
        
        .color-picker { width: 40px; height: 40px; border: none; border-radius: 50%; cursor: pointer; border: 2px solid rgba(100,116,139,0.3); }
        .color-picker:hover { transform: scale(1.05); }
        
        .subject-actions { display: flex; gap: 0.5rem; }
        
        .add-form { background: rgba(0,0,0,0.3); border: 1px solid rgba(100,116,139,0.3); padding: 1.5rem; border-radius: 0.5rem; margin-bottom: 1rem; }
        .add-form h2 { color: #3b82f6; margin-bottom: 1rem; }
        .form-row { display: flex; gap: 1rem; align-items: end; flex-wrap: wrap; }
        .form-group { display: flex; flex-direction: column; min-width: 150px; flex: 1; }
        .form-group label { margin-bottom: 0.25rem; font-size: 0.9rem; color: #cbd5e1; }
        .form-group input { padding: 0.5rem; background: rgba(0,0,0,0.3); border: 1px solid rgba(100,116,139,0.3); border-radius: 0.25rem; color: #e2e8f0; }
        .form-group input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.2); }
        
        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .header { padding: 1rem; flex-direction: column; gap: 1rem; }
            .subject-item { flex-direction: column; text-align: center; }
            .form-row { flex-direction: column; }
            .subject-actions { justify-content: center; }
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
            <p class="page-subtitle">Verwalten Sie die Schulfächer für <?php echo htmlspecialchars($school['name']); ?>.</p>
        </div>
        
        <div class="alert" id="alert"></div>
        
        <?php if ($show_migration_notice): ?>
        <div class="alert alert-danger" style="display: block;">
            <strong>⚠️ Hinweis:</strong> Die Fächer-Tabelle existiert noch nicht. 
            Bitte führen Sie die Migration aus: <code>php migrate_subjects.php</code>
        </div>
        <?php endif; ?>
        
        <div class="add-form">
            <h2>➕ Neues Fach hinzufügen</h2>
            <form id="addForm" onsubmit="addSubject(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label>Kürzel</label>
                        <input type="text" name="short_name" required maxlength="10" placeholder="z.B. M">
                    </div>
                    <div class="form-group">
                        <label>Fachname</label>
                        <input type="text" name="full_name" required maxlength="100" placeholder="z.B. Mathematik">
                    </div>
                    <button type="submit" class="btn btn-success">Hinzufügen</button>
                </div>
            </form>
        </div>
        
        <div class="subjects-grid" id="subjectsGrid">
            <?php foreach ($subjects as $subject): ?>
            <div class="subject-item" data-id="<?php echo $subject['id']; ?>">
                <div class="subject-code">
                    <input type="text" value="<?php echo htmlspecialchars($subject['short_name']); ?>" readonly style="color: <?php echo htmlspecialchars($subject['color']); ?>; font-weight: bold;">
                </div>
                <div class="subject-name readonly">
                    <input type="text" value="<?php echo htmlspecialchars($subject['full_name']); ?>" readonly>
                </div>
                <input type="color" class="color-picker" value="<?php echo htmlspecialchars($subject['color']); ?>" onchange="updateColor(<?php echo $subject['id']; ?>, this.value)">
                <div class="subject-actions">
                    <button class="btn btn-edit" onclick="editSubject(<?php echo $subject['id']; ?>)">✏️ Bearbeiten</button>
                    <button class="btn btn-danger" onclick="deleteSubject(<?php echo $subject['id']; ?>)">🗑️ Löschen</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <script>
        function showAlert(message, type = 'success') {
            const alert = document.getElementById('alert');
            alert.className = `alert alert-${type}`;
            alert.textContent = message;
            alert.style.display = 'block';
            setTimeout(() => alert.style.display = 'none', 5000);
        }
        
        function apiCall(action, data = {}) {
            data.action = action;
            return fetch('admin_faecher.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(data)
            }).then(r => r.json());
        }
        
        function editSubject(id) {
            const item = document.querySelector(`[data-id="${id}"]`);
            const isEditing = item.classList.contains('editing');
            
            if (isEditing) {
                // Speichern
                const shortName = item.querySelector('.subject-code input').value.trim();
                const fullName = item.querySelector('.subject-name input').value.trim();
                
                if (!shortName || !fullName) {
                    showAlert('Bitte alle Felder ausfüllen.', 'danger');
                    return;
                }
                
                apiCall('update', { id, short_name: shortName, full_name: fullName })
                .then(data => {
                    if (data.success) {
                        showAlert(data.message);
                        item.classList.remove('editing');
                        item.querySelector('.subject-name').classList.add('readonly');
                        item.querySelector('.subject-code input').readOnly = true;
                        item.querySelector('.subject-name input').readOnly = true;
                        item.querySelector('.btn-edit').innerHTML = '✏️ Bearbeiten';
                    } else {
                        showAlert(data.message, 'danger');
                    }
                });
            } else {
                // Bearbeiten aktivieren
                item.classList.add('editing');
                item.querySelector('.subject-name').classList.remove('readonly');
                item.querySelector('.subject-code input').readOnly = false;
                item.querySelector('.subject-name input').readOnly = false;
                item.querySelector('.btn-edit').innerHTML = '💾 Speichern';
                item.querySelector('.subject-name input').focus();
            }
        }
        
        function updateColor(id, color) {
            apiCall('update_color', { id, color })
            .then(data => {
                if (data.success) {
                    document.querySelector(`[data-id="${id}"] .subject-code input`).style.color = color;
                } else {
                    showAlert(data.message, 'danger');
                }
            });
        }
        
        function deleteSubject(id) {
            if (!confirm('Möchten Sie dieses Fach wirklich löschen?')) return;
            
            apiCall('delete', { id })
            .then(data => {
                if (data.success) {
                    showAlert(data.message);
                    document.querySelector(`[data-id="${id}"]`).remove();
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(() => showAlert('Netzwerkfehler beim Löschen.', 'danger'));
        }
        
        function addSubject(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            
            apiCall('add', Object.fromEntries(formData))
            .then(data => {
                if (data.success) {
                    showAlert(data.message);
                    const grid = document.getElementById('subjectsGrid');
                    const s = data.subject;
                    const newItem = document.createElement('div');
                    newItem.className = 'subject-item';
                    newItem.setAttribute('data-id', s.id);
                    newItem.innerHTML = `
                        <div class="subject-code">
                            <input type="text" value="${s.short_name}" readonly style="color: ${s.color}; font-weight: bold;">
                        </div>
                        <div class="subject-name readonly">
                            <input type="text" value="${s.full_name}" readonly>
                        </div>
                        <input type="color" class="color-picker" value="${s.color}" onchange="updateColor(${s.id}, this.value)">
                        <div class="subject-actions">
                            <button class="btn btn-edit" onclick="editSubject(${s.id})">✏️ Bearbeiten</button>
                            <button class="btn btn-danger" onclick="deleteSubject(${s.id})">🗑️ Löschen</button>
                        </div>
                    `;
                    grid.appendChild(newItem);
                    event.target.reset();
                } else {
                    showAlert(data.message, 'danger');
                }
            });
        }
    </script>
</body>
</html>
