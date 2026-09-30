<?php // A tab per day for the 7 most recent days with activity; click a day to see its entries. Expects $inventory. ?>
<?php $recent = array_slice($inventory->movementDays(), 0, 7, true); $first = array_key_first($recent); ?>
<div class="section-head">
    <h2>Movement history</h2>
    <div class="actions">
        <button type="button" class="btn" id="open-report">Create report</button>
        <a class="btn btn-secondary" href="history.php">Open calendar</a>
    </div>
</div>
<?php if (!$recent) { echo '<p class="empty">No movements yet.</p>'; return; } ?>
<div class="day-tabs">
    <?php foreach ($recent as $day => $items): ?>
        <button type="button" class="day-tab<?= $day === $first ? ' active' : '' ?>" data-day="<?= $day ?>">
            <span class="dt-wd"><?= date('D', strtotime($day)) ?></span>
            <span class="dt-num"><?= date('j', strtotime($day)) ?></span>
            <span class="dt-mo"><?= date('M', strtotime($day)) ?></span>
            <span class="dt-count"><?= count($items) ?> <?= count($items) === 1 ? 'move' : 'moves' ?></span>
        </button>
    <?php endforeach; ?>
</div>
<?php foreach ($recent as $day => $items): ?>
    <div data-panel="<?= $day ?>" <?= $day === $first ? '' : 'hidden' ?>>
        <?php include __DIR__ . '/movement_list.php'; ?>
    </div>
<?php endforeach; ?>
