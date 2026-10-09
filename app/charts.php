<?php
declare(strict_types=1);

/**
 * Categorical series colours (fixed order, validated for colour-blind separation
 * and contrast on the white surface): sky-600, amber-600, violet-600.
 */
const CHART_SERIES_COLORS = ['#0284c7', '#d97706', '#7c3aed'];

/**
 * Render a small multi-series line chart as inline SVG with a legend, end labels,
 * per-point hover tooltips and an accessible data table.
 *
 * @param array $points  [['label' => '05 Oct', 'values' => ['key' => int, ...]], ...]
 * @param array $series  ['key' => 'Legend label', ...] (max 3)
 */
function line_chart(array $points, array $series, string $id = 'chart'): string
{
    if (count($points) < 2) {
        return '<p class="text-xs text-slate-400 italic">Trend appears once figures have been updated at least twice.</p>';
    }
    $w = 640; $h = 220;
    $padL = 44; $padR = 56; $padT = 12; $padB = 28;
    $plotW = $w - $padL - $padR;
    $plotH = $h - $padT - $padB;

    $max = 0;
    foreach ($points as $p) {
        foreach (array_keys($series) as $k) {
            $max = max($max, (int) ($p['values'][$k] ?? 0));
        }
    }
    $max = nice_ceiling($max);
    $n = count($points);
    $x = fn(int $i) => $padL + ($n === 1 ? 0 : $i * $plotW / ($n - 1));
    $y = fn(float $v) => $padT + $plotH - ($max ? $v / $max * $plotH : 0);

    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="w-full h-auto" role="img" aria-labelledby="' . e($id) . '-title">';
    $svg .= '<title id="' . e($id) . '-title">Trend of ' . e(implode(', ', $series)) . '</title>';
    // grid + y ticks
    for ($t = 0; $t <= 4; $t++) {
        $v = $max * $t / 4;
        $yy = round($y($v), 1);
        $svg .= '<line x1="' . $padL . '" x2="' . ($w - $padR) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="#e2e8f0" stroke-width="1"/>';
        $svg .= '<text x="' . ($padL - 8) . '" y="' . ($yy + 4) . '" text-anchor="end" font-size="10" fill="#64748b">' . num($v) . '</text>';
    }
    // x labels (first, middle, last)
    foreach (array_unique([0, intdiv($n - 1, 2), $n - 1]) as $i) {
        $anchor = $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle');
        $svg .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 8) . '" text-anchor="' . $anchor . '" font-size="10" fill="#64748b">' . e($points[$i]['label']) . '</text>';
    }
    // series
    $ci = 0;
    $endLabels = [];
    foreach ($series as $k => $label) {
        $color = CHART_SERIES_COLORS[$ci++ % count(CHART_SERIES_COLORS)];
        $pts = [];
        foreach ($points as $i => $p) {
            $pts[] = round($x($i), 1) . ',' . round($y((float) ($p['values'][$k] ?? 0)), 1);
        }
        $svg .= '<polyline fill="none" stroke="' . $color . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="' . implode(' ', $pts) . '"/>';
        foreach ($points as $i => $p) {
            $val = (int) ($p['values'][$k] ?? 0);
            $cx = round($x($i), 1);
            $cy = round($y($val), 1);
            $isEnd = $i === $n - 1;
            $svg .= '<g class="group"><circle cx="' . $cx . '" cy="' . $cy . '" r="10" fill="transparent"/>'
                . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($isEnd ? 4.5 : 3.5) . '" fill="' . $color . '" stroke="#fff" stroke-width="2" class="' . ($isEnd ? '' : 'opacity-0 group-hover:opacity-100') . '"/>'
                . '<title>' . e($label . ' · ' . $p['label'] . ': ' . num($val)) . '</title></g>';
        }
        $last = (int) ($points[$n - 1]['values'][$k] ?? 0);
        $endLabels[] = [$y($last), $last];
    }
    // end labels, nudged apart to avoid collisions
    usort($endLabels, fn($a, $b) => $a[0] <=> $b[0]);
    $prevY = -INF;
    foreach ($endLabels as [$ly, $val]) {
        $ly = max($ly + 4, $prevY + 12);
        $prevY = $ly;
        $svg .= '<text x="' . ($w - $padR + 8) . '" y="' . round($ly, 1) . '" font-size="11" font-weight="600" fill="#0f172a">' . num($val) . '</text>';
    }
    $svg .= '</svg>';

    // legend
    $legend = '<div class="flex flex-wrap gap-x-4 gap-y-1 mb-2 text-xs text-slate-600">';
    $ci = 0;
    foreach ($series as $label) {
        $legend .= '<span class="inline-flex items-center gap-1.5"><span class="w-3 h-0.5 rounded-full inline-block" style="background:' . CHART_SERIES_COLORS[$ci++ % 3] . '"></span>' . e($label) . '</span>';
    }
    $legend .= '</div>';

    // table view
    $table = '<details class="mt-2 text-xs"><summary class="cursor-pointer text-slate-500 hover:text-slate-700">View as table</summary>'
        . '<div class="overflow-x-auto mt-2"><table class="table text-xs"><thead><tr><th>Date</th>';
    foreach ($series as $label) {
        $table .= '<th class="text-right">' . e($label) . '</th>';
    }
    $table .= '</tr></thead><tbody>';
    foreach ($points as $p) {
        $table .= '<tr><td>' . e($p['label']) . '</td>';
        foreach (array_keys($series) as $k) {
            $table .= '<td class="text-right font-mono">' . num($p['values'][$k] ?? 0) . '</td>';
        }
        $table .= '</tr>';
    }
    $table .= '</tbody></table></div></details>';

    return $legend . $svg . $table;
}

function nice_ceiling(float $v): float
{
    if ($v <= 0) {
        return 10;
    }
    $exp = 10 ** floor(log10($v));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($v <= $m * $exp) {
            return $m * $exp;
        }
    }
    return 10 * $exp;
}

/** Horizontal progress meter with label and value (single measure - no legend needed). */
function meter(string $label, int $pct, string $value = '', string $barClass = 'bg-sky-600'): string
{
    $pct = max(0, min(100, $pct));
    return '<div title="' . e($label . ': ' . $pct . '%') . '"><div class="flex justify-between text-xs mb-1"><span class="font-medium text-slate-600">' . e($label) . '</span>'
        . '<span class="font-semibold text-slate-900">' . e($value !== '' ? $value : $pct . '%') . '</span></div>'
        . '<div class="bar-track"><div class="h-2 rounded-full ' . $barClass . '" style="width:' . $pct . '%"></div></div></div>';
}

/** Six headline KPI tiles for an aggregate (state / district) row. */
function kpi_strip(array $t): void
{ ?>
  <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-4">
    <div class="kpi"><span class="kpi-label">Institutions</span><span class="kpi-value"><?= num($t['institutions']) ?></span><span class="kpi-note"><?= num($t['onboarded']) ?> onboarded · <?= $t['onboarded_pct'] ?>%</span></div>
    <div class="kpi"><span class="kpi-label">Campus Placed</span><span class="kpi-value text-teal-700"><?= num($t['campus_placed']) ?></span><span class="kpi-note">Last year: <?= num($t['campus_placed_prev']) ?></span></div>
    <div class="kpi"><span class="kpi-label">Final-Year Students</span><span class="kpi-value"><?= num($t['final_year']) ?></span><span class="kpi-note">Total eligible strength</span></div>
    <div class="kpi"><span class="kpi-label">Registered on DWMS</span><span class="kpi-value text-sky-600"><?= num($t['dwms_registered']) ?></span><span class="text-[11px] text-sky-600 font-semibold block mt-1"><?= $t['dwms_pct'] ?>% coverage</span></div>
    <div class="kpi"><span class="kpi-label">Immediate Job Seekers</span><span class="kpi-value text-emerald-600"><?= num($t['job_seekers']) ?></span><span class="text-[11px] text-emerald-600 font-semibold block mt-1"><?= $t['jobseeker_pct'] ?>% of batch</span></div>
    <div class="kpi"><span class="kpi-label">Gateway Completed</span><span class="kpi-value text-purple-600"><?= num($t['gateway_done']) ?></span><span class="text-[11px] text-purple-600 font-semibold block mt-1"><?= $t['gateway_pct'] ?>% of target</span></div>
    <div class="kpi"><span class="kpi-label">DWMS Services</span><span class="kpi-value text-amber-600"><?= num($t['services']) ?></span><span class="kpi-note"><?= num($t['beneficiaries']) ?> students benefited</span></div>
  </div>
<?php
}
