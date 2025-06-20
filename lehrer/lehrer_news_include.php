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
    <!-- Dashboard Statistiken (IMMER OBEN) -->
    <div class="dashboard-stats">
        <h3>Dashboard Übersicht</h3>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-icon">👥</div>
                <div class="stat-content">
                    <div class="stat-number"><?= $students_to_rate ?></div>
                    <div class="stat-label">Schüler zu bewerten</div>
                </div>
            </div>
            <div class="stat-box">
                <div class="stat-icon">✅</div>
                <div class="stat-content">
                    <div class="stat-number"><?= $students_rated ?></div>
                    <div class="stat-label">Bereits bewertet</div>
                </div>
            </div>
            <div class="stat-box">
                <div class="stat-icon">📋</div>
                <div class="stat-content">
                    <div class="stat-number"><?= $group_count ?></div>
                    <div class="stat-label">Gruppen als Prüfer</div>
                </div>
            </div>
            <div class="stat-box">
                <div class="stat-icon">📊</div>
                <div class="stat-content">
                    <div class="stat-number"><?= $students_to_rate > 0 ? round(($students_rated / $students_to_rate) * 100) : 100 ?>%</div>
                    <div class="stat-label">Fortschritt</div>
                </div>
            </div>
        </div>
    </div>

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
                <div class="admin-news-item <?= $news['is_important'] ? 'news-important' : '' ?>" data-news-id="<?= $news['id'] ?>">
                    <div class="news-header">
                        <h4><?= htmlspecialchars($news['title']) ?></h4>
                        <button class="news-close-btn" onclick="markNewsAsRead(<?= $news['id'] ?>)">✕</button>
                    </div>
                    <div class="news-body">
                        <p><?= nl2br(htmlspecialchars($news['content'])) ?></p>
                        <div class="news-footer">
                            <span class="news-author">Von: <?= htmlspecialchars($news['created_by_name']) ?></span>
                            <span class="news-date"><?= date('d.m.Y - H:i', strtotime($news['created_at'])) ?> Uhr</span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <!-- Letzte Aktivitäten -->
    <div class="activities-section">
        <h3>Letzte Aktivitäten</h3>
        <?php if (empty($activities)): ?>
            <p class="no-activities">Keine aktuellen Aktivitäten vorhanden.</p>
        <?php else: ?>
            <?php foreach ($activities as $activity): ?>
                <div class="activity-item" data-activity-id="<?= $activity['activity_id'] ?>">
                    <div class="activity-icon">
                        <?= $activity['type'] === 'bewertung' ? '✅' : 
                           ($activity['type'] === 'zuweisung' ? '👤' : '📄');
                        ?>
                    </div>
                    <div class="activity-content">
                        <p class="activity-message"><?= htmlspecialchars($activity['message']) ?></p>
                        <?php if ($activity['extra_info']): ?>
                            <p class="activity-extra">
                                <?php if ($activity['type'] === 'bewertung'): ?>
                                    Note: <?= htmlspecialchars($activity['extra_info']) ?>
                                <?php elseif ($activity['type'] === 'zuweisung'): ?>
                                    Fach: <?= htmlspecialchars($activity['extra_info']) ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <p class="activity-date"><?= date('d.m.Y - H:i', strtotime($activity['date'])) ?> Uhr</p>
                    </div>
                    <button class="activity-close-btn" onclick="hideActivity('<?= htmlspecialchars($activity['activity_id']) ?>')">✕</button>
                </div>
            <?php endforeach; ?>
            <div class="activities-info-box">
                <strong>Info:</strong> Aktivitäten werden automatisch nach 7 Tagen ausgeblendet.
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
            // Versteckte Aktivitäten im SessionStorage speichern
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
/* Sunset Theme Styles - Dunklere Blau/Grau Töne */
.news-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

/* Dashboard Statistiken - IMMER OBEN */
.dashboard-stats {
    margin-bottom: 40px;
    background-color: #1a2332;
    padding: 25px;
    border-radius: 8px;
    border: 1px solid #2d3f55;
}

.dashboard-stats h3 {
    color: #f4a460;
    font-size: 22px;
    margin: 0 0 20px 0;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.stat-box {
    background-color: #243447;
    padding: 20px;
    border-radius: 6px;
    border: 1px solid #35495e;
    display: flex;
    align-items: center;
    gap: 15px;
    transition: transform 0.2s ease;
}

.stat-box:hover {
    transform: translateY(-2px);
    background-color: #2a3b50;
}

.stat-icon {
    font-size: 32px;
    opacity: 0.8;
}

.stat-content {
    flex: 1;
}

.stat-number {
    font-size: 28px;
    font-weight: bold;
    color: #f4a460;
}

.stat-label {
    font-size: 14px;
    color: #8fb1d9;
    margin-top: 4px;
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
    color: #f4a460;
    font-size: 22px;
    margin: 0;
}

.mark-all-read-btn {
    background-color: #3b7ea8;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
    transition: background-color 0.3s ease;
}

.mark-all-read-btn:hover {
    background-color: #4a95c9;
}

/* News Items - Dunkelblaue Kästen mit weißer Schrift */
.admin-news-item {
    background-color: #1e3a5f; /* Dunkelblau */
    border: 1px solid #2a4f7a;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    position: relative;
    animation: slideIn 0.3s ease-out;
}

.admin-news-item.news-important {
    background-color: #2d1f4f; /* Dunkles Violett-Blau für wichtige Nachrichten */
    border-color: #4a3578;
}

.admin-news-item .news-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 12px;
}

.admin-news-item h4 {
    margin: 0;
    color: #ffffff; /* Weiße Überschrift */
    font-size: 18px;
}

.news-close-btn {
    background: none;
    border: none;
    color: #ffffff;
    font-size: 20px;
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: background-color 0.2s ease;
}

.news-close-btn:hover {
    background-color: rgba(255, 255, 255, 0.1);
}

.news-body {
    color: #ffffff; /* Weißer Text */
}

.news-body p {
    margin: 0 0 15px 0;
    line-height: 1.6;
}

.news-footer {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: #b8d4e8; /* Hellblaue Meta-Informationen */
}

/* Aktivitäten Section */
.activities-section {
    background-color: #1a2332;
    padding: 25px;
    border-radius: 8px;
    border: 1px solid #2d3f55;
}

.activities-section h3 {
    color: #f4a460;
    font-size: 22px;
    margin: 0 0 20px 0;
}

.no-activities {
    color: #8fb1d9;
    text-align: center;
    padding: 20px;
}

.activity-item {
    background-color: #243447;
    border: 1px solid #35495e;
    border-radius: 6px;
    padding: 15px;
    margin-bottom: 12px;
    display: flex;
    align-items: start;
    gap: 12px;
    position: relative;
}

.activity-icon {
    font-size: 24px;
    opacity: 0.8;
}

.activity-content {
    flex: 1;
}

.activity-message {
    color: #e0e0e0;
    margin: 0 0 5px 0;
}

.activity-extra {
    color: #8fb1d9;
    font-size: 14px;
    margin: 0 0 5px 0;
}

.activity-date {
    color: #6b8aad;
    font-size: 13px;
    margin: 0;
}

.activity-close-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    background: none;
    border: none;
    color: #8fb1d9;
    font-size: 16px;
    cursor: pointer;
    padding: 0;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: background-color 0.2s ease;
}

.activity-close-btn:hover {
    background-color: rgba(255, 255, 255, 0.1);
}

.activities-info-box {
    background-color: #1e3a5f;
    color: #ffffff;
    padding: 12px;
    border-radius: 5px;
    margin-top: 15px;
    font-size: 13px;
}

/* Animationen */
@keyframes fadeOut {
    from { opacity: 1; transform: translateX(0); }
    to { opacity: 0; transform: translateX(20px); }
}

@keyframes slideIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>