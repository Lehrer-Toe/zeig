<?php
// Sicherheitsprüfung
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'lehrer') {
    exit('Keine Berechtigung');
}

// Teacher ID aus Session holen
$teacher_id = $_SESSION['user_id'];

// 1. Statistiken berechnen
// Schüler die bewertet werden müssen
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT gs.student_id) as students_to_rate
    FROM group_students gs
    WHERE gs.examiner_teacher_id = ?
");
$stmt->execute([$teacher_id]);
$students_to_rate = $stmt->fetchColumn();

// Bereits bewertete Schüler
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT r.student_id) as students_rated
    FROM ratings r
    WHERE r.teacher_id = ? AND r.is_complete = 1
");
$stmt->execute([$teacher_id]);
$students_rated = $stmt->fetchColumn();

// Anzahl Gruppen als Prüfer
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT gs.group_id) as group_count
    FROM group_students gs
    WHERE gs.examiner_teacher_id = ?
");
$stmt->execute([$teacher_id]);
$group_count = $stmt->fetchColumn();

// 2. Admin News abrufen (die noch nicht gelesen wurden)
$stmt = $pdo->prepare("
    SELECT 
        an.id,
        an.title,
        an.content,
        an.created_at,
        an.is_important,
        u.name as created_by_name
    FROM admin_news an
    JOIN users u ON an.created_by = u.id
    LEFT JOIN news_read_status nrs ON an.id = nrs.news_id AND nrs.teacher_id = ?
    WHERE nrs.id IS NULL 
        AND (an.expires_at IS NULL OR an.expires_at >= CURDATE())
    ORDER BY an.is_important DESC, an.created_at DESC
");
$stmt->execute([$teacher_id]);
$admin_news = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Letzte Aktivitäten abrufen (nur die letzten 7 Tage)
$stmt = $pdo->prepare("
    SELECT 
        'bewertung' as type,
        CONCAT('Bewertung für ', s.first_name, ' ', s.last_name, ' abgeschlossen') as message,
        r.created_at as date,
        CAST(r.final_grade AS CHAR) as extra_info,
        CONCAT('rating_', r.id) as activity_id
    FROM ratings r
    JOIN students s ON r.student_id = s.id
    WHERE r.teacher_id = ? 
        AND r.is_complete = 1
        AND r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    
    UNION ALL
    
    SELECT 
        'zuweisung' as type,
        CONCAT('Als Prüfer für ', s.first_name, ' ', s.last_name, ' in Gruppe \"', g.name, '\" zugewiesen') as message,
        gs.assigned_at as date,
        sub.short_name as extra_info,
        CONCAT('assignment_', gs.id) as activity_id
    FROM group_students gs
    JOIN students s ON gs.student_id = s.id
    JOIN groups g ON gs.group_id = g.id
    LEFT JOIN subjects sub ON gs.subject_id = sub.id
    WHERE gs.examiner_teacher_id = ?
        AND g.teacher_id != ?
        AND gs.assigned_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    
    ORDER BY date DESC
    LIMIT 10
");
$stmt->execute([$teacher_id, $teacher_id, $teacher_id]);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="news-container">
    <!-- Admin News (falls vorhanden) -->
    <?php if (!empty($admin_news)): ?>
        <div class="admin-news-section">
            <div class="admin-news-header">
                <h3>Wichtige Mitteilungen</h3>
                <button class="mark-all-read-btn" onclick="markAllNewsAsRead()">
                    ✓ Alle als gelesen markieren
                </button>
            </div>
            <?php foreach ($admin_news as $news): ?>
                <div class="admin-news-item <?= $news['is_important'] ? 'important' : '' ?>" data-news-id="<?= $news['id'] ?>">
                    <div class="news-header-row">
                        <h4><?= htmlspecialchars($news['title']) ?></h4>
                        <button class="close-btn" onclick="markNewsAsRead(<?= $news['id'] ?>)">✕</button>
                    </div>
                    <p class="news-content"><?= nl2br(htmlspecialchars($news['content'])) ?></p>
                    <div class="news-meta">
                        <span>Von <?= htmlspecialchars($news['created_by_name']) ?></span>
                        <span><?= date('d.m.Y - H:i', strtotime($news['created_at'])) ?> Uhr</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="news-header">
        <h2>Dashboard & Aktivitäten</h2>
        <div class="stats-row">
            <div class="stat-item">
                <span class="stat-number"><?= $students_to_rate ?></span>
                <span class="stat-label">Zu bewerten</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?= $students_rated ?></span>
                <span class="stat-label">Bewertet</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?= $group_count ?></span>
                <span class="stat-label">Gruppen als Prüfer</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?= round(($students_rated / max($students_to_rate, 1)) * 100) ?>%</span>
                <span class="stat-label">Fortschritt</span>
            </div>
        </div>
        
        <!-- Fortschrittsbalken -->
        <div class="progress-container">
            <div class="progress-bar" style="width: <?= round(($students_rated / max($students_to_rate, 1)) * 100) ?>%"></div>
        </div>
    </div>
    
    <div class="news-list">
        <h3>Letzte Aktivitäten</h3>
        <?php if (empty($activities)): ?>
            <div class="empty-state">
                <div class="empty-icon">📋</div>
                <p>Noch keine Aktivitäten vorhanden.</p>
                <p>Beginnen Sie mit dem Erstellen von Gruppen oder der Bewertung von Schülern.</p>
            </div>
        <?php else: ?>
            <?php foreach ($activities as $activity): ?>
                <div class="news-item" data-activity-id="<?= htmlspecialchars($activity['activity_id']) ?>">
                    <div class="news-badge badge-<?= $activity['type'] ?>">
                        <?php 
                        $badges = [
                            'bewertung' => '📊 Bewertung',
                            'zuweisung' => '📌 Zuweisung'
                        ];
                        echo $badges[$activity['type']] ?? '📄 Aktivität';
                        ?>
                    </div>
                    <div class="news-content">
                        <p class="news-message"><?= htmlspecialchars($activity['message']) ?></p>
                        <?php if ($activity['extra_info']): ?>
                            <p class="news-extra">
                                <?php if ($activity['type'] === 'bewertung'): ?>
                                    Note: <?= htmlspecialchars($activity['extra_info']) ?>
                                <?php elseif ($activity['type'] === 'zuweisung'): ?>
                                    Fach: <?= htmlspecialchars($activity['extra_info']) ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <p class="news-date"><?= date('d.m.Y - H:i', strtotime($activity['date'])) ?> Uhr</p>
                    </div>
                    <button class="activity-close-btn" onclick="hideActivity('<?= htmlspecialchars($activity['activity_id']) ?>')">✕</button>
                </div>
            <?php endforeach; ?>
            <div class="news-info-box">
                <strong>Info:</strong> Aktivitäten werden automatisch nach 7 Tagen ausgeblendet. Sie können einzelne Aktivitäten über das ✕ manuell ausblenden.
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function markNewsAsRead(newsId) {
    fetch('includes/mark_news_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ news_id: newsId })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // News ausblenden mit Animation
            const newsItem = document.querySelector(`[data-news-id="${newsId}"]`);
            newsItem.style.animation = 'fadeOut 0.3s ease-out';
            setTimeout(() => {
                newsItem.remove();
                checkIfNewsEmpty();
            }, 300);
        }
    })
    .catch(error => {
        console.error('Fehler beim Markieren als gelesen:', error);
        alert('Fehler: Konnte Nachricht nicht als gelesen markieren. Bitte prüfen Sie die Browser-Konsole.');
    });
}

function markAllNewsAsRead() {
    // Alle News-IDs sammeln
    const newsItems = document.querySelectorAll('.admin-news-item');
    const newsIds = Array.from(newsItems).map(item => parseInt(item.dataset.newsId));
    
    if (newsIds.length === 0) return;
    
    // Alle News als gelesen markieren
    fetch('includes/mark_all_news_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ news_ids: newsIds })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Alle News mit Animation ausblenden
            newsItems.forEach((item, index) => {
                setTimeout(() => {
                    item.style.animation = 'fadeOut 0.3s ease-out';
                    setTimeout(() => item.remove(), 300);
                }, index * 50); // Gestaffelte Animation
            });
            
            // Nach der letzten Animation die Section entfernen
            setTimeout(() => {
                checkIfNewsEmpty();
            }, newsItems.length * 50 + 300);
        }
    })
    .catch(error => {
        console.error('Fehler beim Markieren aller als gelesen:', error);
        alert('Fehler: Konnte Nachrichten nicht als gelesen markieren. Bitte prüfen Sie die Browser-Konsole.');
    });
}

function checkIfNewsEmpty() {
    const newsSection = document.querySelector('.admin-news-section');
    if (newsSection && newsSection.querySelectorAll('.admin-news-item').length === 0) {
        newsSection.style.animation = 'fadeOut 0.3s ease-out';
        setTimeout(() => newsSection.remove(), 300);
    }
}

// Aktivitäten ausblenden (nur lokal im Browser)
function hideActivity(activityId) {
    const activityItem = document.querySelector(`[data-activity-id="${activityId}"]`);
    if (activityItem) {
        activityItem.style.animation = 'fadeOut 0.3s ease-out';
        setTimeout(() => {
            activityItem.remove();
            // Versteckte Aktivitäten im LocalStorage speichern
            let hiddenActivities = JSON.parse(sessionStorage.getItem('hiddenActivities') || '[]');
            hiddenActivities.push(activityId);
            sessionStorage.setItem('hiddenActivities', JSON.stringify(hiddenActivities));
        }, 300);
    }
}

// Beim Laden der Seite versteckte Aktivitäten ausblenden
document.addEventListener('DOMContentLoaded', function() {
    const hiddenActivities = JSON.parse(sessionStorage.getItem('hiddenActivities') || '[]');
    hiddenActivities.forEach(activityId => {
        const activityItem = document.querySelector(`[data-activity-id="${activityId}"]`);
        if (activityItem) {
            activityItem.style.display = 'none';
        }
    });
});
</script>

<style>
/* Sunset Theme Styles - Blau/Grau Töne */
.news-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

/* Admin News Section */
.admin-news-section {
    margin-bottom: 40px;
}

.admin-news-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.admin-news-section h3 {
    color: #e0e0e0;
    font-size: 20px;
    margin: 0;
}

.mark-all-read-btn {
    background: linear-gradient(135deg, #063b52 0%, #002b45 100%);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.mark-all-read-btn:hover {
    background: linear-gradient(135deg, #002b45 0%, #063b52 100%);
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 43, 69, 0.3);
}

.admin-news-item {
    background: linear-gradient(135deg, rgba(6, 59, 82, 0.1) 0%, rgba(0, 43, 69, 0.1) 100%);
    border: 1px solid rgba(6, 59, 82, 0.3);
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 15px;
    position: relative;
    transition: all 0.3s ease;
}

.admin-news-item.important {
    background: linear-gradient(135deg, rgba(255, 153, 0, 0.15) 0%, rgba(255, 170, 51, 0.15) 100%);
    border: 2px solid rgba(255, 153, 0, 0.4);
    box-shadow: 0 0 20px rgba(255, 153, 0, 0.2);
}

.news-header-row {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 10px;
}

.admin-news-item h4 {
    color: #ffffff;
    margin: 0;
    font-size: 18px;
    font-weight: 600;
}

.close-btn {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #ffffff;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s ease;
}

.close-btn:hover {
    background: rgba(255, 107, 107, 0.8);
    border-color: rgba(255, 107, 107, 0.9);
    color: #ffffff;
    transform: scale(1.1);
}

.news-content {
    color: #e0e0e0;
    margin-bottom: 10px;
    line-height: 1.7;
    font-size: 15px;
}

.news-meta {
    display: flex;
    justify-content: space-between;
    color: #999999;
    font-size: 13px;
}

/* Dashboard Header */
.news-header {
    margin-bottom: 40px;
}

.news-header h2 {
    background: linear-gradient(135deg, #063b52 0%, #002b45 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 25px;
}

.stats-row {
    display: flex;
    gap: 20px;
    background: linear-gradient(135deg, rgba(6, 59, 82, 0.1) 0%, rgba(0, 43, 69, 0.1) 100%);
    border: 1px solid rgba(6, 59, 82, 0.2);
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 20px;
}

.stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 1;
}

.stat-number {
    font-size: 36px;
    font-weight: 700;
    background: linear-gradient(135deg, #ff9900 0%, #ffaa33 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.stat-label {
    color: #b3b3b3;
    font-size: 14px;
    margin-top: 5px;
}

/* Progress Bar */
.progress-container {
    background: rgba(153, 153, 153, 0.2);
    border-radius: 10px;
    height: 10px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #ff9900 0%, #ffaa33 100%);
    transition: width 0.5s ease;
}

/* News List */
.news-list {
    margin-top: 40px;
}

.news-list h3 {
    color: #e0e0e0;
    font-size: 20px;
    margin-bottom: 20px;
}

.news-item {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0.05) 100%);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 15px;
    padding: 25px;
    display: flex;
    align-items: flex-start;
    gap: 20px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.news-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #063b52 0%, #002b45 100%);
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.3s ease;
}

.news-item:hover {
    background: linear-gradient(135deg, rgba(6, 59, 82, 0.2) 0%, rgba(0, 43, 69, 0.15) 100%);
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(0, 43, 69, 0.2);
}

.news-item:hover::before {
    transform: scaleX(1);
}

.activity-close-btn {
    position: absolute;
    top: 15px;
    right: 15px;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #999999;
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    padding: 0;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s ease;
    opacity: 0.7;
}

.activity-close-btn:hover {
    background: rgba(255, 107, 107, 0.8);
    border-color: rgba(255, 107, 107, 0.9);
    color: #ffffff;
    opacity: 1;
    transform: scale(1.1);
}

.news-badge {
    padding: 8px 16px;
    border-radius: 25px;
    font-size: 14px;
    font-weight: 600;
    white-space: nowrap;
    flex-shrink: 0;
}

.badge-bewertung {
    background: linear-gradient(135deg, #063b52 0%, #002b45 100%);
    color: white;
    box-shadow: 0 4px 15px rgba(6, 59, 82, 0.3);
}

.badge-zuweisung {
    background: linear-gradient(135deg, #ff9900 0%, #ff7722 100%);
    color: white;
    box-shadow: 0 4px 15px rgba(255, 153, 0, 0.3);
}

.news-content {
    flex: 1;
    padding-right: 40px; /* Platz für X-Button */
}

.news-message {
    color: #ffffff;
    font-size: 16px;
    margin-bottom: 8px;
    font-weight: 500;
    line-height: 1.5;
}

.news-extra {
    color: #b3b3b3;
    font-size: 14px;
    margin-bottom: 8px;
    font-weight: 600;
}

.news-date {
    color: #999999;
    font-size: 13px;
}

.news-info-box {
    background: rgba(255, 153, 0, 0.1);
    border: 1px solid rgba(255, 153, 0, 0.3);
    border-radius: 10px;
    padding: 15px;
    margin-top: 20px;
    color: #ffd6cc;
    font-size: 14px;
    line-height: 1.6;
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(255, 153, 0, 0.7);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(255, 153, 0, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(255, 153, 0, 0);
    }
}

@keyframes fadeOut {
    from {
        opacity: 1;
        transform: translateX(0);
    }
    to {
        opacity: 0;
        transform: translateX(-20px);
    }
}

.empty-state {
    text-align: center;
    padding: 80px 20px;
    background: linear-gradient(135deg, rgba(153, 153, 153, 0.05) 0%, rgba(119, 119, 119, 0.05) 100%);
    border: 2px dashed rgba(153, 153, 153, 0.3);
    border-radius: 15px;
}

.empty-icon {
    font-size: 48px;
    margin-bottom: 20px;
}

.empty-state p {
    color: #b3b3b3;
    font-size: 16px;
    margin-bottom: 10px;
}

.empty-state p:first-of-type {
    color: #e0e0e0;
    font-size: 18px;
    font-weight: 500;
}

@media (max-width: 768px) {
    .stats-row {
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .stat-item {
        flex: 1 1 45%;
    }
    
    .news-item {
        flex-direction: column;
        padding: 20px;
    }
    
    .news-badge {
        align-self: flex-start;
    }
    
    .news-header h2 {
        font-size: 24px;
    }
    
    .stat-number {
        font-size: 28px;
    }
    
    .admin-news-header {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
    }
    
    .mark-all-read-btn {
        width: 100%;
    }
}
</style>