<?php
/**
 * Reusable Page Header Component
 * For pages that need a simple header without statistics
 */

// Default values
$header_title = $header_title ?? 'Page Title';
$header_subtitle = $header_subtitle ?? 'Page description';
$header_icon = $header_icon ?? 'fas fa-home';
$header_actions = $header_actions ?? [];
$header_style = $header_style ?? '';
?>

<div class="page-header" <?php echo !empty($header_style) ? 'style="' . htmlspecialchars($header_style) . '"' : ''; ?>>
    <h2><i class="<?php echo htmlspecialchars($header_icon); ?>"></i> <?php echo htmlspecialchars($header_title); ?></h2>
    <p><?php echo htmlspecialchars($header_subtitle); ?></p>
    <?php if (!empty($header_actions)): ?>
        <div class="page-actions">
            <?php foreach ($header_actions as $action): ?>
                <a href="<?php echo htmlspecialchars($action['url']); ?>" 
                   class="btn <?php echo htmlspecialchars($action['class'] ?? 'btn-primary'); ?>"
                   <?php echo !empty($action['style']) ? 'style="' . htmlspecialchars($action['style']) . '"' : ''; ?>>
                    <i class="<?php echo htmlspecialchars($action['icon']); ?>"></i> 
                    <?php echo htmlspecialchars($action['text']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>