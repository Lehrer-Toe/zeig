<?php
require_once '../config.php';

// Schuladmin-Zugriff prüfen
$user = requireSchuladmin();
requireValidSchoolLicense($user['school_id']);

$admin_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'];
$db = getDB();

// Nachricht-Variablen
$message = '';
$error = '';

// Datei-Upload verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'upload' && isset($_FILES['dokumentvorlage'])) {
        $uploadDir = '../uploads/dokumentvorlagen/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $file = $_FILES['dokumentvorlage'];
        $allowedTypes = ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/rtf', 'application/vnd.oasis.opendocument.text'];
        $allowedExtensions = ['docx', 'rtf', 'odt'];
        
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($file['size'] > 8 * 1024 * 1024) {
            $error = "Die Datei darf nicht größer als 8 MB sein.";
        } elseif (!in_array($fileExtension, $allowedExtensions)) {
            $error = "Nur DOCX, RTF und ODT Dateien sind erlaubt.";
        } else {
            // Prüfen ob bereits eine Vorlage existiert
            $stmt = $db->prepare("SELECT * FROM dokumentvorlagen WHERE school_id = ?");
            $stmt->execute([$school_id]);
            $existingTemplate = $stmt->fetch();
            
            // Wenn vorhanden, alte Datei löschen
            if ($existingTemplate && file_exists($existingTemplate['dateipfad'])) {
                unlink($existingTemplate['dateipfad']);
            }
            
            // Sichere Dateiname erstellen mit Schulname
            $stmt = $db->prepare("SELECT name FROM schools WHERE id = ?");
            $stmt->execute([$school_id]);
            $schoolName = $stmt->fetchColumn();
            
            // Schulname für Dateinamen säubern
            $safeSchoolName = preg_replace('/[^a-zA-Z0-9-_]/', '_', $schoolName);
            $safeSchoolName = substr($safeSchoolName, 0, 50); // Länge begrenzen
            
            $newFileName = $school_id . '_' . $safeSchoolName . '_vorlage.' . $fileExtension;
            $targetPath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                if ($existingTemplate) {
                    $stmt = $db->prepare("UPDATE dokumentvorlagen SET dateiname = ?, dateipfad = ?, dateityp = ?, upload_datum = NOW() WHERE school_id = ?");
                    $stmt->execute([$file['name'], $targetPath, $fileExtension, $school_id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO dokumentvorlagen (school_id, dateiname, dateipfad, dateityp) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$school_id, $file['name'], $targetPath, $fileExtension]);
                }
                $message = "Dokumentvorlage erfolgreich hochgeladen!";
            } else {
                $error = "Fehler beim Hochladen der Datei.";
            }
        }
    } elseif ($_POST['action'] === 'add_placeholder') {
        $platzhalter = trim($_POST['platzhalter']);
        $anzeigename = trim($_POST['anzeigename']);
        
        if (!preg_match('/^\[[\w\s]+\]$/', $platzhalter)) {
            $error = "Platzhalter müssen das Format [Name] haben.";
        } else {
            $stmt = $db->prepare("INSERT INTO platzhalter_mappings (school_id, platzhalter, datenbank_feld, anzeigename, ist_system) VALUES (?, ?, ?, ?, FALSE)");
            try {
                $stmt->execute([$school_id, $platzhalter, 'custom_' . time(), $anzeigename]);
                $message = "Platzhalter erfolgreich hinzugefügt!";
            } catch (PDOException $e) {
                $error = "Dieser Platzhalter existiert bereits.";
            }
        }
    } elseif ($_POST['action'] === 'update_mapping') {
        $mapping_id = $_POST['mapping_id'];
        $datenbank_feld = $_POST['datenbank_feld'];
        
        $stmt = $db->prepare("UPDATE platzhalter_mappings SET datenbank_feld = ? WHERE id = ? AND school_id = ?");
        $stmt->execute([$datenbank_feld, $mapping_id, $school_id]);
        $message = "Zuordnung erfolgreich aktualisiert!";
    } elseif ($_POST['action'] === 'delete_placeholder') {
        $mapping_id = $_POST['mapping_id'];
        
        $stmt = $db->prepare("DELETE FROM platzhalter_mappings WHERE id = ? AND school_id = ? AND ist_system = FALSE");
        $stmt->execute([$mapping_id, $school_id]);
        $message = "Platzhalter erfolgreich gelöscht!";
    }
}

// Aktuelle Dokumentvorlage abrufen
$stmt = $db->prepare("SELECT * FROM dokumentvorlagen WHERE school_id = ?");
$stmt->execute([$school_id]);
$currentTemplate = $stmt->fetch();

// Platzhalter-Mappings abrufen
$stmt = $db->prepare("SELECT * FROM platzhalter_mappings WHERE school_id = ? ORDER BY ist_system DESC, platzhalter");
$stmt->execute([$school_id]);
$mappings = $stmt->fetchAll();

// Verfügbare Datenbankfelder mit klarer Zuordnung
$availableFields = [
    // SCHÜLERDATEN (aus Tabelle: students)
    'student_name' => 'Schülername (Vor- und Nachname)',
    'student_firstname' => 'Vorname des Schülers',
    'student_lastname' => 'Nachname des Schülers',
    
    // PROJEKTDATEN (aus Tabelle: groups)
    'project_name' => 'Projektname/Gruppenname',
    
    // PRÜFUNGSFACH (aus Tabelle: subjects über group_students)
    'exam_subject' => 'Prüfungsfach (vollständiger Name)',
    'exam_subject_short' => 'Prüfungsfach (Kürzel)',
    
    // BEWERTUNGEN (aus Tabelle: ratings)
    'final_grade' => 'Gesamtnote (z.B. 2,3)',
    'comment' => 'Kommentar/Bemerkung',
    
    // STÄRKEN (aus Tabelle: strength_items über rating_strengths)
    'strengths_list' => 'Stärken (als Liste)',
    
    // SCHUL- UND KLASSENDATEN
    'class_name' => 'Klassenbezeichnung',
    'school_name' => 'Schulname',
    'school_year' => 'Schuljahr (berechnet)',
    
    // LEHRKRAFT (aus Tabelle: users)
    'teacher_name' => 'Name der Lehrkraft',
    
    // DATUM
    'current_date' => 'Aktuelles Datum',
    'current_date_long' => 'Datum ausgeschrieben'
];
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dokumentvorlagen verwalten</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background-color: #0a0a0a;
            color: #e0e0e0;
            min-height: 100vh;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 1px solid #333;
        }

        h1 {
            font-size: 2.5em;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
        }

        .back-button {
            background: #2d2d2d;
            color: #e0e0e0;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .back-button:hover {
            background: #3d3d3d;
            transform: translateY(-2px);
        }

        .section {
            background: #1a1a1a;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }

        .section h2 {
            font-size: 1.8em;
            margin-bottom: 20px;
            color: #667eea;
        }

        .upload-area {
            border: 2px dashed #333;
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            transition: all 0.3s ease;
            background: #0d0d0d;
        }

        .upload-area:hover {
            border-color: #667eea;
            background: #111;
        }

        .file-input {
            display: none;
        }

        .file-label {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .file-label:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .current-file {
            margin-top: 20px;
            padding: 15px;
            background: #2d2d2d;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .file-icon {
            width: 40px;
            height: 40px;
            background: #667eea;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 12px;
            background: #2d2d2d;
            border: 1px solid #333;
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #667eea;
            background: #333;
        }

        .placeholder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .placeholder-card {
            background: #2d2d2d;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #333;
            transition: all 0.3s ease;
        }

        .placeholder-card:hover {
            border-color: #667eea;
            transform: translateY(-2px);
        }

        .placeholder-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .placeholder-tag {
            font-family: monospace;
            background: #667eea;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 14px;
        }

        .system-badge {
            background: #4a5568;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: slideIn 0.3s ease;
        }

        .message.success {
            background: #2d5a2d;
            border: 1px solid #3d7a3d;
            color: #90ee90;
        }

        .message.error {
            background: #5a2d2d;
            border: 1px solid #7a3d3d;
            color: #ff6b6b;
        }

        .info-box {
            background: #1e3a5f;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .flex-row {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }

        .flex-row .form-group {
            flex: 1;
        }

        select {
            cursor: pointer;
        }

        .db-info {
            font-size: 11px;
            color: #888;
            margin-top: 5px;
        }

        .standard-placeholders {
            background: #0d0d0d;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }

        .standard-placeholders h3 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 1.2em;
        }

        .placeholder-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
        }

        .placeholder-item {
            padding: 8px;
            background: #1a1a1a;
            border-radius: 4px;
            font-family: monospace;
            font-size: 14px;
            color: #e0e0e0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Dokumentvorlagen verwalten</h1>
            <a href="dashboard.php" class="back-button">← Zurück zum Dashboard</a>
        </div>

        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Dokumentvorlage Upload -->
        <div class="section">
            <h2>Dokumentvorlage hochladen</h2>
            <div class="info-box">
                <p>Laden Sie hier Ihre Dokumentvorlage (DOCX, RTF oder ODT) hoch. Die Datei darf maximal 8 MB groß sein. Pro Schule kann nur eine Vorlage gespeichert werden.</p>
            </div>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <div class="upload-area">
                    <input type="file" id="dokumentvorlage" name="dokumentvorlage" class="file-input" accept=".docx,.rtf,.odt" required>
                    <label for="dokumentvorlage" class="file-label">Datei auswählen</label>
                    <p style="margin-top: 15px; color: #999;">oder ziehen Sie die Datei hierher</p>
                </div>
                
                <?php if ($currentTemplate): ?>
                    <div class="current-file">
                        <div class="file-info">
                            <div class="file-icon"><?php echo strtoupper($currentTemplate['dateityp']); ?></div>
                            <div>
                                <strong><?php echo htmlspecialchars($currentTemplate['dateiname']); ?></strong>
                                <br>
                                <small>Hochgeladen am: <?php echo date('d.m.Y H:i', strtotime($currentTemplate['upload_datum'])); ?></small>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Ersetzen</button>
                    </div>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary" style="margin-top: 20px; width: 100%;">Hochladen</button>
                <?php endif; ?>
            </form>
        </div>

        <!-- Standard-Platzhalter -->
        <div class="section">
            <h2>Verfügbare Standard-Platzhalter</h2>
            <div class="info-box">
                <p>Diese Platzhalter können Sie direkt in Ihrer Dokumentvorlage verwenden. Sie werden automatisch mit den entsprechenden Daten gefüllt.</p>
            </div>
            
            <div class="standard-placeholders">
                <h3>Kopieren Sie diese Platzhalter in Ihre Vorlage:</h3>
                <div class="placeholder-list">
                    <div class="placeholder-item">[Schülername]</div>
                    <div class="placeholder-item">[Vorname]</div>
                    <div class="placeholder-item">[Nachname]</div>
                    <div class="placeholder-item">[Projektname]</div>
                    <div class="placeholder-item">[Prüfungsfach]</div>
                    <div class="placeholder-item">[Fach Kürzel]</div>
                    <div class="placeholder-item">[Note]</div>
                    <div class="placeholder-item">[Gesamtnote]</div>
                    <div class="placeholder-item">[Note in Textform]</div>
                    <div class="placeholder-item">[Stärken]</div>
                    <div class="placeholder-item">[Kommentar]</div>
                    <div class="placeholder-item">[Bemerkung]</div>
                    <div class="placeholder-item">[Klasse]</div>
                    <div class="placeholder-item">[Schule]</div>
                    <div class="placeholder-item">[Schulname]</div>
                    <div class="placeholder-item">[Schuljahr]</div>
                    <div class="placeholder-item">[Lehrkraft]</div>
                    <div class="placeholder-item">[Datum]</div>
                    <div class="placeholder-item">[Datum lang]</div>
                </div>
            </div>
        </div>

        <!-- Eigene Platzhalter verwalten -->
        <div class="section">
            <h2>Eigene Platzhalter verwalten</h2>
            <div class="info-box">
                <p>Erstellen Sie eigene Platzhalter und verknüpfen Sie diese mit Datenbankfeldern.</p>
            </div>

            <!-- Neuen Platzhalter hinzufügen -->
            <form method="POST" style="margin-bottom: 30px;">
                <input type="hidden" name="action" value="add_placeholder">
                <div class="flex-row">
                    <div class="form-group">
                        <label>Platzhalter (z.B. [MeinFeld])</label>
                        <input type="text" name="platzhalter" pattern="\[[\w\s]+\]" placeholder="[MeinPlatzhalter]" required>
                    </div>
                    <div class="form-group">
                        <label>Anzeigename</label>
                        <input type="text" name="anzeigename" placeholder="Mein Feld" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="height: fit-content;">Hinzufügen</button>
                </div>
            </form>

            <!-- Bestehende Platzhalter -->
            <div class="placeholder-grid">
                <?php foreach ($mappings as $mapping): ?>
                    <div class="placeholder-card">
                        <div class="placeholder-header">
                            <span class="placeholder-tag"><?php echo htmlspecialchars($mapping['platzhalter']); ?></span>
                            <?php if ($mapping['ist_system']): ?>
                                <span class="system-badge">System</span>
                            <?php endif; ?>
                        </div>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="update_mapping">
                            <input type="hidden" name="mapping_id" value="<?php echo $mapping['id']; ?>">
                            
                            <div class="form-group">
                                <label>Verknüpfung mit Datenbankfeld</label>
                                <select name="datenbank_feld" onchange="this.form.submit()">
                                    <option value="">-- Bitte wählen --</option>
                                    <?php foreach ($availableFields as $field => $label): ?>
                                        <option value="<?php echo $field; ?>" <?php echo $mapping['datenbank_feld'] === $field ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="db-info">
                                    <?php if ($mapping['datenbank_feld'] && isset($availableFields[$mapping['datenbank_feld']])): ?>
                                        Aktuell: <?php echo htmlspecialchars($availableFields[$mapping['datenbank_feld']]); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                        
                        <?php if (!$mapping['ist_system']): ?>
                            <form method="POST" style="margin-top: 10px;">
                                <input type="hidden" name="action" value="delete_placeholder">
                                <input type="hidden" name="mapping_id" value="<?php echo $mapping['id']; ?>">
                                <button type="submit" class="btn btn-danger" onclick="return confirm('Möchten Sie diesen Platzhalter wirklich löschen?')">Löschen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Verwendungshinweise -->
        <div class="section">
            <h2>Verwendungshinweise</h2>
            <div class="info-box">
                <h3>So funktioniert's:</h3>
                <ol style="margin-left: 20px; margin-top: 10px;">
                    <li>Erstellen Sie Ihre Dokumentvorlage in Word, LibreOffice oder einem anderen Textverarbeitungsprogramm</li>
                    <li>Fügen Sie die Platzhalter aus der obigen Liste ein (z.B. <strong>[Schülername]</strong>, <strong>[Note]</strong>, <strong>[Prüfungsfach]</strong>)</li>
                    <li>Formatieren Sie die Vorlage nach Ihren Wünschen (Schriftart, Größe, Farben bleiben erhalten)</li>
                    <li>Laden Sie die Vorlage hier hoch</li>
                    <li>Beim PDF-Export werden die Platzhalter automatisch mit den Daten ersetzt</li>
                </ol>
                
                <h3 style="margin-top: 20px;">Wichtige Platzhalter:</h3>
                <ul style="margin-left: 20px; margin-top: 10px;">
                    <li><strong>[Prüfungsfach]</strong> - Das Fach, das dem Schüler in der Gruppe zugeordnet wurde</li>
                    <li><strong>[Fach Kürzel]</strong> - Das Kürzel des Prüfungsfachs (z.B. "D" für Deutsch)</li>
                    <li><strong>[Stärken]</strong> - Listet alle ausgewählten Stärken als Aufzählung auf</li>
                    <li><strong>[Note]</strong> oder <strong>[Gesamtnote]</strong> - Die finale Bewertung (z.B. 2,3)</li>
                    <li><strong>[Note in Textform]</strong> - Die Note ausgeschrieben (z.B. "gut")</li>
                    <li><strong>[Datum]</strong> - Aktuelles Datum im Format TT.MM.JJJJ</li>
                    <li><strong>[Datum lang]</strong> - Datum ausgeschrieben (z.B. "21. Juni 2025")</li>
                </ul>

                <h3 style="margin-top: 20px;">Datenbankfelder:</h3>
                <p style="margin-top: 10px;">Die Daten kommen aus folgenden Quellen:</p>
                <ul style="margin-left: 20px; margin-top: 10px;">
                    <li><strong>Schülerdaten</strong> - aus der Tabelle "students"</li>
                    <li><strong>Projektname</strong> - aus der Tabelle "groups"</li>
                    <li><strong>Prüfungsfach</strong> - aus der Tabelle "subjects" (über die Schüler-Gruppen-Zuordnung)</li>
                    <li><strong>Bewertungen & Kommentare</strong> - aus der Tabelle "ratings"</li>
                    <li><strong>Stärken</strong> - aus der Tabelle "strength_items"</li>
                    <li><strong>Schul- und Klassendaten</strong> - aus den Tabellen "schools" und "classes"</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        // Datei-Upload Preview
        document.getElementById('dokumentvorlage').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name;
            if (fileName) {
                const label = document.querySelector('.file-label');
                label.textContent = fileName;
            }
        });

        // Drag & Drop funktionalität
        const uploadArea = document.querySelector('.upload-area');
        
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            uploadArea.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, unhighlight, false);
        });
        
        function highlight(e) {
            uploadArea.style.borderColor = '#667eea';
            uploadArea.style.background = '#111';
        }
        
        function unhighlight(e) {
            uploadArea.style.borderColor = '#333';
            uploadArea.style.background = '#0d0d0d';
        }
        
        uploadArea.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            
            document.getElementById('dokumentvorlage').files = files;
            
            if (files.length > 0) {
                document.querySelector('.file-label').textContent = files[0].name;
            }
        }
    </script>
</body>
</html>