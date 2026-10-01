<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/lib/fpdf/fpdf.php';

// Streams the report as a PDF. Filters come from the "Create report" popup on the Reports page; anything missing or invalid falls back to a default.
$valid = fn($s) => ($t = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $s)) && $t->format('Y-m-d') === $s;
$from = $valid($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to = $valid($_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$type = in_array($_GET['type'] ?? '', ['in', 'out'], true) ? $_GET['type'] : 'both';
$group = in_array($_GET['group'] ?? '', ['product', 'category'], true) ? $_GET['group'] : 'date';
$notes = ($_GET['notes'] ?? '1') === '1';
['sizes' => $sizes, 'units' => $units, 'limits' => [$lo, $hi]] = require __DIR__ . '/includes/paper_sizes.php';
$papers = array_merge(...array_values($sizes));
$paper = in_array($_GET['paper'] ?? '', array_keys($papers), true) ? $_GET['paper'] : array_key_first($papers);
[, $pw, $ph] = $papers[$paper];   // page width and height in mm
if (($_GET['paper'] ?? '') === 'custom') {   // own size: width and height in the chosen unit, each held between the limits
    $mm = $units[$_GET['unit'] ?? ''] ?? 1;
    [$pw, $ph] = array_map(fn($side) => min($hi, max($lo, (float) ($_GET[$side] ?? 0) * $mm)), ['w', 'h']);
}
$land = ($_GET['orient'] ?? '') === 'landscape';
[$pw, $ph] = $land ? [max($pw, $ph), min($pw, $ph)] : [min($pw, $ph), max($pw, $ph)];   // portrait: shorter side across; landscape: longer side across
$cats = (array) ($_GET['cat'] ?? []);     // empty = every category
$prods = (array) ($_GET['prod'] ?? []);   // empty = every product
$days = array_filter(explode(',', (string) ($_GET['days'] ?? '')), $valid);   // specific days replace the date range
sort($days);

$rows = array_reverse(array_filter($inventory->movements(), function ($m) use ($inventory, $from, $to, $days, $type, $cats, $prods) {
    $p = $inventory->product($m->productId);
    $day = substr($m->date, 0, 10);
    return $p && ($days ? in_array($day, $days, true) : $day >= $from && $day <= $to)
        && ($type === 'both' || $m->type === $type)
        && (!$cats || in_array($p->category, $cats, true))
        && (!$prods || in_array($m->productId, $prods, true));
}));   // oldest first

$groups = [];
foreach ($rows as $m) {
    $p = $inventory->product($m->productId);
    $key = match ($group) {
        'product' => "$p->name ($m->productId)",
        'category' => $p->category === '' ? 'No category' : $p->category,
        default => date('l, F j, Y', strtotime($m->date)),
    };
    $groups[$key][] = $m;
}
if ($group !== 'date') {
    ksort($groups);
}

$sum = fn($items, $t) => array_sum(array_map(fn($m) => $m->type === $t ? $m->quantity : 0, $items));
$showIn = $type !== 'out';
$showOut = $type !== 'in';
$scope = [];
if ($cats) {
    $scope[] = 'Categories: ' . implode(', ', array_map(fn($c) => $c === '' ? 'No category' : $c, $cats));
}
if ($prods) {
    $scope[] = count($prods) . ' selected ' . (count($prods) === 1 ? 'product' : 'products');
}
$typeLabel = ['both' => 'Stock in and out', 'in' => 'Stock in only', 'out' => 'Stock out only'][$type];

$latin = fn($s) => mb_convert_encoding((string) $s, 'Windows-1252', 'UTF-8');   // FPDF's built-in fonts are Windows-1252
$range = $days ? implode(', ', array_map(fn($d) => date('M j, Y', strtotime($d)), $days)) : date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));

// Everything below is laid out from the paper's own size: margin 7% of the shorter side (15 mm on A4), then usable width and bottom edge.
$m = round(min($pw, $ph) * .07);
$W = $pw - 2 * $m;
$bottom = $ph - $m;

$pdf = new FPDF($land ? 'L' : 'P', 'mm', [$pw, $ph]);
$pdf->SetMargins($m, $m, $m);
$pdf->SetAutoPageBreak(false);
$pdf->SetTitle('Stock Movement Report');
$pdf->AddPage();

$pdf->SetFont('Helvetica', 'B', 18);
$pdf->SetTextColor(18, 50, 79);
$pdf->Cell(0, 9, 'Stock Movement Report', 0, 1);
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(107, 122, 137);
$pdf->MultiCell(0, 4.5, $latin("$range · $typeLabel · " . ($scope ? implode(' · ', $scope) : 'All products') . ' · Generated ' . date('M j, Y g:i A')), 0, 'L');
$pdf->Ln(4);

$cards = [['Movements', count($rows)], ['Units in', '+' . $sum($rows, 'in')], ['Units out', '-' . $sum($rows, 'out')], ['Net change', $sum($rows, 'in') - $sum($rows, 'out')]];
$y = $pdf->GetY();
$cw = ($W - 12) / 4;   // four cards with 4 mm gaps
foreach ($cards as $i => [$label, $value]) {
    $x = $m + $i * ($cw + 4);
    $pdf->SetDrawColor(221, 227, 234);
    $pdf->Rect($x, $y, $cw, 17);
    $pdf->SetXY($x + 3, $y + 2);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->SetTextColor(31, 95, 153);
    $pdf->Cell($cw - 6, 7, $value);
    $pdf->SetXY($x + 3, $y + 10);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(107, 122, 137);
    $pdf->Cell($cw - 6, 5, $label);
}
$pdf->SetY($y + 23);

// Columns: [heading, width in mm on A4's 180 mm (0 = share what is left), alignment, optional smallest width in mm]
// Landscape layout: the note shares the free width with the product instead of keeping a fixed width, and the narrow columns stay as they are.
$cols = [[$group === 'date' ? 'Time' : 'When', $group === 'date' ? 16 : 32, 'L'], ['Product', 0, 'L'], ['ID', 32, 'R', 26]];
$showIn && $cols[] = ['In', 14, 'R'];
$showOut && $cols[] = ['Out', 14, 'R'];
$notes && $cols[] = ['Note', $land ? 0 : 42, 'L'];
$k = $land ? min(1, $W / 180) : $W / 180;   // portrait scales the columns to the paper; landscape only shrinks them on a narrow one
$fixed = array_map(fn($c) => $c[1] ? max($c[3] ?? 12, $c[1] * $k) : 0, $cols);   // never under 12 mm so numbers still fit; the ID keeps room for a 13-digit code
$free = ($W - array_sum($fixed)) / count(array_filter($fixed, fn($w) => !$w));
$widths = array_map(fn($w) => $w ?: $free, $fixed);
$aligns = array_column($cols, 2);
$headings = array_column($cols, 0);

// Word-wraps text to a width in mm (a word wider than the column is broken instead).
$wrap = function (string $text, float $width) use ($pdf): array {
    $pdf->SetFont('Helvetica', '', 9);   // measure in the body font
    $out = [];
    $line = '';
    foreach (str_split($text) as $ch) {
        if ($pdf->GetStringWidth($line . $ch) > $width) {
            if (($cut = strrpos($line, ' ')) === false) {
                $out[] = $line;
                $line = '';
            } else {
                $out[] = substr($line, 0, $cut);
                $line = substr($line, $cut + 1);
            }
        }
        $line .= $ch;
    }
    return [...$out, $line];
};
$prepare = fn(array $cells) => array_map(fn($c, $w) => $wrap($latin($c), $w - 3), $cells, $widths);
$height = fn(array $lines) => max(array_map('count', $lines)) * 4.6 + 3;
$cellsOf = fn($m) => [
    $group === 'date' ? substr($m->date, 11) : $m->date, $inventory->product($m->productId)->name, $m->productId,
    ...($showIn ? [$m->type === 'in' ? $m->quantity : ''] : []),
    ...($showOut ? [$m->type === 'out' ? $m->quantity : ''] : []),
    ...($notes ? [$m->note] : []),
];

// Draws one table row from wrapped cells; $kind is 'head', 'body' or 'total'.
$draw = function (array $lines, string $kind) use ($pdf, $widths, $aligns, $height, $m, $W): void {
    $y = $pdf->GetY();
    $h = $height($lines);
    if ($kind !== 'body') {
        $head = $kind === 'head';
        $pdf->SetFillColor(...($head ? [20, 108, 85] : [242, 245, 248]));
        $pdf->Rect($m, $y, $W, $h, 'F');
        $pdf->SetTextColor(...($head ? [255, 255, 255] : [29, 42, 54]));
        $pdf->SetFont('Helvetica', 'B', $head ? 8 : 9);
    } else {
        $pdf->SetTextColor(29, 42, 54);
        $pdf->SetFont('Helvetica', '', 9);
    }
    $x = $m;
    foreach ($lines as $i => $cell) {
        foreach ($cell as $n => $text) {
            $pdf->SetXY($x + 1.5, $y + 1.5 + $n * 4.6);
            $pdf->Cell($widths[$i] - 3, 4.6, $text, 0, 0, $aligns[$i]);
        }
        $x += $widths[$i];
    }
    if ($kind === 'body') {
        $pdf->SetDrawColor(184, 196, 208);
        $pdf->Line($m, $y + $h, $m + $W, $y + $h);
    }
    $pdf->SetY($y + $h);
};

foreach ($groups as $label => $items) {
    if ($pdf->GetY() + 17 + $height($prepare($headings)) + $height($prepare($cellsOf($items[0]))) > $bottom) {   // keep the heading with its header row and first entry (17 mm = heading, count line and some slack)
        $pdf->AddPage();
    }
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetTextColor(20, 108, 85);
    $pdf->MultiCell(0, 6, $latin($label), 0, 'L');   // wraps, since a product heading can be long
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(107, 122, 137);
    $pdf->Cell(0, 5, count($items) . (count($items) === 1 ? ' movement' : ' movements'), 0, 1);

    $draw($prepare($headings), 'head');
    foreach ($items as $m) {
        $lines = $prepare($cellsOf($m));
        if ($pdf->GetY() + $height($lines) > $bottom) {
            $pdf->AddPage();
            $draw($prepare($headings), 'head');
        }
        $draw($lines, 'body');
    }
    $totals = ['Total', '', ''];
    $showIn && $totals[] = $sum($items, 'in');
    $showOut && $totals[] = $sum($items, 'out');
    $notes && $totals[] = '';
    $draw($prepare($totals), 'total');
    $pdf->Ln(6);
}
if (!$rows) {
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(107, 122, 137);
    $pdf->Cell(0, 8, 'No movements match these filters.');
}

$pdf->Output('I', 'stock-movement-report.pdf');
