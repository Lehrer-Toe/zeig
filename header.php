<div class="header">
    <div>
        <h1>🏫 Schuladmin Dashboard</h1>
        <div class="school-info">
            <?php echo escape($school['name']); ?> - <?php echo escape($school['location']); ?>
        </div>
    </div>
    <div class="user-info">
        <span>👋 <?php echo escape($user['name']); ?></span>
        <a href="../logout.php" class="btn btn-secondary btn-sm">Abmelden</a>
    </div>
</div>