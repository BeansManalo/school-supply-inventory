<?php // One day of movements. Expects $day ('Y-m-d'), $items (StockMovement list) and $inventory. ?>
<?php
$in = array_sum(array_map(fn($m) => $m->type === 'in' ? $m->quantity : 0, $items));
$out = array_sum(array_map(fn($m) => $m->type === 'out' ? $m->quantity : 0, $items));
?>
<div class="day-head">
    <h3><?= date('l, F j, Y', strtotime($day)) ?></h3>
    <span class="hint"><?= count($items) ?> <?= count($items) === 1 ? 'movement' : 'movements' ?></span>
    <span class="badge qty-in">+<?= $in ?> in</span>
    <span class="badge qty-out">−<?= $out ?> out</span>
</div>
<?php foreach ($items as $m): ?>
    <div class="entry <?= $m->type ?>">
        <span class="entry-time"><?= e(substr($m->date, 11)) ?></span>
        <div>
            <div class="entry-name"><?= e($inventory->product($m->productId)?->name) ?></div>
            <span class="entry-code"><?= e($m->productId) ?></span>
        </div>
        <span class="entry-qty qty-<?= $m->type ?>"><?= $m->type === 'in' ? '+' : '−' ?><?= $m->quantity ?></span>
        <div class="entry-note<?= $m->note === '' ? ' none' : '' ?>"><?= $m->note === '' ? 'No note' : e($m->note) ?></div>
    </div>
<?php endforeach; ?>
<?php if (!$items): ?>
    <p class="empty">No movements on this day.</p>
<?php endif; ?>
