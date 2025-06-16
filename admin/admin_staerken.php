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
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stärkenverwaltung - <?php echo APP_NAME; ?></title>
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

        .btn-secondary {
            background: rgba(100, 116, 139, 0.2);
            color: #cbd5e1;
            border: 1px solid rgba(100, 116, 139, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(100, 116, 139, 0.3);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .coming-soon {
            text-align: center;
            padding: 4rem 2rem;
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(245, 158, 11, 0.2);
            border-radius: 2rem;
            backdrop-filter: blur(10px);
        }

        .coming-soon .icon {
            font-size: 5rem;
            margin-bottom: 2rem;
            color: #f59e0b;
            animation: flex 2s ease-in-out infinite;
        }

        @keyframes flex {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .coming-soon h2 {
            font-size: 2.5rem;
            color: #f59e0b;
            margin-bottom: 1rem;
        }

        .coming-soon p {
            font-size: 1.1rem;
            opacity: 0.8;
            line-height: 1.6;
            margin-bottom: 2rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .features-preview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 3rem;
        }

        .feature-card {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.2);
            border-radius: 1rem;
            padding: 1.5rem;
            text-align: left;
        }

        .feature-card .icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: #f59e0b;
        }

        .feature-card h3 {
            color: #f59e0b;
            margin-bottom: 0.5rem;
        }

        .feature-card p {
            opacity: 0.8;
            line-height: 1.5;
        }

        .progress-section {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.2);
            border-radius: 1rem;
            padding: 2rem;
            margin-top: 3rem;
        }

        .progress-section h3 {
            color: #f59e0b;
            margin-bottom: 1rem;
        }

        .progress-bar {
            background: rgba(0, 0, 0, 0.3);
            height: 20px;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .progress-fill {
            background: linear-gradient(90deg, #f59e0b, #d97706);
            height: 100%;
            width: 10%;
            border-radius: 10px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .coming-soon {
                padding: 2rem 1rem;
            }
            
            .coming-soon h2 {
                font-size: 2rem;
            }
            
            .features-preview {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>💪 Stärkenverwaltung</h1>
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a> / Stärkenverwaltung
            </div>
        </div>
        <a href="dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
    </div>

    <div class="container">
        <div class="coming-soon">
            <div class="icon">💪</div>
            <h2>Stärkenverwaltung</h2>
            <p>
                Die Stärkenverwaltung ist für die Zukunft geplant. Hier werden Sie 
                individuelle Kompetenzbereiche definieren, Stärkenprofile erstellen und 
                eine differenzierte, stärkenorientierte Bewertung ermöglichen können.
            </p>

            <div class="progress-section">
                <h3>Entwicklungsfortschritt</h3>
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
                <p>10% - Erste Konzeptideen</p>
            </div>
        </div>

        <div class="features-preview">
            <div class="feature-card">
                <div class="icon">🎯</div>
                <h3>Kompetenz-Profile</h3>
                <p>Erstellen Sie individuelle Kompetenzprofile für verschiedene Bereiche und Fähigkeiten.</p>
            </div>

            <div class="feature-card">
                <div class="icon">📈</div>
                <h3>Stärken-Entwicklung</h3>
                <p>Verfolgen Sie die Entwicklung der Stärken jedes Schülers über die Zeit.</p>
            </div>

            <div class="feature-card">
                <div class="icon">🔍</div>
                <h3>Stärken-Analyse</h3>
                <p>Analysieren Sie die Stärkenverteilung in Klassen und identifizieren Sie Potentiale.</p>
            </div>

            <div class="feature-card">
                <div class="icon">📊</div>
                <h3>Bewertungsraster</h3>
                <p>Entwickeln Sie differenzierte Bewertungsraster für verschiedene Kompetenzbereiche.</p>
            </div>

            <div class="feature-card">
                <div class="icon">🏆</div>
                <h3>Erfolgs-Tracking</h3>
                <p>Dokumentieren Sie Erfolge und Fortschritte in den verschiedenen Stärkenbereichen.</p>
            </div>

            <div class="feature-card">
                <div class="icon">📋</div>
                <h3>Förderempfehlungen</h3>
                <p>Generieren Sie automatische Förderempfehlungen basierend auf Stärkenprofilen.</p>
            </div>
        </div>
    </div>
</body>
</html>