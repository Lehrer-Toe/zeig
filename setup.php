<?php
/**
 * Setup-Script für "Zeig, was du kannst"
 * Führt die erste Installation und Konfiguration durch
 */

// Sicherheitscheck - Setup nur einmal ausführen
$setupFile = __DIR__ . '/.setup_complete';
if (file_exists($setupFile)) {
    die('Setup wurde bereits durchgeführt. Löschen Sie die Datei .setup_complete um erneut zu installieren.');
}

require_once 'config.php';

$errors = [];
$success = [];

// Setup verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = $_POST['step'] ?? 'database';
    
    if ($step === 'database') {
        try {
            // Datenbankverbindung testen
            $db = getDB();
            $success[] = 'Datenbankverbindung erfolgreich hergestellt.';
            
            // Tabellen erstellen
            $sqlFile = __DIR__ . '/database_schema.sql';
            if (file_exists($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                $statements = explode(';', $sql);
                
                foreach ($statements as $statement) {
                    $statement = trim($statement);
                    if (!empty($statement)) {
                        $db->exec($statement);
                    }
                }
                $success[] = 'Datenbank-Tabellen erfolgreich erstellt.';
            }
            
            // Superadmin erstellen
            $passwordHash = password_hash('wandermaus17', PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("
                INSERT INTO users (email, password_hash, user_type, name, is_active, first_login) 
                VALUES (?, ?, 'superadmin', 'Super Administrator', 1, 0)
                ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)
            ");
            
            $stmt->execute(['tilama@mail.de', $passwordHash]);
            $success[] = 'Superadmin erfolgreich erstellt/aktualisiert.';
            
            // Standard-Bewertungskriterien einfügen
            $criteria = [
                ['Kreativität', 'Bewertung der kreativen Leistung', 10],
                ['Teamarbeit', 'Bewertung der Zusammenarbeit im Team', 10],
                ['Präsentation', 'Bewertung der Präsentationsfähigkeiten', 10],
                ['Fachliche Kompetenz', 'Bewertung des fachlichen Wissens', 10],
                ['Selbstständigkeit', 'Bewertung der eigenständigen Arbeitsweise', 10]
            ];
            
            $stmt = $db->prepare("
                INSERT INTO rating_criteria (name, description, max_points) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE description = VALUES(description), max_points = VALUES(max_points)
            ");
            
            foreach ($criteria as $criterion) {
                $stmt->execute($criterion);
            }
            $success[] = 'Standard-Bewertungskriterien erstellt.';
            
            // Verzeichnisse erstellen
            $dirs = ['logs', 'backups', 'uploads'];
            foreach ($dirs as $dir) {
                $dirPath = __DIR__ . '/' . $dir;
                if (!is_dir($dirPath)) {
                    mkdir($dirPath, 0755, true);
                    file_put_contents($dirPath . '/.htaccess', "Deny from all\n");
                }
            }
            $success[] = 'Verzeichnisse erstellt.';
            
            // Setup als abgeschlossen markieren
            file_put_contents($setupFile, date('Y-m-d H:i:s') . " - Setup completed\n");
            
            $success[] = 'Installation erfolgreich abgeschlossen!';
            
        } catch (Exception $e) {
            $errors[] = 'Datenbankfehler: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup - <?php echo APP_NAME; ?></title>
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
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .setup-container {
            max-width: 600px;
            width: 100%;
            margin: 2rem;
        }

        .setup-card {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            padding: 2rem;
            backdrop-filter: blur(10px);
        }

        .setup-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .setup-header h1 {
            color: #3b82f6;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .setup-header p {
            opacity: 0.8;
            font-size: 1.1rem;
        }

        .status-section {
            margin-bottom: 2rem;
        }

        .status-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .status-icon {
            font-size: 1.5rem;
            margin-right: 1rem;
            width: 2rem;
        }

        .status-ok { color: #22c55e; }
        .status-error { color: #ef4444; }
        .status-pending { color: #f59e0b; }

        .success-list {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .success-list h4 {
            color: #86efac;
            margin-bottom: 0.5rem;
        }

        .success-list ul {
            color: #86efac;
            margin-left: 1.5rem;
        }

        .error-list {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .error-list h4 {
            color: #fca5a5;
            margin-bottom: 0.5rem;
        }

        .error-list ul {
            color: #fca5a5;
            margin-left: 1.5rem;
        }

        .btn {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border: none;
            border-radius: 0.5rem;
            color: white;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            transform: translateY(-1px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .config-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 2rem;
        }

        .config-info h4 {
            color: #93c5fd;
            margin-bottom: 0.5rem;
        }

        .config-info code {
            background: rgba(0, 0, 0, 0.3);
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            color: #fbbf24;
        }

        .completion-message {
            text-align: center;
            padding: 2rem;
        }

        .completion-message .icon {
            font-size: 4rem;
            color: #22c55e;
            margin-bottom: 1rem;
        }

        .login-link {
            display: inline-block;
            margin-top: 1rem;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
            text-decoration: none;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .login-link:hover {
            background: linear-gradient(135deg, #16a34a, #15803d);
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-card">
            <div class="setup-header">
                <h1>🚀 Setup</h1>
                <p><?php echo APP_NAME; ?></p>
            </div>

            <?php if (!empty($success)): ?>
                <div class="success-list">
                    <h4>✅ Erfolgreich:</h4>
                    <ul>
                        <?php foreach ($success as $msg): ?>
                            <li><?php echo htmlspecialchars($msg); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="error-list">
                    <h4>❌ Fehler:</h4>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($success) || !empty($errors)): ?>
                <div class="config-info">
                    <h4>📋 Konfiguration</h4>
                    <p><strong>Datenbank:</strong> <code><?php echo DB_NAME; ?></code></p>
                    <p><strong>Host:</strong> <code><?php echo DB_HOST; ?></code></p>
                    <p><strong>Benutzer:</strong> <code><?php echo DB_USER; ?></code></p>
                    <p><strong>Superadmin:</strong> <code>tilama@mail.de</code> / <code>wandermaus17</code></p>
                </div>

                <div class="status-section">
                    <h3 style="margin-bottom: 1rem; color: #3b82f6;">System-Checks</h3>
                    
                    <div class="status-item">
                        <span class="status-icon <?php echo version_compare(PHP_VERSION, '7.4.0', '>=') ? 'status-ok' : 'status-error'; ?>">
                            <?php echo version_compare(PHP_VERSION, '7.4.0', '>=') ? '✅' : '❌'; ?>
                        </span>
                        <div>
                            <strong>PHP Version</strong><br>
                            Aktuell: <?php echo PHP_VERSION; ?> (Mindestens 7.4.0 erforderlich)
                        </div>
                    </div>

                    <div class="status-item">
                        <span class="status-icon <?php echo extension_loaded('pdo') ? 'status-ok' : 'status-error'; ?>">
                            <?php echo extension_loaded('pdo') ? '✅' : '❌'; ?>
                        </span>
                        <div>
                            <strong>PDO Extension</strong><br>
                            <?php echo extension_loaded('pdo') ? 'Verfügbar' : 'Nicht verfügbar'; ?>
                        </div>
                    </div>

                    <div class="status-item">
                        <span class="status-icon <?php echo extension_loaded('pdo_mysql') ? 'status-ok' : 'status-error'; ?>">
                            <?php echo extension_loaded('pdo_mysql') ? '✅' : '❌'; ?>
                        </span>
                        <div>
                            <strong>MySQL PDO Extension</strong><br>
                            <?php echo extension_loaded('pdo_mysql') ? 'Verfügbar' : 'Nicht verfügbar'; ?>
                        </div>
                    </div>

                    <div class="status-item">
                        <span class="status-icon <?php echo is_writable(__DIR__) ? 'status-ok' : 'status-error'; ?>">
                            <?php echo is_writable(__DIR__) ? '✅' : '❌'; ?>
                        </span>
                        <div>
                            <strong>Schreibrechte</strong><br>
                            <?php echo is_writable(__DIR__) ? 'Verzeichnis ist beschreibbar' : 'Verzeichnis ist nicht beschreibbar'; ?>
                        </div>
                    </div>

                    <?php
                    $dbConnection = false;
                    try {
                        $db = getDB();
                        $dbConnection = true;
                    } catch (Exception $e) {
                        $dbConnectionError = $e->getMessage();
                    }
                    ?>

                    <div class="status-item">
                        <span class="status-icon <?php echo $dbConnection ? 'status-ok' : 'status-error'; ?>">
                            <?php echo $dbConnection ? '✅' : '❌'; ?>
                        </span>
                        <div>
                            <strong>Datenbankverbindung</strong><br>
                            <?php echo $dbConnection ? 'Verbindung erfolgreich' : 'Fehler: ' . ($dbConnectionError ?? 'Unbekannt'); ?>
                        </div>
                    </div>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="step" value="database">
                    <button type="submit" class="btn" 
                            <?php echo !$dbConnection ? 'disabled' : ''; ?>>
                        🔧 Installation starten
                    </button>
                </form>

            <?php else: ?>
                <div class="completion-message">
                    <div class="icon">🎉</div>
                    <h2 style="color: #22c55e; margin-bottom: 1rem;">Installation erfolgreich!</h2>
                    <p>Das System wurde erfolgreich installiert und konfiguriert.</p>
                    <p>Sie können sich jetzt mit den Superadmin-Zugangsdaten anmelden:</p>
                    <p><strong>E-Mail:</strong> tilama@mail.de<br>
                       <strong>Passwort:</strong> wandermaus17</p>
                    
                    <a href="index.php" class="login-link">
                        🔑 Zum Login
                    </a>
                    
                    <div style="margin-top: 2rem; font-size: 0.9rem; opacity: 0.7;">
                        <p>⚠️ <strong>Sicherheitshinweis:</strong></p>
                        <p>Löschen Sie diese setup.php Datei nach der Installation aus Sicherheitsgründen.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>