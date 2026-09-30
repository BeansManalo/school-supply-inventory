<?php
require __DIR__ . '/includes/bootstrap.php';

$days = $inventory->movementDays();
$parse = fn(string $s, string $format) => ($t = DateTimeImmutable::createFromFormat('!' . $format, $s)) && $t->format($format) === $s ? $t : null;
$selected = $parse($_GET['d'] ?? '', 'Y-m-d') ?? new DateTimeImmutable(array_key_first($days) ?? 'today');   // default: latest activity
$month = ($parse($_GET['m'] ?? '', 'Y-m') ?? $selected)->modify('first day of this month');
$key = $selected->format('Y-m-d');
$today = new DateTimeImmutable('today');
$url = fn(DateTimeImmutable $m, string $d) => 'history.php?' . http_build_query(['m' => $m->format('Y-m'), 'd' => $d]);

$title = 'Movement History';
$active = 'reports.php';
include __DIR__ . '/includes/header.php';
?>
<div class="history-page">
    <div class="cal" data-month="<?= $month->format('Y-m') ?>" data-day="<?= $key ?>"
         data-active="<?= implode(',', array_unique(array_map(fn($d) => substr($d, 0, 7), array_keys($days)))) ?>">
        <div class="cal-view">
        <div class="cal-head">
            <a class="cal-nav" href="<?= $url($month->modify('-1 month'), $key) ?>" title="Previous month">‹</a>
            <button type="button" class="cal-title" title="Pick a month"><?= $month->format('F Y') ?></button>
            <a class="cal-nav" href="<?= $url($month->modify('+1 month'), $key) ?>" title="Next month">›</a>
        </div>
        <div class="cal-grid">
            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $wd): ?><b><?= $wd ?></b><?php endforeach; ?>
            <?php for ($i = 0; $i < (int) $month->format('w'); $i++): ?><span></span><?php endfor; ?>
            <?php for ($n = 0; $n < (int) $month->format('t'); $n++): ?>
                <?php
                $d = $month->modify("+$n days")->format('Y-m-d');
                $count = count($days[$d] ?? []);
                $cls = 'cal-day' . ($d === $today->format('Y-m-d') ? ' today' : '') . ($d === $key ? ' sel' : '');
                ?>
                <?php if ($count): ?>
                    <a class="<?= $cls ?> has" href="<?= $url($month, $d) ?>" title="<?= $count ?> movements"><?= $n + 1 ?><small><?= $count ?></small></a>
                <?php else: ?>
                    <span class="<?= $cls ?>"><?= $n + 1 ?></span>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <div class="cal-foot">
            <span><span class="swatch"></span>Days with activity</span>
            <a href="<?= $url($today, $today->format('Y-m-d')) ?>">Today</a>
        </div>
        </div>
        <div class="cal-picker" hidden></div>
    </div>

    <div>
        <?php $day = $key; $items = $days[$key] ?? []; include __DIR__ . '/includes/movement_list.php'; ?>
        <p><a href="reports.php">← Back to reports</a></p>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
