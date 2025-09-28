<?php
// Activity tab include - expects $recentActivity to be available
?>
<div class="profile-section">
    <div class="section-header">
        <div class="section-icon">
            <i class="fas fa-history"></i>
        </div>
        <h3>Recent Activity</h3>
    </div>
    <div class="activity-list">
        <?php foreach ((array)$recentActivity as $activity): ?>
            <div class="activity-item">
                <div class="activity-icon <?php echo $activity['action_type']; ?>">
                    <i class="fas fa-<?php echo $activity['icon']; ?>"></i>
                </div>
                <div class="activity-details">
                    <h5><?php echo htmlspecialchars($activity['description']); ?></h5>
                    <div class="activity-time"><?php echo $activity['formatted_time']; ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($recentActivity)): ?>
            <div class="activity-item">
                <div class="activity-icon update">
                    <i class="fas fa-info-circle"></i>
                </div>
                <div class="activity-details">
                    <h5>No recent activity</h5>
                    <div class="activity-time">Activity will appear here as you use the system</div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
