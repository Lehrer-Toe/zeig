<?php
/**
 * Security Dashboard für Administratoren
 * Zeigt Security-Statistiken und Logs
 */

require_once '../config.php';

// Nur Superadmins haben Zugriff
$user = requireSuperadmin();

// Security-Statistiken aus vorhandenen Tabellen abrufen
$db = getDB();

// Login-Statistiken (aus users Tabelle)
$stats = [];

// Aktive Benutzer (die in den letzten 24h aktiv waren)
try {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT id) as active_users 
        FROM users 
        WHERE last_login > DATE_SUB(NOW(), INTERVAL 24 HOUR) 
        AND is_active = 1
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $stats['active_users'] = $result['active_users'] ?? 0;
} catch (Exception $e) {
    $stats['active_users'] = 0;
}

// Gesamtzahl aktiver Sessions (geschätzt basierend auf aktiven Benutzern)
$stats['active_sessions'] = $stats['active_users'];

// Inaktive Benutzer (nicht in den letzten 30 Tagen eingeloggt)
try {
    $stmt = $db->prepare("
        SELECT COUNT(*) as inactive_users 
        FROM users 
        WHERE (last_login < DATE_SUB(NOW(), INTERVAL 30 DAY) OR last_login IS NULL)
        AND is_active = 1
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $stats['inactive_users'] = $result['inactive_users'] ?? 0;
} catch (Exception $e) {
    $stats['inactive_users'] = 0;
}

// Gesperrte Benutzer
try {
    $stmt = $db->prepare("SELECT COUNT(*) as locked_users FROM users WHERE is_active = 0");
    $stmt->execute();
    $result = $stmt->fetch();
    $stats['locked_users'] = $result['locked_users'] ?? 0;
} catch (Exception $e) {
    $stats['locked_users'] = 0;
}

// Benutzer nach Typ
try {
    $stmt = $db->prepare("
        SELECT 
            user_type,
            COUNT(*) as count
        FROM users
        WHERE is_active = 1
        GROUP BY user_type
    ");
    $stmt->execute();
    $userTypes = $stmt->fetchAll();
} catch (Exception $e) {
    $userTypes = [];
}

// Letzte Logins
try {
    $stmt = $db->prepare("
        SELECT 
            u.email,
            u.name,
            u.user_type,
            u.last_login,
            s.name as school_name
        FROM users u
        LEFT JOIN schools s ON u.school_id = s.id
        WHERE u.last_login IS NOT NULL
        ORDER BY u.last_login DESC
        LIMIT 20
    ");
    $stmt->execute();
    $recentLogins = $stmt->fetchAll();
} catch (Exception $e) {
    $recentLogins = [];
}

// Login-Aktivität der letzten 7 Tage
try {
    $stmt = $db->prepare("
        SELECT 
            DATE(last_login) as login_date,
            COUNT(DISTINCT id) as unique_logins
        FROM users
        WHERE last_login > DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(last_login)
        ORDER BY login_date
    ");
    $stmt->execute();
    $loginActivity = $stmt->fetchAll();
} catch (Exception $e) {
    $loginActivity = [];
}

// Schulen mit expiring licenses
try {
    $stmt = $db->prepare("
        SELECT 
            s.name,
            s.license_until,
            s.contact_person,
            s.contact_email,
            DATEDIFF(s.license_until, CURDATE()) as days_remaining,
            COUNT(DISTINCT u.id) as user_count
        FROM schools s
        LEFT JOIN users u ON s.id = u.school_id AND u.is_active = 1
        WHERE s.license_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        AND s.is_active = 1
        GROUP BY s.id
        ORDER BY s.license_until
    ");
    $stmt->execute();
    $expiringLicenses = $stmt->fetchAll();
} catch (Exception $e) {
    $expiringLicenses = [];
}

// Abgelaufene Lizenzen
try {
    $stmt = $db->prepare("
        SELECT 
            s.name,
            s.license_until,
            s.contact_person,
            s.contact_email,
            DATEDIFF(CURDATE(), s.license_until) as days_expired,
            COUNT(DISTINCT u.id) as user_count
        FROM schools s
        LEFT JOIN users u ON s.id = u.school_id
        WHERE s.license_until < CURDATE()
        AND s.is_active = 1
        GROUP BY s.id
        ORDER BY s.license_until DESC
        LIMIT 10
    ");
    $stmt->execute();
    $expiredLicenses = $stmt->fetchAll();
} catch (Exception $e) {
    $expiredLicenses = [];
}

// Systemstatistiken
try {
    $stmt = $db->query("SELECT COUNT(*) as total FROM schools");
    $stats['total_schools'] = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM schools WHERE is_active = 1 AND license_until >= CURDATE()");
    $stats['active_schools'] = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM classes WHERE is_active = 1");
    $stats['total_classes'] = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM students WHERE is_active = 1");
    $stats['total_students'] = $stmt->fetch()['total'];
} catch (Exception $e) {
    $stats['total_schools'] = 0;
    $stats['active_schools'] = 0;
    $stats['total_classes'] = 0;
    $stats['total_students'] = 0;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Dashboard - <?php echo APP_NAME; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0a0a;
            color: #e0e0e0;
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Cyber-Security Theme */
        .dashboard {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            border: 1px solid #ff0066;
            border-radius: 10px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 0 20px rgba(255, 0, 102, 0.3);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 0, 102, 0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }

        h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            color: #ff0066;
            text-shadow: 0 0 10px rgba(255, 0, 102, 0.5);
            position: relative;
            z-index: 1;
        }

        .subtitle {
            color: #888;
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: linear-gradient(135deg, #1a1a1a 0%, #262626 100%);
            border: 1px solid #333;
            border-radius: 10px;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            border-color: #ff0066;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(255, 0, 102, 0.2);
        }

        .stat-card.danger {
            border-color: #ff3366;
            background: linear-gradient(135deg, #2a1a1a 0%, #3a2626 100%);
        }

        .stat-card.warning {
            border-color: #ffaa00;
            background: linear-gradient(135deg, #2a2a1a 0%, #3a3a26 100%);
        }

        .stat-card.success {
            border-color: #00ff88;
            background: linear-gradient(135deg, #1a2a1a 0%, #263a26 100%);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0.5rem 0;
            font-family: 'Courier New', monospace;
            letter-spacing: 2px;
        }

        .stat-label {
            color: #888;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-icon {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 3rem;
            opacity: 0.1;
        }

        /* Tables */
        .section {
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 10px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            color: #ff0066;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Courier New', monospace;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #333;
        }

        th {
            background: #262626;
            color: #ff0066;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 1px;
        }

        tr:hover {
            background: rgba(255, 0, 102, 0.05);
        }

        .email-address {
            font-family: 'Courier New', monospace;
            color: #00ff88;
        }

        .danger-text {
            color: #ff3366;
            font-weight: bold;
        }

        .warning-text {
            color: #ffaa00;
        }

        .success-text {
            color: #00ff88;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-danger {
            background: rgba(255, 51, 102, 0.2);
            border: 1px solid #ff3366;
            color: #ff3366;
        }

        .badge-warning {
            background: rgba(255, 170, 0, 0.2);
            border: 1px solid #ffaa00;
            color: #ffaa00;
        }

        .badge-success {
            background: rgba(0, 255, 136, 0.2);
            border: 1px solid #00ff88;
            color: #00ff88;
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.2);
            border: 1px solid #3b82f6;
            color: #3b82f6;
        }

        /* Actions */
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 5px;
            border: 1px solid #ff0066;
            background: transparent;
            color: #ff0066;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.9rem;
        }

        .btn:hover {
            background: #ff0066;
            color: #000;
            box-shadow: 0 0 20px rgba(255, 0, 102, 0.5);
        }

        .btn-primary {
            border-color: #3b82f6;
            color: #3b82f6;
        }

        .btn-primary:hover {
            background: #3b82f6;
        }

        /* Chart Container */
        .chart-container {
            height: 300px;
            margin-top: 1rem;
            position: relative;
            background: #0a0a0a;
            border-radius: 5px;
            padding: 1rem;
        }

        /* Alert Box */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border: 1px solid;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .alert-danger {
            background: rgba(255, 51, 102, 0.1);
            border-color: #ff3366;
            color: #ff3366;
        }

        .alert-warning {
            background: rgba(255, 170, 0, 0.1);
            border-color: #ffaa00;
            color: #ffaa00;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 0.85rem;
            }

            th, td {
                padding: 0.5rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                text-align: center;
            }
        }

        /* Matrix Rain Effect */
        .matrix-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            opacity: 0.05;
            pointer-events: none;
        }

        /* User type colors */
        .user-type-admin { color: #ff0066; }
        .user-type-lehrer { color: #3b82f6; }
        .user-type-schueler { color: #00ff88; }
    </style>
</head>
<body>
    <div class="matrix-bg" id="matrix"></div>
    
    <div class="dashboard">
        <div class="header">
            <h1>🛡️ Security Dashboard</h1>
            <div class="subtitle">System Security Monitoring & User Analytics</div>
        </div>

        <!-- Statistics Overview -->
        <div class="stats-grid">
            <div class="stat-card success">
                <div class="stat-icon">👥</div>
                <div class="stat-label">Aktive Benutzer (24h)</div>
                <div class="stat-value"><?php echo number_format($stats['active_users']); ?></div>
            </div>

            <div class="stat-card <?php echo $stats['inactive_users'] > 50 ? 'warning' : ''; ?>">
                <div class="stat-icon">😴</div>
                <div class="stat-label">Inaktive Benutzer (30d)</div>
                <div class="stat-value"><?php echo number_format($stats['inactive_users']); ?></div>
            </div>

            <div class="stat-card <?php echo $stats['locked_users'] > 0 ? 'danger' : ''; ?>">
                <div class="stat-icon">🔒</div>
                <div class="stat-label">Gesperrte Benutzer</div>
                <div class="stat-value"><?php echo number_format($stats['locked_users']); ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🏫</div>
                <div class="stat-label">Aktive Schulen</div>
                <div class="stat-value"><?php echo number_format($stats['active_schools']); ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">📚</div>
                <div class="stat-label">Aktive Klassen</div>
                <div class="stat-value"><?php echo number_format($stats['total_classes']); ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🎓</div>
                <div class="stat-label">Aktive Schüler</div>
                <div class="stat-value"><?php echo number_format($stats['total_students']); ?></div>
            </div>
        </div>

        <!-- User Types Distribution -->
        <?php if (!empty($userTypes)): ?>
        <div class="section">
            <h2 class="section-title">👥 Benutzerverteilung</h2>
            <div class="stats-grid">
                <?php foreach ($userTypes as $type): ?>
                <div class="stat-card">
                    <div class="stat-label"><?php echo ucfirst($type['user_type']); ?></div>
                    <div class="stat-value user-type-<?php echo $type['user_type']; ?>">
                        <?php echo number_format($type['count']); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Expiring Licenses -->
        <?php if (!empty($expiringLicenses)): ?>
        <div class="section">
            <h2 class="section-title">⚠️ Auslaufende Lizenzen (nächste 30 Tage)</h2>
            <table>
                <thead>
                    <tr>
                        <th>Schule</th>
                        <th>Lizenz läuft ab</th>
                        <th>Tage verbleibend</th>
                        <th>Kontaktperson</th>
                        <th>E-Mail</th>
                        <th>Benutzer</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expiringLicenses as $license): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($license['name']); ?></td>
                        <td class="warning-text">
                            <?php echo date('d.m.Y', strtotime($license['license_until'])); ?>
                        </td>
                        <td>
                            <span class="badge badge-warning"><?php echo $license['days_remaining']; ?> Tage</span>
                        </td>
                        <td><?php echo htmlspecialchars($license['contact_person']); ?></td>
                        <td class="email-address"><?php echo htmlspecialchars($license['contact_email']); ?></td>
                        <td><?php echo $license['user_count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Expired Licenses -->
        <?php if (!empty($expiredLicenses)): ?>
        <div class="section">
            <h2 class="section-title">🚫 Abgelaufene Lizenzen</h2>
            <table>
                <thead>
                    <tr>
                        <th>Schule</th>
                        <th>Lizenz abgelaufen am</th>
                        <th>Tage abgelaufen</th>
                        <th>Kontaktperson</th>
                        <th>E-Mail</th>
                        <th>Benutzer</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expiredLicenses as $license): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($license['name']); ?></td>
                        <td class="danger-text">
                            <?php echo date('d.m.Y', strtotime($license['license_until'])); ?>
                        </td>
                        <td>
                            <span class="badge badge-danger"><?php echo $license['days_expired']; ?> Tage</span>
                        </td>
                        <td><?php echo htmlspecialchars($license['contact_person']); ?></td>
                        <td class="email-address"><?php echo htmlspecialchars($license['contact_email']); ?></td>
                        <td><?php echo $license['user_count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Login Activity Chart -->
        <?php if (!empty($loginActivity)): ?>
        <div class="section">
            <h2 class="section-title">📊 Login-Aktivität (letzte 7 Tage)</h2>
            <div class="chart-container">
                <canvas id="loginChart"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Logins -->
        <?php if (!empty($recentLogins)): ?>
        <div class="section">
            <h2 class="section-title">🔑 Letzte Logins</h2>
            <table>
                <thead>
                    <tr>
                        <th>Zeit</th>
                        <th>Benutzer</th>
                        <th>E-Mail</th>
                        <th>Typ</th>
                        <th>Schule</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLogins as $login): ?>
                    <tr>
                        <td><?php echo date('d.m.Y H:i', strtotime($login['last_login'])); ?></td>
                        <td><?php echo htmlspecialchars($login['name'] ?? 'N/A'); ?></td>
                        <td class="email-address"><?php echo htmlspecialchars($login['email']); ?></td>
                        <td>
                            <span class="badge badge-info"><?php echo ucfirst($login['user_type']); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($login['school_name'] ?? 'System'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <button class="btn" onclick="exportReport()">📥 Report exportieren</button>
            <button class="btn" onclick="refreshData()">🔄 Daten aktualisieren</button>
            <a href="dashboard.php" class="btn btn-primary">🏠 Zurück zum Dashboard</a>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script>
        // Matrix Rain Effect
        function createMatrixRain() {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            document.getElementById('matrix').appendChild(canvas);
            
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
            
            const matrix = "ABCDEFGHIJKLMNOPQRSTUVWXYZ123456789@#$%^&*()*&^%+-/~{[|`]}";
            const matrixArray = matrix.split("");
            
            const fontSize = 10;
            const columns = canvas.width / fontSize;
            const drops = Array(Math.floor(columns)).fill(1);
            
            function draw() {
                ctx.fillStyle = 'rgba(0, 0, 0, 0.04)';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                
                ctx.fillStyle = '#0F0';
                ctx.font = fontSize + 'px monospace';
                
                drops.forEach((y, i) => {
                    const text = matrixArray[Math.floor(Math.random() * matrixArray.length)];
                    const x = i * fontSize;
                    ctx.fillText(text, x, y * fontSize);
                    
                    if (y * fontSize > canvas.height && Math.random() > 0.975) {
                        drops[i] = 0;
                    }
                    drops[i]++;
                });
            }
            
            setInterval(draw, 35);
        }
        
        createMatrixRain();
        
        // Login Activity Chart
        <?php if (!empty($loginActivity)): ?>
        const loginData = <?php echo json_encode($loginActivity); ?>;
        
        const ctx = document.getElementById('loginChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: loginData.map(d => {
                    const date = new Date(d.login_date);
                    return date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });
                }),
                datasets: [{
                    label: 'Unique Logins',
                    data: loginData.map(d => d.unique_logins),
                    borderColor: '#00ff88',
                    backgroundColor: 'rgba(0, 255, 136, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: '#e0e0e0'
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            color: '#e0e0e0'
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            color: '#e0e0e0'
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Action Functions
        function exportReport() {
            if (confirm('Möchten Sie einen Security-Report als CSV exportieren?')) {
                // Hier würde normalerweise ein Download-Link generiert
                alert('Report-Export wird vorbereitet...\n(Diese Funktion muss noch implementiert werden)');
            }
        }
        
        function refreshData() {
            location.reload();
        }
        
        // Auto-refresh every 60 seconds
        setInterval(() => {
            location.reload();
        }, 60000);
        
        // Window resize handler for matrix effect
        window.addEventListener('resize', () => {
            const canvas = document.querySelector('#matrix canvas');
            if (canvas) {
                canvas.width = window.innerWidth;
                canvas.height = window.innerHeight;
            }
        });
    </script>
</body>
</html>