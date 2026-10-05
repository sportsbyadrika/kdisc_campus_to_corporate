<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();
$ay = academic_year();

// Institution users land on their institution dashboard
if ($user['role'] === 'institution') {
    $ids = assigned_institution_ids();
    if (count($ids) === 1) {
        redirect('institution-dashboard', ['id' => $ids[0]]);
    }
}

[$scopeSql, $scopeParams] = institution_scope('i');
$rows = institution_metrics($scopeSql, $scopeParams, $ay);
$total = aggregate_metrics($rows);

$pending = 0;
if (has_role('admin', 'state', 'district')) {
    $sql = 'SELECT COUNT(*) FROM institution_requests WHERE status = \'pending\'' . ($user['role'] === 'district' ? ' AND district_id = ?' : '');
    $st = db()->prepare($sql);
    $st->execute($user['role'] === 'district' ? [(int) $user['district_id']] : []);
    $pending = (int) $st->fetchColumn();
}

$activity = [];
if (has_role('admin', 'state', 'district')) {
    $sql = 'SELECT l.*, ' . user_name_sql('u') . ' AS user_name, i.name AS institution_name FROM activity_log l
            LEFT JOIN users u ON u.id = l.user_id LEFT JOIN institutions i ON i.id = l.institution_id'
        . ($user['role'] === 'district' ? ' WHERE l.district_id = ?' : '') . ' ORDER BY l.id DESC LIMIT 10';
    $st = db()->prepare($sql);
    $st->execute($user['role'] === 'district' ? [(int) $user['district_id']] : []);
    $activity = $st->fetchAll();
}

$pageTitle = 'Dashboard';
require APP_ROOT . '/app/layout/header.php';
$hello = (int) date('G') < 12 ? 'Good morning' : ((int) date('G') < 17 ? 'Good afternoon' : 'Good evening');
?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6">
  <div>
    <h1 class="text-xl font-bold text-slate-900"><?= e($hello) ?>, <?= e(strtok($user['name'], ' ')) ?></h1>
    <p class="text-sm text-slate-500">
      <?php if ($user['role'] === 'district'): ?><?= e(district_name((int) $user['district_id'])) ?> district overview
      <?php elseif ($user['role'] === 'institution'): ?>Your institutions
      <?php else: ?>Kerala state overview<?php endif; ?> · Academic year <?= e($ay) ?>
    </p>
  </div>
  <div class="flex gap-2 flex-wrap">
    <?php if ($user['role'] === 'district'): ?>
      <a href="<?= e(url('reports')) ?>" class="btn-primary"><?= icon('chart') ?> District report</a>
      <a href="<?= e(url('users', ['new' => 1])) ?>" class="btn-secondary"><?= icon('plus') ?> Institution user</a>
    <?php elseif (has_role('admin', 'state')): ?>
      <a href="<?= e(url('reports')) ?>" class="btn-primary"><?= icon('chart') ?> Drill-down report</a>
      <a href="<?= e(url('institutions', ['new' => 1])) ?>" class="btn-secondary"><?= icon('plus') ?> Add institution</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($user['role'] === 'institution'): ?>
  <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
    <?php foreach ($rows as $r): ?>
      <a href="<?= e(url('institution-dashboard', ['id' => $r['id']])) ?>" class="card hover:border-sky-300 transition-colors block">
        <div class="flex items-center gap-3 mb-4">
          <?php if ($r['logo']): ?><img src="<?= e(upload_url($r['logo'])) ?>" alt="" class="w-11 h-11 rounded-xl object-contain border border-slate-200">
          <?php else: ?><div class="w-11 h-11 rounded-xl bg-sky-100 text-sky-700 font-bold flex items-center justify-center"><?= e(initials($r['name'])) ?></div><?php endif; ?>
          <div class="min-w-0"><p class="font-bold text-slate-900 truncate"><?= e($r['name']) ?></p><p class="text-xs text-slate-500"><?= e($r['district_name']) ?> · <?= e($r['university_short'] ?: '—') ?></p></div>
        </div>
        <?= meter('Onboarding', $r['profile_pct'], '', 'bg-emerald-600') ?>
        <div class="flex justify-between items-center mt-4"><?= status_badge($r['status']) ?><span class="text-xs font-semibold text-sky-700">Open dashboard →</span></div>
      </a>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <div class="card md:col-span-3 text-center py-12">
        <p class="font-semibold text-slate-800">No institution is assigned to your account yet.</p>
        <p class="text-sm text-slate-500 mt-1">Please contact your district coordinator.</p>
      </div>
    <?php endif; ?>
  </div>
<?php else: ?>

  <?php kpi_strip($total); ?>

  <?php if ($pending): ?>
    <a href="<?= e(url('institution-requests')) ?>" class="mt-6 p-4 bg-indigo-50 border border-indigo-200 rounded-2xl flex items-center justify-between gap-3 hover:bg-indigo-100 transition-colors">
      <span class="flex items-center gap-3 text-sm text-indigo-900"><?= icon('inbox', 'w-5 h-5') ?> <strong><?= $pending ?></strong> new institution request<?= $pending > 1 ? 's' : '' ?> <?= $user['role'] === 'district' ? 'awaiting State approval' : 'awaiting your review' ?></span>
      <?= icon('chevron-right') ?>
    </a>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
    <div class="card lg:col-span-2">
      <?php if ($user['role'] === 'district'):
          $attention = array_filter($rows, fn($r) => $r['status'] !== 'Onboarded' && $r['is_active']);
          usort($attention, fn($a, $b) => $a['profile_pct'] <=> $b['profile_pct']); ?>
        <div class="flex items-center justify-between mb-4">
          <div><h3 class="card-title">Institutions needing attention</h3><p class="card-subtitle">Lowest onboarding completion first</p></div>
          <a href="<?= e(url('institutions')) ?>" class="text-xs link">All institutions</a>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead><tr><th>Institution</th><th>Status</th><th class="min-w-40">Completion</th><th class="text-right">Score</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($attention, 0, 8) as $r): ?>
                <tr data-href="<?= e(url('institution-dashboard', ['id' => $r['id']])) ?>">
                  <td class="font-medium text-slate-900"><?= e($r['name']) ?></td>
                  <td><?= status_badge($r['status']) ?></td>
                  <td><div class="flex items-center gap-2"><div class="bar-track"><div class="h-2 rounded-full bg-emerald-600" style="width: <?= $r['profile_pct'] ?>%"></div></div><span class="text-xs font-semibold w-9 text-right"><?= $r['profile_pct'] ?>%</span></div></td>
                  <td class="text-right font-bold"><?= $r['score'] ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$attention): ?><tr><td colspan="4" class="text-center text-slate-400 py-8"><?= $rows ? 'All institutions are fully onboarded. 🎉' : 'No institutions in your district yet.' ?></td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      <?php else:
          $byDistrict = district_rollup($rows); ?>
        <div class="flex items-center justify-between mb-4">
          <div><h3 class="card-title">Onboarding by district</h3><p class="card-subtitle">Share of institutions fully onboarded</p></div>
          <a href="<?= e(url('reports', ['level' => 'districts'])) ?>" class="text-xs link">District report</a>
        </div>
        <ul class="space-y-2.5">
          <?php foreach ($byDistrict as $d): ?>
            <li>
              <a href="<?= e(url('reports', ['district' => $d['district']['id']])) ?>" class="grid grid-cols-[8.5rem_1fr_6.5rem] items-center gap-3 group" title="<?= e($d['district']['name']) ?>: <?= num($d['onboarded']) ?> of <?= num($d['institutions']) ?> onboarded">
                <span class="text-xs font-medium text-slate-700 group-hover:text-sky-700 truncate"><?= e($d['district']['name']) ?></span>
                <span class="bar-track"><span class="block h-2 rounded-full bg-emerald-600" style="width: <?= $d['onboarded_pct'] ?>%"></span></span>
                <span class="text-xs text-right"><span class="font-semibold text-slate-900"><?= $d['onboarded_pct'] ?>%</span> <span class="text-slate-400"><?= num($d['onboarded']) ?>/<?= num($d['institutions']) ?></span></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="flex items-center justify-between mb-3">
        <h3 class="card-title">Recent activity</h3>
        <a href="<?= e(url('activity')) ?>" class="text-xs link">View all</a>
      </div>
      <ul class="divide-y divide-slate-100 text-xs">
        <?php foreach ($activity as $a): ?>
          <li class="py-2.5">
            <p><span class="font-semibold text-slate-800"><?= e($a['user_name'] ?? 'System') ?></span> <span class="text-slate-600"><?= e($a['summary']) ?></span></p>
            <p class="text-slate-400 mt-0.5"><?= $a['institution_name'] ? e($a['institution_name']) . ' · ' : '' ?><?= e(time_ago($a['created_at'])) ?></p>
          </li>
        <?php endforeach; ?>
        <?php if (!$activity): ?><li class="py-2 text-slate-400 italic">No activity yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>
<?php require APP_ROOT . '/app/layout/footer.php';
