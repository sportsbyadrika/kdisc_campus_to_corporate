<?php
/**
 * Drill-down statistics: State  ->  14 districts  ->  institutions of a district.
 * Read-only and open to every signed-in user. Institution rows link to an institution
 * dashboard only when the viewer is allowed to open it.
 */
require __DIR__ . '/app/bootstrap.php';
$user = require_login();
$ay = academic_year();
$canExport = has_role('admin', 'state', 'district');

$districtId = int_input('district');
if ($districtId && !in_array($districtId, array_map('intval', array_column(districts(), 'id')), true)) {
    $districtId = null;
}
$level = (string) input('level', 'state');
if ($districtId) {
    $level = 'district';
} elseif (!in_array($level, ['state', 'districts'], true)) {
    $level = 'state';
}

$filters = [
    'university' => int_input('university'),
    'category'   => int_input('category'),
    'type'       => int_input('type'),
];
$where = ['1=1'];
$params = [];
if ($districtId) { $where[] = 'i.district_id = ?'; $params[] = $districtId; }
if ($filters['university']) { $where[] = 'i.university_id = ?'; $params[] = $filters['university']; }
if ($filters['category']) { $where[] = 'i.category_id = ?'; $params[] = $filters['category']; }
if ($filters['type']) { $where[] = 'i.type_id = ?'; $params[] = $filters['type']; }
$rows = institution_metrics(implode(' AND ', $where), $params, $ay);
$total = aggregate_metrics($rows);
$districtRows = $level === 'districts' ? district_rollup($rows) : [];
$districtName = $districtId ? district_name($districtId) : null;

// ---- CSV export of the current level --------------------------------------
if (input('export') === 'csv' && $canExport) {
    $fname = 'c2c-' . ($districtName ? strtolower($districtName) : $level) . '-' . $ay . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9.\-]/i', '-', $fname) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($level === 'district') {
        fputcsv($out, ['Institution', 'Code', 'Institution type', 'University', 'Category', 'Status', 'Profile %', 'DWMS Institution ID', 'Campus placed', 'Campus placed (last year)', 'Final-year', 'DWMS registered', 'DWMS %', 'Job seekers', 'Gateway completed', 'Gateway %', 'Gateway tests (vendor)', 'Services', 'Beneficiaries', 'Readiness score']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['name'], $r['code'], $r['type_name'], $r['university_short'], $r['category_name'], $r['status'], $r['profile_pct'], $r['dwms_id'], $r['campus_placed'], $r['campus_placed_prev'], $r['final_year'], $r['dwms_registered'], $r['dwms_pct'], $r['job_seekers'], $r['gateway_done'], $r['gateway_pct'], $r['has_vendor'] ? $r['gateway_vendor'] : '', $r['services'], $r['beneficiaries'], $r['score']]);
        }
    } else {
        fputcsv($out, ['District', 'Institutions', 'Onboarded', 'In progress', 'Not started', 'Campus placed', 'Campus placed (last year)', 'Final-year', 'DWMS registered', 'DWMS %', 'Job seekers', 'Gateway completed', 'Gateway %', 'Gateway tests (vendor)', 'Services', 'Beneficiaries', 'Avg readiness']);
        foreach ($level === 'districts' ? $districtRows : [['district' => ['name' => 'Kerala']] + $total] as $d) {
            fputcsv($out, [$d['district']['name'], $d['institutions'], $d['onboarded'], $d['in_progress'], $d['not_started'], $d['campus_placed'], $d['campus_placed_prev'], $d['final_year'], $d['dwms_registered'], $d['dwms_pct'], $d['job_seekers'], $d['gateway_done'], $d['gateway_pct'], $d['gateway_vendor'], $d['services'], $d['beneficiaries'], $d['avg_score']]);
        }
    }
    fclose($out);
    exit;
}

$universities = lookup('universities', false);
$categories = lookup('institution_categories', false);
$q = fn(array $extra = []) => array_merge(['university' => $filters['university'], 'category' => $filters['category'], 'type' => $filters['type']], $extra);
$maxFinal = max([1, ...array_map(fn($d) => $d['final_year'], $districtRows)]);

$pageTitle = 'Reports';
require APP_ROOT . '/app/layout/header.php';

?>
<div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4 mb-6">
  <div>
    <nav class="text-xs text-slate-500 mb-2 flex items-center gap-1.5">
      <a class="link" href="<?= e(url('reports', $q())) ?>">Kerala State</a>
      <?php if ($level !== 'state'): ?><?= icon('chevron-right', 'w-3 h-3') ?><a class="link" href="<?= e(url('reports', $q(['level' => 'districts']))) ?>">Districts</a><?php endif; ?>
      <?php if ($districtName): ?><?= icon('chevron-right', 'w-3 h-3') ?><span class="text-slate-700 font-medium"><?= e($districtName) ?></span><?php endif; ?>
    </nav>
    <h1 class="text-xl font-bold text-slate-900">
      <?= $level === 'state' ? 'State-wise Statistics' : ($level === 'districts' ? 'District-wise Statistics' : e($districtName) . ' — Institution-wise Statistics') ?>
    </h1>
    <p class="text-sm text-slate-500">Academic year <?= e($ay) ?> · <?= $level === 'district' ? ($user['role'] === 'institution' || $user['role'] === 'district' ? 'view only · institutions you manage open their dashboard' : 'click an institution to open its dashboard') : 'click to drill down' ?></p>
  </div>
  <form method="get" class="flex flex-wrap items-center gap-2 no-print">
    <?php if ($level === 'districts'): ?><input type="hidden" name="level" value="districts"><?php endif; ?>
    <?php if ($districtId): ?><input type="hidden" name="district" value="<?= $districtId ?>"><?php endif; ?>
    <select name="university" class="input input-sm w-auto" onchange="this.form.submit()">
      <option value="">All universities</option>
      <?php foreach ($universities as $u): ?><option value="<?= $u['id'] ?>" <?= $filters['university'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['short_name'] ?: $u['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="type" class="input input-sm w-auto" onchange="this.form.submit()"><?= institution_type_options($filters['type'], 'All institution types') ?></select>
    <select name="category" class="input input-sm w-auto" onchange="this.form.submit()">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= $filters['category'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
    <?php if ($canExport): ?><a href="<?= e(url('reports', $q(['level' => $level === 'district' ? null : $level, 'district' => $districtId, 'export' => 'csv']))) ?>" class="btn-secondary btn-xs"><?= icon('download', 'w-3.5 h-3.5') ?> CSV</a><?php endif; ?>
    <button type="button" onclick="window.print()" class="btn-secondary btn-xs" data-no-busy><?= icon('printer', 'w-3.5 h-3.5') ?> Print</button>
  </form>
</div>

<?php if ($level === 'state'): ?>
  <a href="<?= e(url('reports', $q(['level' => 'districts']))) ?>" class="block group">
    <div class="bg-linear-to-r from-slate-900 via-sky-950 to-indigo-950 rounded-2xl p-6 sm:p-8 text-white shadow-md relative overflow-hidden transition-transform group-hover:-translate-y-0.5">
      <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
        <div>
          <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wider bg-emerald-400/20 text-emerald-300 border border-emerald-400/30">State</span>
          <h2 class="text-3xl font-black mt-2">Kerala</h2>
          <p class="text-sm text-slate-300 mt-1">14 districts · <?= num($total['institutions']) ?> institutions · <?= num($total['final_year']) ?> final-year students</p>
          <span class="inline-flex items-center gap-1.5 mt-4 text-sm font-semibold text-sky-200 group-hover:text-white">View district-wise statistics <?= icon('arrow-right') ?></span>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-white/10 backdrop-blur-md border border-white/20 rounded-2xl p-4">
          <div class="text-center px-2"><span class="text-[10px] font-semibold uppercase tracking-wider text-sky-200 block">Onboarded</span><span class="text-2xl font-extrabold text-amber-400"><?= $total['onboarded_pct'] ?>%</span></div>
          <div class="text-center px-2"><span class="text-[10px] font-semibold uppercase tracking-wider text-sky-200 block">DWMS</span><span class="text-2xl font-extrabold"><?= $total['dwms_pct'] ?>%</span></div>
          <div class="text-center px-2"><span class="text-[10px] font-semibold uppercase tracking-wider text-sky-200 block">Gateway</span><span class="text-2xl font-extrabold text-emerald-300"><?= $total['gateway_pct'] ?>%</span></div>
          <div class="text-center px-2"><span class="text-[10px] font-semibold uppercase tracking-wider text-sky-200 block">Avg score</span><span class="text-2xl font-extrabold"><?= $total['avg_score'] ?></span></div>
        </div>
      </div>
    </div>
  </a>
  <div class="mt-6"><?php kpi_strip($total); ?></div>

  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
    <?php foreach ([['Onboarded', $total['onboarded'], 'bg-emerald-600'], ['In Progress', $total['in_progress'], 'bg-amber-500'], ['Not Started', $total['not_started'], 'bg-slate-400']] as [$lbl, $n, $cls]): ?>
      <div class="kpi"><?= meter($lbl . ' institutions', pct($n, $total['institutions']), num($n) . ' · ' . pct($n, $total['institutions']) . '%', $cls) ?></div>
    <?php endforeach; ?>
  </div>

<?php elseif ($level === 'districts'): ?>
  <?php kpi_strip($total); ?>
  <div class="card mt-6 p-0 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="table">
        <thead><tr>
          <th>District</th><th class="text-right">Institutions</th><th>Onboarded</th><th class="text-right">Campus placed</th><th class="text-right">Final-year</th>
          <th class="text-right">DWMS reg.</th><th class="text-right">Job seekers</th><th class="text-right">Gateway</th><th class="text-right">Services</th><th class="text-right">Avg score</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($districtRows as $d): $href = url('reports', $q(['district' => $d['district']['id']])); ?>
          <tr data-href="<?= e($href) ?>">
            <td class="font-semibold text-slate-900"><a href="<?= e($href) ?>" class="hover:text-sky-700"><?= e($d['district']['name']) ?></a></td>
            <td class="text-right font-mono"><?= num($d['institutions']) ?></td>
            <td class="min-w-36">
              <div class="flex items-center gap-2" title="<?= num($d['onboarded']) ?> of <?= num($d['institutions']) ?> onboarded">
                <div class="bar-track"><div class="h-2 rounded-full bg-emerald-600" style="width: <?= $d['onboarded_pct'] ?>%"></div></div>
                <span class="text-xs font-semibold w-10 text-right"><?= $d['onboarded_pct'] ?>%</span>
              </div>
            </td>
            <td class="text-right font-mono"><?= num($d['campus_placed']) ?><span class="block text-[11px] text-slate-400">last yr <?= num($d['campus_placed_prev']) ?></span></td>
            <td class="text-right font-mono">
              <span class="inline-flex items-center gap-2 justify-end"><span class="hidden xl:inline-block w-16 bar-track"><span class="block h-2 rounded-full bg-sky-600" style="width: <?= pct($d['final_year'], $maxFinal) ?>%"></span></span><?= num($d['final_year']) ?></span>
            </td>
            <td class="text-right font-mono"><?= num($d['dwms_registered']) ?> <span class="text-[11px] text-slate-400"><?= $d['dwms_pct'] ?>%</span></td>
            <td class="text-right font-mono"><?= num($d['job_seekers']) ?></td>
            <td class="text-right font-mono"><?= num($d['gateway_done']) ?> <span class="text-[11px] text-slate-400"><?= $d['gateway_pct'] ?>%</span><?= $d['gateway_vendor'] ? '<span class="block text-[11px] text-violet-600">vendor ' . num($d['gateway_vendor']) . '</span>' : '' ?></td>
            <td class="text-right font-mono"><?= num($d['services']) ?></td>
            <td class="text-right font-bold"><?= $d['institutions'] ? $d['avg_score'] : '—' ?></td>
            <td class="text-right text-slate-400"><?= icon('chevron-right') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td>Kerala</td><td class="text-right font-mono"><?= num($total['institutions']) ?></td><td><?= $total['onboarded_pct'] ?>%</td><td class="text-right font-mono"><?= num($total['campus_placed']) ?></td>
          <td class="text-right font-mono"><?= num($total['final_year']) ?></td><td class="text-right font-mono"><?= num($total['dwms_registered']) ?></td>
          <td class="text-right font-mono"><?= num($total['job_seekers']) ?></td><td class="text-right font-mono"><?= num($total['gateway_done']) ?></td>
          <td class="text-right font-mono"><?= num($total['services']) ?></td><td class="text-right"><?= $total['avg_score'] ?></td><td></td>
        </tr></tfoot>
      </table>
    </div>
  </div>

<?php else: ?>
  <?php kpi_strip($total); ?>
  <div class="card mt-6 p-0 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="table">
        <thead><tr>
          <th>Institution</th><th>University</th><th>Status</th><th class="text-right">Campus placed</th><th class="text-right">Final-year</th><th class="text-right">DWMS reg.</th>
          <th class="text-right">Job seekers</th><th class="text-right">Gateway</th><th class="text-right">Services</th><th class="text-right">Score</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $href = can_view_institution($r) ? url('institution-dashboard', ['id' => $r['id']]) : null; ?>
          <tr <?= $href ? 'data-href="' . e($href) . '"' : '' ?>>
            <td>
              <<?= $href ? 'a href="' . e($href) . '"' : 'div' ?> class="flex items-center gap-2.5 group">
                <?php if ($r['logo']): ?><img src="<?= e(upload_url($r['logo'])) ?>" alt="" class="w-8 h-8 rounded-lg object-contain border border-slate-200 bg-white shrink-0">
                <?php else: ?><span class="w-8 h-8 rounded-lg bg-sky-100 text-sky-700 text-[11px] font-bold flex items-center justify-center shrink-0"><?= e(initials($r['name'])) ?></span><?php endif; ?>
                <span class="min-w-0"><span class="block font-semibold text-slate-900 <?= $href ? 'group-hover:text-sky-700' : '' ?>"><?= e($r['name']) ?></span><span class="block text-[11px] text-slate-400"><?= e(implode(' · ', array_filter([$r['type_name'], $r['category_name']])) ?: '—') ?></span></span>
              </<?= $href ? 'a' : 'div' ?>>
            </td>
            <td class="text-xs"><?= e($r['university_short'] ?: '—') ?></td>
            <td><?= status_badge($r['status']) ?><span class="block text-[11px] text-slate-400 mt-0.5"><?= $r['profile_pct'] ?>% complete</span></td>
            <td class="text-right font-mono"><?= $r['campus_placed'] === null ? '<span class="text-slate-300">—</span>' : num($r['campus_placed']) ?><span class="block text-[11px] text-slate-400">last yr <?= $r['campus_placed_prev'] === null ? '—' : num($r['campus_placed_prev']) ?></span></td>
            <td class="text-right font-mono"><?= num($r['final_year']) ?></td>
            <td class="text-right font-mono"><?= num($r['dwms_registered']) ?> <span class="text-[11px] text-slate-400"><?= $r['dwms_pct'] ?>%</span></td>
            <td class="text-right font-mono"><?= num($r['job_seekers']) ?></td>
            <td class="text-right font-mono"><?= num($r['gateway_done']) ?> <span class="text-[11px] text-slate-400"><?= $r['gateway_pct'] ?>%</span><?= $r['has_vendor'] ? '<span class="block text-[11px] text-violet-600">vendor ' . num($r['gateway_vendor']) . '</span>' : '' ?></td>
            <td class="text-right font-mono"><?= num($r['services']) ?></td>
            <td class="text-right font-bold"><?= $r['score'] ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-slate-400 py-10">No institutions in this district match the filters.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php require APP_ROOT . '/app/layout/footer.php';
