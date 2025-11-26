<?php
// Store List Content - Partial for AJAX loading
?>
<!-- Stores Grid -->
<?php if (!empty($stores)): ?>
<div class="stores-grid">
    <?php foreach ($stores as $store): ?>
    <div class="store-card">
        <div class="store-header">
            <h3 class="store-name"><?php echo htmlspecialchars($store['name']); ?></h3>
            <?php if (!empty($store['code'])): ?>
            <span class="store-code"><?php echo htmlspecialchars($store['code']); ?></span>
            <?php endif; ?>
        </div>
        
        <div class="store-info">
            <div class="info-row col-address" title="<?php echo htmlspecialchars($store['address'] ?? 'N/A'); ?>">
                <i class="fas fa-map-marker-alt"></i> <span><?php echo htmlspecialchars($store['address'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-row col-city">
                <i class="fas fa-city"></i> <span><?php echo htmlspecialchars($store['city'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($store['state'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-row col-phone">
                <?php if (!empty($store['phone'])): ?>
                <i class="fas fa-phone"></i> <span><?php echo htmlspecialchars($store['phone']); ?></span>
                <?php else: ?>
                <span class="empty-cell">-</span>
                <?php endif; ?>
            </div>
            <div class="info-row col-manager">
                <?php if (!empty($store['manager_name'])): ?>
                <i class="fas fa-user-tie"></i> <span><?php echo htmlspecialchars($store['manager_name']); ?></span>
                <?php else: ?>
                <span class="empty-cell">-</span>
                <?php endif; ?>
            </div>
            <div class="info-row col-pos">
                <?php if (!empty($store['has_pos'])): ?>
                <i class="fas fa-cash-register"></i> <span class="badge badge-success" style="background: #d1fae5; color: #065f46; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600;">Enabled</span>
                <?php else: ?>
                <i class="fas fa-cash-register" style="color: #9ca3af;"></i> <span class="badge badge-secondary" style="background: #f3f4f6; color: #6b7280; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600;">Disabled</span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="store-stats">
            <div class="stat-item">
                <div class="value"><?php echo number_format($store['product_count']); ?></div>
                <div class="label">Products</div>
            </div>
            <div class="stat-item">
                <div class="value"><?php echo number_format($store['total_stock']); ?></div>
                <div class="label">Total Stock</div>
            </div>
        </div>
        
        <div class="store-actions">
            <a href="profile.php?id=<?php echo $store['id']; ?>" class="btn-view">
                <i class="fas fa-eye"></i> View
            </a>
            <?php if (currentUserHasPermission('can_edit_stores')): ?>
            <a href="edit.php?id=<?php echo $store['id']; ?>" class="btn-edit">
                <i class="fas fa-edit"></i> Edit
            </a>
            <?php endif; ?>
            <a href="../stock/list.php?store=<?php echo $store['id']; ?>" class="btn-inventory">
                <i class="fas fa-boxes"></i> Stock
            </a>
            <?php if (currentUserHasPermission('can_delete_stores')): ?>
            <button onclick="confirmDeleteStore(<?php echo $store['id']; ?>, '<?php echo htmlspecialchars(addslashes($store['name'])); ?>', <?php echo $store['product_count']; ?>)" class="btn-delete">
                <i class="fas fa-trash"></i> Delete
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($pagination['total_pages'] > 1): ?>
<div class="pagination">
    <div class="pagination-info">
        Showing <?php echo ($pagination['offset'] + 1); ?>-<?php echo min($pagination['offset'] + $pagination['per_page'], $pagination['total_records']); ?> 
        of <?php echo number_format($pagination['total_records']); ?> stores
    </div>
    
    <div class="pagination-links">
        <?php if ($pagination['has_prev']): ?>
        <a href="?page=<?php echo ($pagination['current_page'] - 1); ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="ajax-link" data-page="<?php echo ($pagination['current_page'] - 1); ?>">
            <i class="fas fa-chevron-left"></i> Previous
        </a>
        <?php endif; ?>
        
        <?php
        // Show page numbers
        $start_page = max(1, $pagination['current_page'] - 2);
        $end_page = min($pagination['total_pages'], $pagination['current_page'] + 2);
        
        for ($i = $start_page; $i <= $end_page; $i++):
        ?>
            <?php if ($i == $pagination['current_page']): ?>
            <span class="current"><?php echo $i; ?></span>
            <?php else: ?>
            <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="ajax-link" data-page="<?php echo $i; ?>">
                <?php echo $i; ?>
            </a>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($pagination['has_next']): ?>
        <a href="?page=<?php echo ($pagination['current_page'] + 1); ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="ajax-link" data-page="<?php echo ($pagination['current_page'] + 1); ?>">
            Next <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="no-stores">
    <i class="fas fa-store-slash"></i>
    <h2>No Stores Found</h2>
    <p><?php echo $search ? 'No stores match your search criteria.' : 'Get started by adding your first store.'; ?></p>
    <?php if (currentUserHasPermission('can_add_stores')): ?>
    <br>
    <a href="add.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add Your First Store
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>