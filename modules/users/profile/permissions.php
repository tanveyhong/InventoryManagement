<?php
// Permissions tab include - expects $userPermissions and $roleManager to be available
?>
<div class="profile-section">
    <div class="section-header">
        <div class="section-icon">
            <i class="fas fa-shield-alt"></i>
        </div>
        <h3>Role & Permissions</h3>
    </div>
    <div class="permissions-grid">
        <?php foreach ((array)$userPermissions as $permission): ?>
            <div class="permission-card <?php echo $permission['granted'] ? 'granted' : ''; ?>">
                <h4>
                    <i class="fas fa-<?php echo $permission['icon']; ?>"></i>
                    <?php echo htmlspecialchars($permission['name']); ?>
                </h4>
                <p><?php echo htmlspecialchars($permission['description']); ?></p>
                <span class="permission-status <?php echo $permission['granted'] ? 'status-granted' : 'status-denied'; ?>">
                    <?php echo $permission['granted'] ? 'Granted' : 'Denied'; ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
