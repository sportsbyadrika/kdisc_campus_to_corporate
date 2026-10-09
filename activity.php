<?php
/** Audit trail of changes made in the field. */
require __DIR__ . '/app/bootstrap.php';
$user = require_role('admin', 'state', 'district');

$where = ['1=1'];
$params = [];
if ($user['role'] === 'district') {
    $where[] = 'l.district_id = ?';
    $params[] = (int) $user['district_id'];
}
if ($user['role'] !== 'superadmin') {
    $where[] = "(u.role IS NULL OR u.role <> 'superadmin' OR l.institution_id IS NOT NULL)";
}
$f = ['institution' => int_input('institution'), 'district' => int_input('district'), 'entity' => (string) input('entity'), 'from' => (string) input('from'), 'to' => (string) input('to')];
if ($f['institution']) { $where[] = 'l.institution_id = ?'; $params[] = $f['institution']; }
if ($f['district'] && is_state_level()) { $where[] = 'l.district_id = ?'; $params[] = $f['district']; }
if ($f['entity'] !== '') { $where[] = 'l.entity = ?'; $params[] = $f['entity']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) { $where[] = 'l.created_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) { $where[] = 'l.created_at <= ?'; $params[] = $f['to'] . ' 23:59:59'; }

$perPage = 50;
$pageNo = max(1, (int) int_input('page', 1));
$from = 'FROM activity_log l LEFT JOIN users u ON u.id = l.user_id LEFT JOIN institutions i ON i.id = l.institution_id LEFT JOIN districts d ON d.id = l.district_id WHERE ' . implode(' AND ', $where);
$st = db()->prepare("SELECT COUNT(*) $from");
$st->execute($params);
$total = (int) $st->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$offset = ($pageNo - 1) * $perPage;
$st = db()->prepare('SELECT l.*, ' . user_name_sql('u') . " AS user_name, i.name AS institution_name, d.name AS district_name $from ORDER BY l.id DESC LIMIT $perPage OFFSET $offset");
$st->execute($params);
$logs = $st->fetchAll();

$instName = null;
if ($f['institution']) {
    $s = db()->prepare('SELECT name FROM institutions WHERE id = ?');
    $s->execute([$f['institution']]);
    $instName = $s->fetchColumn();
}
$entities = ['institution' => 'Institution profile', 'officer' => 'Placement officers', 'departments' => 'Student strength', 'cohort' => 'Cohorts & assessments',
    'services' => 'DWMS services', 'vendor_counts' => 'Vendor test counts', 'vendor_upload' => 'Vendor test uploads', 'request' => 'Institution requests', 'user' => 'Users', 'district' => 'Districts',
    'universities' => 'Universities', 'categories' => 'Categories', 'courses' => 'Courses', 'assessments' => 'Assessment tests', 'settings' => 'Settings'];
$actionColors = ['create' => 'bg-emerald-100 text-emerald-700', 'update' => 'bg-sky-100 text-sky-700', 'delete' => 'bg-rose-100 text-rose-700',
    'approve' => 'bg-emerald-100 text-emerald-700', 'reject' => 'bg-rose-100 text-rose-700'];

function change_value(mixed $v): string
{
    if ($v === null || $v === '' || !is_scalar($v)) {
        return '<span class="text-slate-300">—</span>';
    }
    return e(mb_strimwidth((string) $v, 0, 60, '…'));
}

$pageTitle = 'Activity';
require APP_ROOT . '/app/layout/header.php';
?>
<div class="mb-6">
  <h1 class="text-xl font-bold text-slate-900">Activity &amp; Change Log</h1>
  <p class="text-sm text-slate-500"><?= $instName ? 'Changes for ' . e($instName) : 'Every change recorded in the field' ?> · <?= num($total) ?> entries</p>
</div>

<form method="get" class="card p-4 mb-4 flex flex-wrap items-end gap-2">
  <?php if ($f['institution']): ?><input type="hidden" name="institution" value="<?= $f['institution'] ?>"><?php endif; ?>
  <?php if (is_state_level()): ?>
  <div><label class="label">District</label><select name="district" class="input input-sm w-auto">
    <option value="">All</option>
    <?php foreach (districts() as $d): ?><option value="<?= $d['id'] ?>" <?= $f['district'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
  </select></div>
  <?php endif; ?>
  <div><label class="label">Area</label><select name="entity" class="input input-sm w-auto">
    <option value="">All</option>
    <?php foreach ($entities as $k => $lbl): ?><option value="<?= $k ?>" <?= $f['entity'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
  </select></div>
  <div><label class="label">From</label><input type="date" name="from" value="<?= e($f['from']) ?>" class="input input-sm"></div>
  <div><label class="label">To</label><input type="date" name="to" value="<?= e($f['to']) ?>" class="input input-sm"></div>
  <button class="btn-secondary btn-xs" data-no-busy>Filter</button>
  <?php if (array_filter($f)): ?><a href="<?= e(url('activity')) ?>" class="text-xs link pb-2">Clear</a><?php endif; ?>
</form>

<div class="card p-0 overflow-hidden">
  <ul class="divide-y divide-slate-100">
    <?php foreach ($logs as $l):
        $changes = $l['changes'] ? json_decode($l['changes'], true) : []; ?>
      <li class="px-5 py-3.5 text-sm">
        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-1">
          <div class="flex items-start gap-3 min-w-0">
            <span class="mt-0.5 px-2 py-0.5 rounded text-[10px] font-bold uppercase shrink-0 <?= $actionColors[$l['action']] ?? 'bg-slate-100 text-slate-600' ?>"><?= e($l['action']) ?></span>
            <div class="min-w-0">
              <p><span class="font-semibold text-slate-900"><?= e($l['user_name'] ?? 'System') ?></span> <span class="text-slate-700"><?= e($l['summary']) ?></span></p>
              <p class="text-xs text-slate-400">
                <?php if ($l['institution_name']): ?><a class="link" href="<?= e(url('institution-dashboard', ['id' => $l['institution_id']])) ?>"><?= e($l['institution_name']) ?></a> · <?php endif; ?>
                <?= e($l['district_name'] ?? '') ?><?= $l['district_name'] ? ' · ' : '' ?><?= e($entities[$l['entity']] ?? $l['entity']) ?>
              </p>
              <?php if ($changes): ?>
                <details class="mt-1.5 text-xs">
                  <summary class="cursor-pointer text-slate-500 hover:text-slate-700"><?= count($changes) ?> field<?= count($changes) > 1 ? 's' : '' ?> changed</summary>
                  <table class="mt-2 text-xs">
                    <?php foreach ($changes as $field => [$o, $n]): ?>
                      <tr><td class="pr-4 py-0.5 text-slate-500"><?= e(str_replace('_', ' ', $field)) ?></td><td class="pr-2 py-0.5 text-rose-600 line-through"><?= change_value($o) ?></td><td class="py-0.5 text-emerald-700"><?= change_value($n) ?></td></tr>
                    <?php endforeach; ?>
                  </table>
                </details>
              <?php endif; ?>
            </div>
          </div>
          <span class="text-xs text-slate-400 shrink-0 sm:pl-4" title="<?= e($l['created_at']) ?>"><?= e(date('d M Y, h:i A', strtotime($l['created_at']))) ?></span>
        </div>
      </li>
    <?php endforeach; ?>
    <?php if (!$logs): ?><li class="px-5 py-10 text-center text-sm text-slate-400">No activity recorded.</li><?php endif; ?>
  </ul>
</div>

<?php if ($pages > 1): ?>
<div class="flex justify-between items-center mt-4 text-sm">
  <span class="text-slate-500">Page <?= $pageNo ?> of <?= $pages ?></span>
  <div class="flex gap-2">
    <?php if ($pageNo > 1): ?><a class="btn-secondary btn-xs" href="<?= e(url('activity', array_merge($f, ['page' => $pageNo - 1]))) ?>">&larr; Newer</a><?php endif; ?>
    <?php if ($pageNo < $pages): ?><a class="btn-secondary btn-xs" href="<?= e(url('activity', array_merge($f, ['page' => $pageNo + 1]))) ?>">Older &rarr;</a><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/app/layout/footer.php';
