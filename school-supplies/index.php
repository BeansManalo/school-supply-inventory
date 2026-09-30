<?php
require __DIR__ . '/includes/bootstrap.php';

$title = 'Dashboard';
$summary = $inventory->summary();

$week = $inventory->dailyTotals(7);
$weekIn = array_sum(array_column($week, 'in'));
$weekOut = array_sum(array_column($week, 'out'));
$chartMax = max([1, ...array_column($week, 'in'), ...array_column($week, 'out')]);
$bar = fn(int $n) => $n ? 'max(3px,' . round($n / $chartMax * 100, 1) . '%)' : '0';

$categories = array_slice($inventory->unitsByCategory(), 0, 6, true);
$categoryMax = max([1, ...array_values($categories)]);

$restock = array_filter($inventory->products(), fn($p) => $p->status() !== 'OK');
usort($restock, fn($a, $b) => $a->quantity <=> $b->quantity);   // out of stock first
$restock = array_slice($restock, 0, 5);
$recent = array_slice($inventory->movements(), 0, 6);
include __DIR__ . '/includes/header.php';
?>
<div class="actions">
    <button type="button" class="btn" id="open-scan">Scan QR code</button>
    <a class="btn btn-secondary" href="product_form.php">+ Add product</a>
    <a class="btn btn-secondary" href="products.php?stock=1">Record stock in/out</a>
    <button type="button" class="btn btn-secondary" id="open-qr">Create QR code</button>
</div>

<div class="cards">
    <div class="card stat">
        <span class="stat-icon"><svg viewBox="0 0 24 24"><path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/></svg></span>
        <div>
            <div class="value"><?= $summary['products'] ?></div>
            <div class="label">Products</div>
            <div class="sub"><?= $summary['categories'] ?> <?= $summary['categories'] === 1 ? 'category' : 'categories' ?></div>
        </div>
    </div>
    <div class="card stat ok">
        <span class="stat-icon"><svg viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/></svg></span>
        <div>
            <div class="value"><?= $summary['units'] ?></div>
            <div class="label">Units on hand</div>
            <div class="sub">Last 7 days: +<?= $weekIn ?> / −<?= $weekOut ?></div>
        </div>
    </div>
    <div class="card stat warn">
        <span class="stat-icon"><svg viewBox="0 0 24 24"><path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17.5v.01"/></svg></span>
        <div>
            <div class="value"><?= $summary['low'] ?></div>
            <div class="label">Low stock</div>
            <div class="sub">At or below reorder level</div>
        </div>
    </div>
    <div class="card stat danger">
        <span class="stat-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/></svg></span>
        <div>
            <div class="value"><?= $summary['out'] ?></div>
            <div class="label">Out of stock</div>
            <div class="sub">Need restocking</div>
        </div>
    </div>
</div>

<div class="dash-grid">
    <section class="card">
        <div class="panel-head">
            <h2>Stock movement, last 7 days</h2>
            <span class="legend"><span>In (<?= $weekIn ?>)</span><span class="out">Out (<?= $weekOut ?>)</span></span>
        </div>
        <div class="chart">
            <?php foreach ($week as $day => $t): ?>
                <div title="<?= date('D, M j', strtotime($day)) ?>: +<?= $t['in'] ?> in, −<?= $t['out'] ?> out">
                    <div class="bars">
                        <span class="bar" style="height:<?= $bar($t['in']) ?>"></span>
                        <span class="bar out" style="height:<?= $bar($t['out']) ?>"></span>
                    </div>
                    <span class="chart-label"><?= date('D', strtotime($day)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <div class="panel-head">
            <h2>Units by category</h2>
            <a href="products.php?group=category">Products</a>
        </div>
        <?php foreach ($categories as $name => $units): ?>
            <div class="hbar">
                <div class="hbar-top"><span><?= $name === '' ? 'No category' : e($name) ?></span><b><?= $units ?></b></div>
                <div class="track"><span style="width:<?= round($units / $categoryMax * 100, 1) ?>%"></span></div>
            </div>
        <?php endforeach; ?>
        <?php if (!$categories): ?><p class="empty">No products yet.</p><?php endif; ?>
    </section>

    <section class="card">
        <div class="panel-head">
            <h2>Needs restock</h2>
            <a href="reports.php">Reports</a>
        </div>
        <?php foreach ($restock as $p): ?>
            <div class="row">
                <div class="row-main">
                    <div class="row-name"><?= e($p->name) ?></div>
                    <div class="hint"><span class="code"><?= e($p->id) ?></span> · <?= $p->quantity ?> left, reorder at <?= $p->reorderLevel ?></div>
                    <div class="track warn"><span style="width:<?= $p->reorderLevel ? round(min(1, $p->quantity / $p->reorderLevel) * 100, 1) : 0 ?>%"></span></div>
                </div>
                <span class="badge st-<?= strtolower($p->status()) ?>"><?= e($p->status()) ?></span>
            </div>
        <?php endforeach; ?>
        <?php if (!$restock): ?><p class="empty">Everything is stocked.</p><?php endif; ?>
    </section>

    <section class="card">
        <div class="panel-head">
            <h2>Recent activity</h2>
            <a href="history.php">History</a>
        </div>
        <?php foreach ($recent as $m): ?>
            <div class="row">
                <span class="badge row-qty qty-<?= $m->type ?>"><?= $m->type === 'in' ? '+' : '−' ?><?= $m->quantity ?></span>
                <div class="row-main">
                    <div class="row-name"><?= e($inventory->product($m->productId)?->name) ?></div>
                    <div class="hint"><?= date('M j, H:i', strtotime($m->date)) ?><?= $m->note === '' ? '' : ' · ' . e($m->note) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$recent): ?><p class="empty">No movements yet.</p><?php endif; ?>
    </section>
</div>

<dialog id="scan-dialog" class="form">
    <h2>Scan QR code</h2>
    <div data-source>
        <p class="hint">Use a camera, or pick a saved image of the code. Only codes made with Create QR code are accepted.</p>
        <p class="alert alert-error" hidden></p>
        <video autoplay muted playsinline hidden></video>
        <form method="dialog" class="actions">
            <button type="button" class="btn" data-camera>Use camera</button>
            <button type="button" class="btn btn-secondary" data-image>Choose image</button>
            <button class="btn btn-secondary">Cancel</button>
        </form>
        <input type="file" accept="image/*" hidden>
    </div>
    <form method="post" action="scan.php" data-verify hidden>
        <input type="hidden" name="apply" value="1">
        <input type="hidden" name="text">
        <div class="alert alert-error" data-critical hidden>
            <strong>Critical mismatch: nothing was added.</strong>
            <p data-problem></p>
            <p>To resolve it:</p>
            <ul>
                <li>If the inventory is right, discard this scan and make a new QR code with the inventory's code and name.</li>
                <li>If the inventory is wrong, correct the product under Products, then scan again.</li>
                <li>If this is a different product, give it a code and name that are not in use and make a new QR code.</li>
            </ul>
        </div>
        <p class="hint" data-status></p>
        <fieldset data-fields>
            <label>Product code <span class="hint"></span>
                <input type="text" name="id" readonly>
            </label>
            <label>Product name <span class="hint"></span>
                <input type="text" name="name" readonly>
            </label>
            <label>Category <span class="hint"></span>
                <input type="text" name="category" maxlength="50">
            </label>
            <label>Stock to add <span class="hint"></span>
                <input type="number" name="stock" min="1" step="1" required>
            </label>
            <label data-reorder>Reorder level <span class="hint">(not on the QR code; flagged as Low at or below this)</span>
                <input type="number" name="reorder_level" min="0" step="1" required>
            </label>
        </fieldset>
        <fieldset class="alert alert-warn" data-conflict hidden disabled>
            <p></p>
            <label><input type="radio" name="choice" value="keep" required> <span></span></label>
            <label><input type="radio" name="choice" value="overwrite"> <span></span></label>
        </fieldset>
        <div class="actions">
            <button class="btn" data-accept>Accept and add stock</button>
            <button class="btn btn-secondary" formmethod="dialog" formnovalidate>Discard</button>
        </div>
    </form>
</dialog>
<dialog id="qr-dialog" class="form">
    <h2>Create QR code</h2>
    <form>
        <label>Product code
            <input type="text" name="id" maxlength="50" required>
        </label>
        <label>Product name
            <input type="text" name="name" maxlength="150" required>
        </label>
        <label>Category <span class="hint">(optional)</span>
            <input type="text" name="category" maxlength="50">
        </label>
        <label>Stock to add <span class="hint">(optional; added to the product when the code is scanned)</span>
            <input type="number" name="stock" min="1" step="1">
        </label>
        <div class="actions">
            <button class="btn">Create</button>
            <button class="btn btn-secondary" formmethod="dialog" formnovalidate>Cancel</button>
        </div>
    </form>
    <div class="qr-result" hidden>
        <canvas></canvas>
        <p class="hint"></p>
        <form method="dialog" class="actions">
            <a class="btn">Save image</a>
            <button type="button" class="btn btn-secondary" data-back>Edit</button>
            <button class="btn btn-secondary">Close</button>
        </form>
    </div>
</dialog>
<script src="lib/qrcode/qrcode.js"></script>
<script src="lib/jsqr/jsQR.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
