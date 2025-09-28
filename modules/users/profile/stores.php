<?php
// Stores tab include - expects $availableStores and $storeRouter to be available
?>
<div class="profile-section">
    <div class="section-header">
        <div class="section-icon">
            <i class="fas fa-store"></i>
        </div>
        <h3>Store Access Management</h3>
    </div>
    <form method="POST" action="">
        <input type="hidden" name="action" value="update_store_access">
        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($_SESSION['user_id']); ?>">
        <div class="store-access-grid">
            <?php foreach ((array)$availableStores as $store): ?>
            <div class="store-card <?php echo $store['accessible'] ? 'accessible' : ''; ?>">
                <div class="store-icon">
                    <i class="fas fa-store"></i>
                </div>
                <h4><?php echo htmlspecialchars($store['name']); ?></h4>
                <p><?php echo htmlspecialchars($store['location']); ?></p>
                <span class="permission-status <?php echo $store['accessible'] ? 'status-granted' : 'status-denied'; ?>">
                    <?php echo $store['accessible'] ? 'Access Granted' : 'No Access'; ?>
                </span>
                <div style="margin-top:10px;">
                    <label>
                        <input type="checkbox" name="store_ids[]" value="<?php echo htmlspecialchars($store['id']); ?>" <?php echo $store['accessible'] ? 'checked' : ''; ?>>
                        Grant Access
                    </label>
                    <?php if ($store['accessible']): ?>
                        <div style="margin-top: 6px;"><small>Role: <?php echo htmlspecialchars($store['role']); ?></small></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($availableStores)): ?>
            <div style="margin-top:12px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Store Access</button>
            </div>
        <?php endif; ?>

    </form>

    <?php if (empty($availableStores)): ?>
            <div class="store-card">
                <div class="store-icon">
                    <i class="fas fa-info-circle"></i>
                </div>
                <h4>No Stores Available</h4>
                <p>Contact your administrator for store access</p>
                <span class="permission-status status-denied">No Access</span>
            </div>
        <?php endif; ?>
    </div>
</div>
