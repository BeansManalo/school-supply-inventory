<?php // "Create report" popup. Expects $inventory. Submits to report.php, which opens in a new tab. ?>
<?php
$products = $inventory->products();
$categories = array_unique(array_filter(array_column($products, 'category')));
sort($categories);
$hasNone = in_array('', array_column($products, 'category'), true);
$papers = require __DIR__ . '/paper_sizes.php';
?>
<dialog id="report-dialog" class="form">
    <h2>Create report</h2>
    <form action="report.php" method="get" target="_blank">
        <div class="row2">
            <label>From
                <input type="date" name="from" value="<?= date('Y-m-d', strtotime('-30 days')) ?>" required>
            </label>
            <label>To
                <input type="date" name="to" value="<?= date('Y-m-d') ?>" required>
            </label>
        </div>
        <label>Show
            <select name="type">
                <option value="both">Stock in and out</option>
                <option value="in">Stock in only</option>
                <option value="out">Stock out only</option>
            </select>
        </label>
        <label>Paper size <span class="hint">(match the paper you will print on)</span>
            <select name="paper">
                <?php foreach ($papers as $key => [$label]): ?>
                    <option value="<?= $key ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <details>
            <summary>Advanced options</summary>
            <label class="check"><input type="checkbox" id="by-days"> Pick specific days instead of a date range</label>
            <div class="day-pick" data-days="<?= e(json_encode(array_map('count', $inventory->movementDays()))) ?>" hidden>
                <div class="dp-view">
                    <div class="cal-head">
                        <button type="button" class="cal-nav" data-nav="-1">‹</button>
                        <button type="button" class="cal-title" title="Pick a month"></button>
                        <button type="button" class="cal-nav" data-nav="1">›</button>
                    </div>
                    <div class="cal-grid"></div>
                    <p class="hint dp-count"></p>
                    <div class="cal-foot">
                        <span><span class="swatch"></span>Days with activity</span>
                        <button type="button" class="btn btn-link" data-today>Today</button>
                    </div>
                </div>
                <div class="cal-picker" hidden></div>
                <input type="hidden" name="days" value="">
            </div>
            <label>Group by
                <select name="group">
                    <option value="date">Date</option>
                    <option value="product">Product</option>
                    <option value="category">Category</option>
                </select>
            </label>
            <p class="hint">Categories (none selected = all)</p>
            <div class="checks">
                <?php foreach ($categories as $c): ?>
                    <label><input type="checkbox" name="cat[]" value="<?= e($c) ?>"> <?= e($c) ?></label>
                <?php endforeach; ?>
                <?php if ($hasNone): ?>
                    <label><input type="checkbox" name="cat[]" value=""> No category</label>
                <?php endif; ?>
            </div>
            <p class="hint">Products (none selected = all)</p>
            <div class="checks wide">
                <?php foreach ($products as $p): ?>
                    <label><input type="checkbox" name="prod[]" value="<?= e($p->id) ?>"> <?= e($p->name) ?> <span class="code"><?= e($p->id) ?></span></label>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="notes" value="0">
            <label class="check"><input type="checkbox" name="notes" value="1" checked> Include notes</label>
        </details>
        <div class="actions">
            <button class="btn">Generate PDF</button>
            <button class="btn btn-secondary" formmethod="dialog" formnovalidate>Cancel</button>
        </div>
    </form>
</dialog>
