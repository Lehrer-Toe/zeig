<?php
session_start();
require_once '../config.php';

// Datenbankverbindung herstellen
try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
}

// Benutzer-Authentifizierung prüfen
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    header('Location: ../login.php');
    exit();
}

// Nur Schuladmins haben Zugriff
if ($_SESSION['user_type'] !== 'schuladmin') {
    die('Zugriff verweigert. Nur Schuladministratoren können auf diese Seite zugreifen.');
}

// Benutzerdaten laden
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || !$user['school_id']) {
    die('Benutzerdaten nicht gefunden.');
}

// Schuldaten laden
$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$user['school_id']]);
$school = $stmt->fetch();

if (!$school) {
    die('Schule nicht gefunden.');
}

// Lizenz prüfen
if (!$school['is_active'] || strtotime($school['license_until']) < time()) {
    die('Die Schullizenz ist abgelaufen oder inaktiv.');
}

// CSRF-Token generieren falls nicht vorhanden
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// AJAX-Handler für verschiedene Aktionen
if (isset($_POST['action']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Ungültiger CSRF-Token']);
        exit;
    }
    
    try {
        switch ($_POST['action']) {
            case 'create_category':
                $name = trim($_POST['name']);
                $icon = trim($_POST['icon']);
                $description = trim($_POST['description']);
                
                // Prüfen ob Maximum erreicht (10 Kategorien)
                $stmt = $db->prepare("SELECT COUNT(*) FROM strength_categories WHERE school_id = ?");
                $stmt->execute([$school['id']]);
                if ($stmt->fetchColumn() >= 10) {
                    throw new Exception('Maximale Anzahl von 10 Kategorien erreicht.');
                }
                
                $stmt = $db->prepare("
                    INSERT INTO strength_categories (school_id, name, icon, description, display_order)
                    VALUES (?, ?, ?, ?, (SELECT COALESCE(MAX(display_order), 0) + 1 FROM strength_categories sc WHERE school_id = ?))
                ");
                $stmt->execute([$school['id'], $name, $icon, $description, $school['id']]);
                
                echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
                exit;
                
            case 'update_category':
                $id = (int)$_POST['id'];
                $name = trim($_POST['name']);
                $icon = trim($_POST['icon']);
                $description = trim($_POST['description']);
                
                $stmt = $db->prepare("
                    UPDATE strength_categories 
                    SET name = ?, icon = ?, description = ? 
                    WHERE id = ? AND school_id = ?
                ");
                $stmt->execute([$name, $icon, $description, $id, $school['id']]);
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'delete_category':
                $id = (int)$_POST['id'];
                
                $stmt = $db->prepare("DELETE FROM strength_categories WHERE id = ? AND school_id = ?");
                $stmt->execute([$id, $school['id']]);
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'toggle_category':
                $id = (int)$_POST['id'];
                
                $stmt = $db->prepare("
                    UPDATE strength_categories 
                    SET is_active = NOT is_active 
                    WHERE id = ? AND school_id = ?
                ");
                $stmt->execute([$id, $school['id']]);
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'reorder_categories':
                $order = json_decode($_POST['order'], true);
                
                $stmt = $db->prepare("
                    UPDATE strength_categories 
                    SET display_order = ? 
                    WHERE id = ? AND school_id = ?
                ");
                
                foreach ($order as $index => $id) {
                    $stmt->execute([$index + 1, $id, $school['id']]);
                }
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'create_item':
                $category_id = (int)$_POST['category_id'];
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                
                // Prüfen ob Maximum erreicht (6 Items pro Kategorie)
                $stmt = $db->prepare("SELECT COUNT(*) FROM strength_items WHERE category_id = ?");
                $stmt->execute([$category_id]);
                if ($stmt->fetchColumn() >= 6) {
                    throw new Exception('Maximale Anzahl von 6 Unterpunkten pro Kategorie erreicht.');
                }
                
                // Prüfen ob Kategorie zur Schule gehört
                $stmt = $db->prepare("SELECT id FROM strength_categories WHERE id = ? AND school_id = ?");
                $stmt->execute([$category_id, $school['id']]);
                if (!$stmt->fetch()) {
                    throw new Exception('Ungültige Kategorie.');
                }
                
                $stmt = $db->prepare("
                    INSERT INTO strength_items (category_id, name, description, display_order)
                    VALUES (?, ?, ?, (SELECT COALESCE(MAX(display_order), 0) + 1 FROM strength_items si WHERE category_id = ?))
                ");
                $stmt->execute([$category_id, $name, $description, $category_id]);
                
                echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
                exit;
                
            case 'update_item':
                $id = (int)$_POST['id'];
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                
                // Prüfen ob Item zur Schule gehört
                $stmt = $db->prepare("
                    SELECT si.id FROM strength_items si
                    JOIN strength_categories sc ON si.category_id = sc.id
                    WHERE si.id = ? AND sc.school_id = ?
                ");
                $stmt->execute([$id, $school['id']]);
                if (!$stmt->fetch()) {
                    throw new Exception('Ungültiger Unterpunkt.');
                }
                
                $stmt = $db->prepare("UPDATE strength_items SET name = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $description, $id]);
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'delete_item':
                $id = (int)$_POST['id'];
                
                // Prüfen ob Item zur Schule gehört
                $stmt = $db->prepare("
                    SELECT si.id FROM strength_items si
                    JOIN strength_categories sc ON si.category_id = sc.id
                    WHERE si.id = ? AND sc.school_id = ?
                ");
                $stmt->execute([$id, $school['id']]);
                if (!$stmt->fetch()) {
                    throw new Exception('Ungültiger Unterpunkt.');
                }
                
                $stmt = $db->prepare("DELETE FROM strength_items WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'toggle_item':
                $id = (int)$_POST['id'];
                
                // Prüfen ob Item zur Schule gehört
                $stmt = $db->prepare("
                    SELECT si.id FROM strength_items si
                    JOIN strength_categories sc ON si.category_id = sc.id
                    WHERE si.id = ? AND sc.school_id = ?
                ");
                $stmt->execute([$id, $school['id']]);
                if (!$stmt->fetch()) {
                    throw new Exception('Ungültiger Unterpunkt.');
                }
                
                $stmt = $db->prepare("UPDATE strength_items SET is_active = NOT is_active WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'init_default_strengths':
                // Prüfen ob bereits Stärken existieren
                $stmt = $db->prepare("SELECT COUNT(*) FROM strength_categories WHERE school_id = ?");
                $stmt->execute([$school['id']]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Es existieren bereits Stärken für diese Schule.');
                }
                
                // Standard-Stärken erstellen
                $db->beginTransaction();
                
                try {
                    // Kategorien und Items definieren
                    $defaultCategories = [
                        [
                            'name' => 'Kognitive und fachliche Kompetenzen',
                            'icon' => '🧠',
                            'description' => 'Denkfähigkeiten und Fachwissen',
                            'items' => [
                                ['Informationsbeschaffung', 'z. B. Internetrecherche, Expertengespräch'],
                                ['Analytisches Denken und Problemlösung', null],
                                ['Kreative Ideenfindung', null],
                                ['Strukturierte Planung', null],
                                ['Fachwissen angewendet', 'z. B. Biologie, Ethik etc.']
                            ]
                        ],
                        [
                            'name' => 'Soziale Kompetenzen',
                            'icon' => '🤝',
                            'description' => 'Zusammenarbeit und Kommunikation',
                            'items' => [
                                ['Kommunikationsfähigkeit', 'klar, sachlich, zielgruppengerecht'],
                                ['Kooperationsbereitschaft im Team', null],
                                ['Empathie und Rücksichtnahme', null],
                                ['Konfliktfähigkeit / Streit schlichten', null],
                                ['Verantwortungsbewusstes Handeln', null],
                                ['Engagement für Gruppe und Projekt', null]
                            ]
                        ],
                        [
                            'name' => 'Personale Kompetenzen',
                            'icon' => '💬',
                            'description' => 'Persönliche Entwicklung und Selbstmanagement',
                            'items' => [
                                ['Selbstreflexion', 'Stärken/Schwächen erkannt und erklärt'],
                                ['Eigenständigkeit und Selbstorganisation', null],
                                ['Ausdauer und Durchhaltevermögen', null],
                                ['Zuverlässigkeit', null],
                                ['Mut, sich einzubringen', null],
                                ['Bereitschaft zur Weiterentwicklung', null]
                            ]
                        ],
                        [
                            'name' => 'Gestalterische und kreative Kompetenzen',
                            'icon' => '🎨',
                            'description' => 'Kreativität und Gestaltung',
                            'items' => [
                                ['Visuelle Gestaltung', 'Plakat, Bühne, Film etc.'],
                                ['Medieneinsatz', 'z. B. Social Media, Podcast, Video'],
                                ['Technisches Geschick', 'Modellbau, Werkstück'],
                                ['Künstlerischer Ausdruck', 'Theater, Musik, Zeichnung']
                            ]
                        ],
                        [
                            'name' => 'Präsentationskompetenz',
                            'icon' => '🗣️',
                            'description' => 'Darstellung und Vermittlung',
                            'items' => [
                                ['Sichere und überzeugende Darstellung des Produkts', null],
                                ['Anpassung an Zielgruppe', 'z. B. Mitschüler, Eltern, Öffentlichkeit'],
                                ['Medieneinsatz bei Präsentation', null]
                            ]
                        ],
                        [
                            'name' => 'Reflexionskompetenz',
                            'icon' => '🔁',
                            'description' => 'Selbsteinschätzung und Lernreflexion',
                            'items' => [
                                ['Prozess realistisch eingeschätzt', null],
                                ['Erfolge und Probleme begründet benannt', null],
                                ['Lernzuwachs reflektiert', null],
                                ['Verbesserungsvorschläge entwickelt', null]
                            ]
                        ]
                    ];
                    
                    // Kategorien erstellen
                    $catStmt = $db->prepare("
                        INSERT INTO strength_categories (school_id, name, icon, description, display_order) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    
                    $itemStmt = $db->prepare("
                        INSERT INTO strength_items (category_id, name, description, display_order) 
                        VALUES (?, ?, ?, ?)
                    ");
                    
                    foreach ($defaultCategories as $catIndex => $category) {
                        $catStmt->execute([
                            $school['id'], 
                            $category['name'], 
                            $category['icon'], 
                            $category['description'], 
                            $catIndex + 1
                        ]);
                        $categoryId = $db->lastInsertId();
                        
                        // Items für diese Kategorie erstellen
                        foreach ($category['items'] as $itemIndex => $item) {
                            $itemStmt->execute([
                                $categoryId,
                                $item[0],
                                $item[1],
                                $itemIndex + 1
                            ]);
                        }
                    }
                    
                    $db->commit();
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
                
                exit;
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Kategorien mit Items laden
$stmt = $db->prepare("
    SELECT sc.*, 
           (SELECT COUNT(*) FROM strength_items si WHERE si.category_id = sc.id) as item_count,
           (SELECT COUNT(*) FROM strength_items si WHERE si.category_id = sc.id AND si.is_active = 1) as active_item_count
    FROM strength_categories sc
    WHERE sc.school_id = ?
    ORDER BY sc.display_order, sc.id
");
$stmt->execute([$school['id']]);
$categories = $stmt->fetchAll();

// Items für jede Kategorie laden
$categoryItems = [];
foreach ($categories as $category) {
    $stmt = $db->prepare("
        SELECT * FROM strength_items 
        WHERE category_id = ? 
        ORDER BY display_order, id
    ");
    $stmt->execute([$category['id']]);
    $categoryItems[$category['id']] = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stärkenverwaltung - Schulverwaltung</title>
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
            background: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
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
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .stats {
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        .stat-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1rem;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 0.5rem;
            border: 1px solid rgba(100, 116, 139, 0.2);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #3b82f6;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(100, 116, 139, 0.2);
            border-radius: 1rem;
        }

        .empty-state .icon {
            font-size: 5rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h2 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: #f59e0b;
        }

        .empty-state p {
            opacity: 0.8;
            margin-bottom: 2rem;
        }

        .categories-grid {
            display: grid;
            gap: 1.5rem;
        }

        .category-card {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(100, 116, 139, 0.2);
            border-radius: 1rem;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .category-card.inactive {
            opacity: 0.6;
        }

        .category-card.dragging {
            opacity: 0.5;
            transform: scale(0.95);
        }

        .category-header {
            background: rgba(59, 130, 246, 0.1);
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: move;
        }

        .category-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .category-icon {
            font-size: 2rem;
        }

        .category-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #3b82f6;
        }

        .category-description {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-top: 0.25rem;
        }

        .category-actions {
            display: flex;
            gap: 0.5rem;
        }

        .category-body {
            padding: 1.5rem;
        }

        .items-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .item-card {
            background: rgba(100, 116, 139, 0.1);
            border: 1px solid rgba(100, 116, 139, 0.2);
            border-radius: 0.5rem;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }

        .item-card.inactive {
            opacity: 0.5;
        }

        .item-card:hover {
            background: rgba(100, 116, 139, 0.2);
        }

        .item-info {
            flex: 1;
        }

        .item-name {
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .item-description {
            font-size: 0.85rem;
            opacity: 0.7;
        }

        .item-actions {
            display: flex;
            gap: 0.5rem;
        }

        .add-item-btn {
            width: 100%;
            margin-top: 1rem;
            background: rgba(59, 130, 246, 0.1);
            border: 1px dashed rgba(59, 130, 246, 0.3);
            color: #3b82f6;
        }

        .add-item-btn:hover {
            background: rgba(59, 130, 246, 0.2);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            backdrop-filter: blur(4px);
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: #1e293b;
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 1rem;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            margin-bottom: 1.5rem;
        }

        .modal-header h2 {
            color: #3b82f6;
            font-size: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #cbd5e1;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            color: #e2e8f0;
            font-size: 1rem;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-control.emoji {
            width: 80px;
            text-align: center;
            font-size: 2rem;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            animation: slideIn 0.3s ease-out;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #10b981;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
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

        .icon-btn {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.25rem;
            transition: all 0.3s ease;
        }

        .icon-btn:hover {
            background: rgba(100, 116, 139, 0.2);
        }

        .drag-handle {
            cursor: move;
            opacity: 0.5;
            margin-right: 0.5rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .actions-bar {
                flex-direction: column;
            }
            
            .stats {
                width: 100%;
                justify-content: space-around;
            }
            
            .category-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .item-card {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .item-actions {
                width: 100%;
                justify-content: flex-end;
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
        <div id="alerts"></div>

        <div class="actions-bar">
            <div class="stats">
                <div class="stat-item">
                    <div class="stat-number"><?php echo count($categories); ?></div>
                    <div class="stat-label">Kategorien</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo array_sum(array_column($categories, 'item_count')); ?></div>
                    <div class="stat-label">Stärken</div>
                </div>
            </div>
            
            <div>
                <?php if (empty($categories)): ?>
                    <button class="btn btn-success" onclick="initDefaultStrengths()">
                        ✨ Standard-Stärken initialisieren
                    </button>
                <?php endif; ?>
                <button class="btn btn-primary" onclick="openCategoryModal()">
                    ➕ Neue Kategorie
                </button>
            </div>
        </div>

        <?php if (empty($categories)): ?>
            <div class="empty-state">
                <div class="icon">💪</div>
                <h2>Noch keine Stärken definiert</h2>
                <p>
                    Beginnen Sie mit den vordefinierten Standard-Stärken oder erstellen Sie eigene Kategorien.
                </p>
                <button class="btn btn-success" onclick="initDefaultStrengths()">
                    ✨ Standard-Stärken initialisieren
                </button>
            </div>
        <?php else: ?>
            <div class="categories-grid" id="categoriesGrid">
                <?php foreach ($categories as $category): ?>
                    <div class="category-card <?php echo !$category['is_active'] ? 'inactive' : ''; ?>" 
                         data-id="<?php echo $category['id']; ?>"
                         draggable="true">
                        <div class="category-header">
                            <div class="category-info">
                                <span class="drag-handle">⋮⋮</span>
                                <div class="category-icon"><?php echo htmlspecialchars($category['icon'] ?: '📌'); ?></div>
                                <div>
                                    <div class="category-title"><?php echo htmlspecialchars($category['name']); ?></div>
                                    <?php if ($category['description']): ?>
                                        <div class="category-description"><?php echo htmlspecialchars($category['description']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="category-actions">
                                <button class="icon-btn" onclick="editCategory(<?php echo $category['id']; ?>, <?php echo htmlspecialchars(json_encode($category)); ?>)" title="Bearbeiten">
                                    ✏️
                                </button>
                                <button class="icon-btn" onclick="toggleCategory(<?php echo $category['id']; ?>)" title="<?php echo $category['is_active'] ? 'Deaktivieren' : 'Aktivieren'; ?>">
                                    <?php echo $category['is_active'] ? '👁️' : '👁️‍🗨️'; ?>
                                </button>
                                <button class="icon-btn" onclick="deleteCategory(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['name']); ?>')" title="Löschen">
                                    🗑️
                                </button>
                            </div>
                        </div>
                        
                        <div class="category-body">
                            <div class="items-list">
                                <?php if (isset($categoryItems[$category['id']])): ?>
                                    <?php foreach ($categoryItems[$category['id']] as $item): ?>
                                        <div class="item-card <?php echo !$item['is_active'] ? 'inactive' : ''; ?>">
                                            <div class="item-info">
                                                <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                <?php if ($item['description']): ?>
                                                    <div class="item-description"><?php echo htmlspecialchars($item['description']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="item-actions">
                                                <button class="icon-btn" onclick="editItem(<?php echo $item['id']; ?>, <?php echo htmlspecialchars(json_encode($item)); ?>)" title="Bearbeiten">
                                                    ✏️
                                                </button>
                                                <button class="icon-btn" onclick="toggleItem(<?php echo $item['id']; ?>)" title="<?php echo $item['is_active'] ? 'Deaktivieren' : 'Aktivieren'; ?>">
                                                    <?php echo $item['is_active'] ? '✅' : '❌'; ?>
                                                </button>
                                                <button class="icon-btn" onclick="deleteItem(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>')" title="Löschen">
                                                    🗑️
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($category['item_count'] < 6): ?>
                                <button class="btn add-item-btn" onclick="openItemModal(<?php echo $category['id']; ?>)">
                                    ➕ Unterpunkt hinzufügen
                                </button>
                            <?php else: ?>
                                <div style="text-align: center; margin-top: 1rem; opacity: 0.6; font-size: 0.9rem;">
                                    Maximum von 6 Unterpunkten erreicht
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal für Kategorie -->
    <div class="modal" id="categoryModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="categoryModalTitle">Neue Kategorie</h2>
            </div>
            <form id="categoryForm">
                <input type="hidden" id="categoryId" value="">
                
                <div class="form-group">
                    <label for="categoryIcon">Icon (Emoji)</label>
                    <input type="text" id="categoryIcon" class="form-control emoji" maxlength="2" placeholder="💪">
                </div>
                
                <div class="form-group">
                    <label for="categoryName">Name *</label>
                    <input type="text" id="categoryName" class="form-control" required maxlength="100">
                </div>
                
                <div class="form-group">
                    <label for="categoryDescription">Beschreibung</label>
                    <textarea id="categoryDescription" class="form-control" maxlength="500"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeCategoryModal()">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal für Unterpunkt -->
    <div class="modal" id="itemModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="itemModalTitle">Neuer Unterpunkt</h2>
            </div>
            <form id="itemForm">
                <input type="hidden" id="itemId" value="">
                <input type="hidden" id="itemCategoryId" value="">
                
                <div class="form-group">
                    <label for="itemName">Name *</label>
                    <input type="text" id="itemName" class="form-control" required maxlength="100">
                </div>
                
                <div class="form-group">
                    <label for="itemDescription">Beschreibung</label>
                    <textarea id="itemDescription" class="form-control" maxlength="500" placeholder="z.B. Internetrecherche, Expertengespräch"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeItemModal()">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // CSRF-Token für AJAX-Requests
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        
        // Kategorie Modal
        function openCategoryModal(id = null, data = null) {
            const modal = document.getElementById('categoryModal');
            const form = document.getElementById('categoryForm');
            const title = document.getElementById('categoryModalTitle');
            
            form.reset();
            
            if (data) {
                title.textContent = 'Kategorie bearbeiten';
                document.getElementById('categoryId').value = data.id;
                document.getElementById('categoryIcon').value = data.icon || '';
                document.getElementById('categoryName').value = data.name;
                document.getElementById('categoryDescription').value = data.description || '';
            } else {
                title.textContent = 'Neue Kategorie';
                document.getElementById('categoryId').value = '';
            }
            
            modal.classList.add('active');
        }

        function closeCategoryModal() {
            document.getElementById('categoryModal').classList.remove('active');
        }

        function editCategory(id, data) {
            openCategoryModal(id, data);
        }

        // Item Modal
        function openItemModal(categoryId, id = null, data = null) {
            const modal = document.getElementById('itemModal');
            const form = document.getElementById('itemForm');
            const title = document.getElementById('itemModalTitle');
            
            form.reset();
            
            document.getElementById('itemCategoryId').value = categoryId;
            
            if (data) {
                title.textContent = 'Unterpunkt bearbeiten';
                document.getElementById('itemId').value = data.id;
                document.getElementById('itemName').value = data.name;
                document.getElementById('itemDescription').value = data.description || '';
            } else {
                title.textContent = 'Neuer Unterpunkt';
                document.getElementById('itemId').value = '';
            }
            
            modal.classList.add('active');
        }

        function closeItemModal() {
            document.getElementById('itemModal').classList.remove('active');
        }

        function editItem(id, data) {
            openItemModal(data.category_id, id, data);
        }

        // AJAX Funktionen
        async function ajaxRequest(action, data) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('csrf_token', csrfToken);
            for (const key in data) {
                formData.append(key, data[key]);
            }
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('success', 'Aktion erfolgreich ausgeführt');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert('error', result.error || 'Ein Fehler ist aufgetreten');
                }
                
                return result;
            } catch (error) {
                showAlert('error', 'Netzwerkfehler: ' + error.message);
                return { success: false };
            }
        }

        function showAlert(type, message) {
            const alertsDiv = document.getElementById('alerts');
            const alert = document.createElement('div');
            alert.className = `alert alert-${type}`;
            alert.textContent = message;
            alertsDiv.appendChild(alert);
            
            setTimeout(() => alert.remove(), 5000);
        }

        // Kategorie speichern
        document.getElementById('categoryForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const id = document.getElementById('categoryId').value;
            const data = {
                name: document.getElementById('categoryName').value,
                icon: document.getElementById('categoryIcon').value,
                description: document.getElementById('categoryDescription').value
            };
            
            if (id) {
                data.id = id;
                await ajaxRequest('update_category', data);
            } else {
                await ajaxRequest('create_category', data);
            }
            
            closeCategoryModal();
        });

        // Item speichern
        document.getElementById('itemForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const id = document.getElementById('itemId').value;
            const data = {
                name: document.getElementById('itemName').value,
                description: document.getElementById('itemDescription').value
            };
            
            if (id) {
                data.id = id;
                await ajaxRequest('update_item', data);
            } else {
                data.category_id = document.getElementById('itemCategoryId').value;
                await ajaxRequest('create_item', data);
            }
            
            closeItemModal();
        });

        // Lösch-Funktionen
        async function deleteCategory(id, name) {
            if (confirm(`Möchten Sie die Kategorie "${name}" wirklich löschen? Alle zugehörigen Unterpunkte werden ebenfalls gelöscht.`)) {
                await ajaxRequest('delete_category', { id });
            }
        }

        async function deleteItem(id, name) {
            if (confirm(`Möchten Sie den Unterpunkt "${name}" wirklich löschen?`)) {
                await ajaxRequest('delete_item', { id });
            }
        }

        // Toggle-Funktionen
        async function toggleCategory(id) {
            await ajaxRequest('toggle_category', { id });
        }

        async function toggleItem(id) {
            await ajaxRequest('toggle_item', { id });
        }

        // Standard-Stärken initialisieren
        async function initDefaultStrengths() {
            if (confirm('Möchten Sie die Standard-Stärken initialisieren? Dies erstellt 6 vordefinierte Kategorien mit Unterpunkten.')) {
                await ajaxRequest('init_default_strengths', {});
            }
        }

        // Drag & Drop für Kategorien
        let draggedElement = null;

        document.querySelectorAll('.category-card').forEach(card => {
            card.addEventListener('dragstart', (e) => {
                draggedElement = e.currentTarget;
                e.currentTarget.classList.add('dragging');
            });

            card.addEventListener('dragend', (e) => {
                e.currentTarget.classList.remove('dragging');
            });

            card.addEventListener('dragover', (e) => {
                e.preventDefault();
                const afterElement = getDragAfterElement(document.getElementById('categoriesGrid'), e.clientY);
                if (afterElement == null) {
                    document.getElementById('categoriesGrid').appendChild(draggedElement);
                } else {
                    document.getElementById('categoriesGrid').insertBefore(draggedElement, afterElement);
                }
            });
        });

        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('.category-card:not(.dragging)')];

            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;

                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        // Speichern der neuen Reihenfolge
        document.getElementById('categoriesGrid').addEventListener('drop', async () => {
            const cards = document.querySelectorAll('.category-card');
            const order = Array.from(cards).map(card => card.dataset.id);
            await ajaxRequest('reorder_categories', { order: JSON.stringify(order) });
        });

        // Modal schließen bei Klick außerhalb
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });

        // ESC-Taste zum Schließen
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.classList.remove('active');
                });
            }
        });
    </script>
</body>
</html>