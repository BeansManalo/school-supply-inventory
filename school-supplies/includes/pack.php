<?php // One product "package". Expects $p (Product). With $editable it shows Edit; otherwise the reorder level. ?>
<article class="card pack" data-id="<?= e($p->id) ?>" data-name="<?= e($p->name) ?>"
         data-category="<?= e($p->category) ?>" data-reorder="<?= $p->reorderLevel ?>"
         data-stock="<?= $p->quantity ?>" data-status="<?= strtolower($p->status()) ?>">
    <div class="pack-top">
        <span class="pack-id" title="<?= e($p->id) ?>"><?= e($p->id) ?></span>
        <span class="badge st-<?= strtolower($p->status()) ?>"><?= e($p->status()) ?></span>
    </div>
    <div class="pack-name" title="<?= e($p->name) ?>"><?= e($p->name) ?></div>
    <div class="pack-stock"><span class="value"><?= $p->quantity ?></span> <span class="label">in stock</span></div>
    <div class="tag"><?= $p->category === '' ? 'No category' : e($p->category) ?></div>
    <div class="pack-foot">
        <?php if ($editable ?? false): ?>
            <button class="btn btn-link" data-edit>Edit</button>
        <?php else: ?>
            <span class="tag">Reorder at <?= $p->reorderLevel ?></span>
        <?php endif; ?>
    </div>
</article>
