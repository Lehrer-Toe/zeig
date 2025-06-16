<?php
// Sicherheitsprüfung
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    exit('Keine Berechtigung');
}

// Letzte Aktivitäten abrufen
$stmt = $pdo->prepare("
    SELECT 
        'bewertung' as type,
        CONCAT('Bewertung gespeichert für ', s.first_name, ' ', s.last_name) as message,
        e.created_at as date,
        e.grade as extra_info
    FROM evaluations e
    JOIN students s ON e.student_id = s.id
    WHERE e.teacher_id = ?
    
    UNION ALL
    
    SELECT 
        'gruppe' as type,
        CONCAT('Gruppe \"', name, '\" erstellt') as message,
        created_at as date,
        NULL as extra_info
    FROM groups
    WHERE teacher_id = ?
    
    ORDER BY date DESC
    LIMIT 10
");
$stmt->execute([$teacher_id, $teacher_id]);
$activities = $stmt->fetchAll();
?>

<div class="news-container">
    <h2>Aktuelle News</h2>
    
    <div class="news-list">
        <?php if (empty($activities)): ?>
            <div class="empty-state">
                <p>Noch keine Aktivitäten vorhanden.</p>
                <p>Beginnen Sie mit dem Erstellen von Gruppen oder der Bewertung von Schülern.</p>
            </div>
        <?php else: ?>
            <?php foreach ($activities as $activity): ?>
                <div class="news-item">
                    <div class="news-badge <?= $activity['type'] === 'bewertung' ? 'badge-evaluation' : 'badge-group' ?>">
                        <?= $activity['type'] === 'bewertung' ? 'Für alle' : 'Gruppe' ?>
                    </div>
                    <div class="news-content">
                        <p class="news-message"><?= htmlspecialchars($activity['message']) ?></p>
                        <?php if ($activity['extra_info']): ?>
                            <p class="news-extra">Note: <?= htmlspecialchars($activity['extra_info']) ?></p>
                        <?php endif; ?>
                        <p class="news-date"><?= date('d.m.Y - H:i', strtotime($activity['date'])) ?> Uhr</p>
                    </div>
                    <div class="news-status">
                        <input type="checkbox" class="news-checkbox" checked disabled>
                        <span>Gelesen</span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.news-container {
    max-width: 1000px;
}

.news-container h2 {
    color: #fff;
    margin-bottom: 30px;
    font-size: 24px;
}

.news-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.news-item {
    background: rgba(91, 103, 202, 0.1);
    border: 1px solid rgba(91, 103, 202, 0.3);
    border-radius: 10px;
    padding: 20px;
    display: flex;
    align-items: flex-start;
    gap: 15px;
    transition: all 0.3s ease;
}

.news-item:hover {
    background: rgba(91, 103, 202, 0.15);
    transform: translateX(5px);
}

.news-badge {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    flex-shrink: 0;
}

.badge-evaluation {
    background: #5b67ca;
    color: white;
}

.badge-group {
    background: #2ecc71;
    color: white;
}

.news-content {
    flex: 1;
}

.news-message {
    color: #fff;
    font-size: 16px;
    margin-bottom: 5px;
}

.news-extra {
    color: #aaa;
    font-size: 14px;
    margin-bottom: 5px;
}

.news-date {
    color: #888;
    font-size: 13px;
}

.news-status {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #888;
    font-size: 14px;
}

.news-checkbox {
    width: 18px;
    height: 18px;
    cursor: not-allowed;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #888;
}

.empty-state p {
    margin-bottom: 10px;
    font-size: 16px;
}

@media (max-width: 768px) {
    .news-item {
        flex-direction: column;
        padding: 15px;
    }
    
    .news-badge {
        align-self: flex-start;
    }
}
</style>