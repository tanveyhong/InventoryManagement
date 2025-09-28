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
    <form method="POST" action="">
        <input type="hidden" name="action" value="update_permissions">
        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($_SESSION['user_id']); ?>">
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

        <div style="margin-top:12px;">
            <label for="role_id">Set role:</label>
            <select name="role_id" id="role_id">
                <option value="">-- Select Role --</option>
                <?php foreach ($roleManager->getAllRoles() as $r): ?>
                    <option value="<?php echo htmlspecialchars($r['id']); ?>"><?php echo htmlspecialchars($r['role_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Role</button>
        </div>
    </form>
</div>
