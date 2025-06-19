<?php
// Diese Datei wird von dashboard.php eingebunden
// Formularverarbeitung erfolgt bereits in dashboard.php

// Wichtige Variablen aus dashboard.php verfügbar machen
$school_id = $_SESSION['school_id'] ?? null;

// Flash-Messages verarbeiten
$flash_message = null;
$flash_type = null;
if (isset($_SESSION['flash_message'])) {
    $flash_message = $_SESSION['flash_message'];
    $flash_type = $_SESSION['flash_type'];
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

// Bearbeitungsmodus prüfen
$edit_template = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $db->prepare("
        SELECT rt.*
        FROM rating_templates rt
        WHERE rt.id = ? AND rt.teacher_id = ? AND rt.is_active = 1
    ");
    $stmt->execute([$edit_id, $teacher_id]);
    $edit_template = $stmt->fetch();
    
    if ($edit_template) {
        // Kategorien der Vorlage laden
        $stmt = $db->prepare("
            SELECT *
            FROM rating_template_categories
            WHERE template_id = ?
            ORDER BY display_order, id
        ");
        $stmt->execute([$edit_id]);
        $edit_template['categories'] = $stmt->fetchAll();
    }
}

// Standard-Vorlage erstellen falls nicht vorhanden
$stmt = $db->prepare("SELECT COUNT(*) FROM rating_templates WHERE teacher_id = ? AND is_standard = 1");
$stmt->execute([$teacher_id]);
$has_standard = $stmt->fetchColumn() > 0;

if (!$has_standard) {
    $db->beginTransaction();
    try {
        // Standard-Vorlage erstellen
        $stmt = $db->prepare("
            INSERT INTO rating_templates (teacher_id, name, is_standard, is_active, created_at) 
            VALUES (?, 'Standard Projekt', 1, 1, NOW())
        ");
        $stmt->execute([$teacher_id]);
        $template_id = $db->lastInsertId();
        
        // Standard-Kategorien hinzufügen
        $categories = [
            ['name' => 'Reflexion', 'weight' => 30, 'order' => 1],
            ['name' => 'Inhalt', 'weight' => 40, 'order' => 2],
            ['name' => 'Präsentation', 'weight' => 30, 'order' => 3]
        ];
        
        $stmt = $db->prepare("
            INSERT INTO rating_template_categories (template_id, name, weight, display_order) 
            VALUES (?, ?, ?, ?)
        ");
        
        foreach ($categories as $cat) {
            $stmt->execute([$template_id, $cat['name'], $cat['weight'], $cat['order']]);
        }
        
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
    }
}

// Vorlagen des Lehrers abrufen
$stmt = $db->prepare("
    SELECT rt.*, 
           (SELECT COUNT(*) FROM rating_template_categories rtc WHERE rtc.template_id = rt.id) as category_count,
           (SELECT SUM(weight) FROM rating_template_categories rtc WHERE rtc.template_id = rt.id) as total_weight
    FROM rating_templates rt
    WHERE rt.teacher_id = ? AND rt.is_active = 1
    ORDER BY rt.is_standard DESC, rt.created_at DESC
");
$stmt->execute([$teacher_id]);
$templates = $stmt->fetchAll();

// Anzahl der benutzerdefinierten Vorlagen zählen
$custom_template_count = 0;
foreach ($templates as $template) {
    if (!$template['is_standard']) {
        $custom_template_count++;
    }
}

// Maximale Anzahl von Vorlagen
$max_templates = 10;
?>

<style>
/* Spezifische Styles für Vorlagen-Modul */
.templates-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 20px;
    background: rgba(255, 255, 255, 0.9);
    border-radius: 15px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.templates-title {
    font-size: 28px;
    color: #002b45;
    font-weight: 700;
}

.template-count {
    font-size: 14px;
    color: #666;
    margin-top: 5px;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 999px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-primary {
    background: #ffffff;
    color: #001133;
    border: 2px solid #ff9900;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.btn-primary:hover {
    background: #ff9900;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(255, 153, 0, 0.3);
}

.btn-success {
    background: #22c55e;
    color: white;
}

.btn-success:hover {
    background: #16a34a;
    transform: translateY(-1px);
}

.btn-secondary {
    background: rgba(255, 255, 255, 0.8);
    color: #002b45;
    border: 1px solid rgba(0, 43, 69, 0.3);
}

.btn-secondary:hover {
    background: rgba(255, 255, 255, 0.95);
    transform: translateY(-1px);
}

.btn-danger {
    background: rgba(231, 76, 60, 0.9);
    color: white;
    font-size: 12px;
    padding: 8px 16px;
}

.btn-danger:hover {
    background: #c0392b;
    transform: translateY(-1px);
}

.btn-sm {
    padding: 8px 16px;
    font-size: 12px;
}

.create-template-section {
    background: rgba(255, 255, 255, 0.95);
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
}

.create-template-form {
    display: flex;
    gap: 15px;
    align-items: center;
}

.create-template-form input {
    flex: 1;
    padding: 12px 20px;
    background: rgba(255, 255, 255, 0.9);
    border: 2px solid rgba(0, 43, 69, 0.2);
    border-radius: 999px;
    color: #002b45;
    font-size: 14px;
    transition: border-color 0.3s;
}

.create-template-form input:focus {
    outline: none;
    border-color: #ff9900;
    box-shadow: 0 0 0 3px rgba(255, 153, 0, 0.1);
}

.templates-grid {
    display: grid;
    gap: 25px;
}

.template-card {
    background: rgba(255, 255, 255, 0.95);
    border: 2px solid transparent;
    border-radius: 15px;
    padding: 25px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.template-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
    border-color: #ff9900;
}

.template-card.standard {
    background: linear-gradient(135deg, rgba(255, 153, 0, 0.05) 0%, rgba(255, 255, 255, 0.95) 100%);
}

.template-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
}

.template-info h3 {
    font-size: 22px;
    color: #002b45;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.standard-badge {
    background: #ff9900;
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.template-meta {
    font-size: 13px;
    color: #666;
    line-height: 1.6;
}

.template-actions {
    display: flex;
    gap: 10px;
}

.categories-list {
    background: rgba(0, 43, 69, 0.03);
    padding: 20px;
    border-radius: 12px;
    margin-top: 20px;
}

.category-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid rgba(0, 43, 69, 0.1);
}

.category-item:last-child {
    border-bottom: none;
}

.category-name {
    font-weight: 600;
    color: #002b45;
}

.category-weight {
    background: #002b45;
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
}

.weight-total {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 2px solid rgba(0, 43, 69, 0.2);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 700;
    font-size: 16px;
    color: #002b45;
}

.weight-total.complete {
    color: #22c55e;
}

.weight-total.incomplete {
    color: #dc2626;
}

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
    background: rgba(255, 255, 255, 0.98);
    border: 2px solid #ff9900;
    border-radius: 20px;
    padding: 30px;
    max-width: 700px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.modal-title {
    font-size: 24px;
    color: #002b45;
    font-weight: 700;
}

.close-modal {
    background: none;
    border: none;
    color: #666;
    font-size: 28px;
    cursor: pointer;
    padding: 5px;
    border-radius: 50%;
    transition: all 0.2s;
}

.close-modal:hover {
    color: #e74c3c;
    background: rgba(231, 76, 60, 0.1);
}

.form-section {
    background: rgba(0, 43, 69, 0.03);
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
}

.form-section-title {
    font-size: 18px;
    font-weight: 600;
    color: #002b45;
    margin-bottom: 15px;
}

.fixed-category {
    background: rgba(255, 153, 0, 0.05);
    padding: 15px;
    border-radius: 10px;
    border: 1px solid rgba(255, 153, 0, 0.2);
    margin-bottom: 20px;
}

.fixed-category-info {
    font-size: 14px;
    color: #666;
    margin-bottom: 10px;
}

.add-category-form {
    display: flex;
    gap: 15px;
    align-items: flex-end;
    margin-bottom: 20px;
}

.form-group {
    flex: 1;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #002b45;
}

.form-control {
    width: 100%;
    padding: 12px;
    background: rgba(255, 255, 255, 0.9);
    border: 2px solid rgba(0, 43, 69, 0.2);
    border-radius: 10px;
    color: #002b45;
    font-size: 14px;
    transition: border-color 0.3s;
}

.form-control:focus {
    outline: none;
    border-color: #ff9900;
    box-shadow: 0 0 0 3px rgba(255, 153, 0, 0.1);
}

.categories-edit-list {
    display: grid;
    gap: 12px;
}

.category-edit-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: rgba(255, 255, 255, 0.8);
    border-radius: 10px;
    border: 1px solid rgba(0, 43, 69, 0.1);
}

.category-edit-name {
    flex: 1;
    font-weight: 600;
    color: #002b45;
}

.category-edit-weight {
    background: #002b45;
    color: white;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    min-width: 50px;
    text-align: center;
}

.weight-status {
    text-align: center;
    margin: 20px 0;
    font-size: 16px;
    font-weight: 600;
}

.weight-status.complete {
    color: #22c55e;
}

.weight-status.incomplete {
    color: #dc2626;
}

.modal-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    margin-top: 25px;
}

.empty-state {
    text-align: center;
    padding: 60px 40px;
    color: #666;
    background: rgba(255, 255, 255, 0.9);
    border-radius: 15px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.empty-state-icon {
    font-size: 72px;
    margin-bottom: 20px;
    opacity: 0.5;
}

.empty-state h3 {
    color: #002b45;
    margin: 20px 0;
}

.flash-message {
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-weight: 600;
    animation: slideIn 0.3s ease-out;
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

.flash-success {
    background: rgba(34, 197, 94, 0.1);
    border: 2px solid rgba(34, 197, 94, 0.3);
    color: #15803d;
}

.flash-error {
    background: rgba(231, 76, 60, 0.1);
    border: 2px solid rgba(231, 76, 60, 0.3);
    color: #dc2626;
}

.info-box {
    background: rgba(59, 130, 246, 0.05);
    border: 1px solid rgba(59, 130, 246, 0.2);
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-size: 14px;
    color: #1e3a8a;
}

.help-text {
    font-size: 12px;
    color: #666;
    margin-top: 5px;
    font-style: italic;
}

@media (max-width: 768px) {
    .templates-header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }

    .create-template-form {
        flex-direction: column;
    }

    .add-category-form {
        flex-direction: column;
    }

    .modal-content {
        padding: 20px;
        margin: 20px;
    }

    .template-header {
        flex-direction: column;
        gap: 15px;
    }
}
</style>

<!-- Flash Messages -->
<div id="flash-messages">
    <?php if ($flash_message): ?>
        <div class="flash-message flash-<?= htmlspecialchars($flash_type) ?>">
            <?= htmlspecialchars($flash_message) ?>
        </div>
    <?php endif; ?>
</div>

<div class="templates-header">
    <div>
        <div class="templates-title">📋 Bewertungsvorlagen</div>
        <div class="template-count">
            <?= $custom_template_count ?> von <?= $max_templates ?> benutzerdefinierten Vorlagen
        </div>
    </div>
</div>

<!-- Neue Vorlage erstellen -->
<?php if ($custom_template_count < $max_templates): ?>
    <div class="create-template-section">
        <h3 style="margin-bottom: 15px; color: #002b45;">Neue Vorlage erstellen</h3>
        <form method="POST" class="create-template-form">
            <input type="hidden" name="form_action" value="create_template">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="text" name="template_name" placeholder="Name der Vorlage" required maxlength="100">
            <button type="submit" class="btn btn-primary">Erstellen</button>
        </form>
    </div>
<?php endif; ?>

<div class="templates-grid">
    <?php if (empty($templates)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">📋</div>
            <h3>Keine Vorlagen vorhanden</h3>
            <p>Erstellen Sie Ihre erste Bewertungsvorlage.</p>
        </div>
    <?php else: ?>
        <?php foreach ($templates as $template): ?>
            <div class="template-card <?= $template['is_standard'] ? 'standard' : '' ?>">
                <div class="template-header">
                    <div class="template-info">
                        <h3>
                            <?= htmlspecialchars($template['name']) ?>
                            <?php if ($template['is_standard']): ?>
                                <span class="standard-badge">Standard</span>
                            <?php endif; ?>
                        </h3>
                        <div class="template-meta">
                            Kategorien: <?= $template['category_count'] ?> | 
                            Gewichtung: <?= $template['total_weight'] ?>%
                            <?php if (!$template['is_standard']): ?>
                                <br>Erstellt am: <?= date('d.m.Y', strtotime($template['created_at'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="template-actions">
                        <?php if (!$template['is_standard']): ?>
                            <a href="?page=vorlagen&edit=<?= $template['id'] ?>" class="btn btn-secondary btn-sm">
                                ✏️ Bearbeiten
                            </a>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Sind Sie sicher, dass Sie diese Vorlage löschen möchten?');">
                                <input type="hidden" name="form_action" value="delete_template">
                                <input type="hidden" name="template_id" value="<?= $template['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <button type="submit" class="btn btn-danger">
                                    🗑️ Löschen
                                </button>
                            </form>
                        <?php else: ?>
                            <span style="color: #666; font-size: 14px; font-style: italic;">
                                Standardvorlage kann nicht bearbeitet werden
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                // Kategorien laden
                $stmt = $db->prepare("
                    SELECT * FROM rating_template_categories 
                    WHERE template_id = ? 
                    ORDER BY display_order, id
                ");
                $stmt->execute([$template['id']]);
                $categories = $stmt->fetchAll();
                ?>

                <div class="categories-list">
                    <?php foreach ($categories as $category): ?>
                        <div class="category-item">
                            <span class="category-name"><?= htmlspecialchars($category['name']) ?></span>
                            <span class="category-weight"><?= $category['weight'] ?>%</span>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="weight-total <?= $template['total_weight'] == 100 ? 'complete' : 'incomplete' ?>">
                        <span>Gesamtgewichtung:</span>
                        <span><?= $template['total_weight'] ?>%</span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal für Vorlage bearbeiten -->
<div class="modal <?= $edit_template ? 'show' : '' ?>" id="templateModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Vorlage: <?= $edit_template ? htmlspecialchars($edit_template['name']) : '' ?></h2>
            <button type="button" class="close-modal" onclick="closeModal()">&times;</button>
        </div>

        <?php if ($edit_template): ?>
            <div class="fixed-category">
                <div class="fixed-category-info">
                    <strong>Reflexion (30% - fest)</strong> ist automatisch in jeder Vorlage enthalten.
                </div>
            </div>

            <div class="form-section">
                <h3 class="form-section-title">Weitere Kategorien hinzufügen (max. 70%)</h3>
                
                <form method="POST" class="add-category-form">
                    <input type="hidden" name="form_action" value="add_category">
                    <input type="hidden" name="template_id" value="<?= $edit_template['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Kategorie-Name</label>
                        <input type="text" name="category_name" class="form-control" placeholder="z.B. Inhaltliche Darstellung" required maxlength="100">
                    </div>
                    
                    <div class="form-group" style="max-width: 150px;">
                        <label class="form-label">Gewichtung %</label>
                        <input type="number" name="weight" class="form-control" min="1" max="70" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Hinzufügen</button>
                </form>

                <?php
                $used_weight = 0;
                foreach ($edit_template['categories'] as $cat) {
                    $used_weight += $cat['weight'];
                }
                ?>
                
                <div class="weight-status <?= $used_weight == 100 ? 'complete' : 'incomplete' ?>">
                    Verwendete Gewichtung: <?= $used_weight ?>% / 100%
                </div>

                <div class="categories-edit-list" style="margin-top: 20px;">
                    <?php foreach ($edit_template['categories'] as $category): ?>
                        <div class="category-edit-item">
                            <span class="category-edit-name"><?= htmlspecialchars($category['name']) ?></span>
                            <span class="category-edit-weight"><?= $category['weight'] ?>%</span>
                            <?php if ($category['name'] !== 'Reflexion'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="form_action" value="delete_category">
                                    <input type="hidden" name="category_id" value="<?= $category['id'] ?>">
                                    <input type="hidden" name="template_id" value="<?= $edit_template['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <button type="submit" class="btn btn-danger">Entfernen</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Abbrechen</button>
                <?php if ($used_weight == 100): ?>
                    <button type="button" class="btn btn-success" onclick="closeModal()">Vorlage speichern</button>
                <?php else: ?>
                    <button type="button" class="btn btn-success" disabled title="Die Gesamtgewichtung muss 100% betragen">
                        Vorlage speichern
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function closeModal() {
    <?php if ($edit_template): ?>
        window.location.href = '<?= $_SERVER['PHP_SELF'] ?>?page=vorlagen';
    <?php else: ?>
        document.getElementById('templateModal').classList.remove('show');
    <?php endif; ?>
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // Flash Messages automatisch ausblenden
    setTimeout(() => {
        document.querySelectorAll('.flash-message').forEach(msg => {
            msg.style.transition = 'opacity 0.3s';
            msg.style.opacity = '0';
            setTimeout(() => msg.remove(), 300);
        });
    }, 5000);
    
    // Modal Click-Outside Handler
    const modal = document.getElementById('templateModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    }
    
    // ESC Key Handler
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('templateModal');
            if (modal && modal.classList.contains('show')) {
                closeModal();
            }
        }
    });
});
</script>