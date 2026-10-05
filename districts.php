<?php
/**
 * District master: the 14 districts are fixed; State / Admin maintain each district's
 * support team (TCE, Regional Programme Manager, Regional Head) shown on institution dashboards.
 */
require __DIR__ . '/app/bootstrap.php';
require_role('admin', 'state');

// Fields grouped by support role (role definitions: SUPPORT_ROLES in app/helpers.php)
$groups = [
    'tce' => ['tce_name' => 'Name', 'tce_email' => 'Email', 'tce_phone' => 'Phone'],
    'rpm' => ['rpm_name' => 'Name', 'rpm_email' => 'Email'],
    'rh'  => ['rh_name' => 'Name', 'rh_email' => 'Email'],
];
// RPM / Regional Head can be hidden by the super admin (Settings → Visibility); hidden fields are left untouched on save
$groups = array_intersect_key($groups, array_flip(visible_support_roles()));
$fields = [];
foreach ($groups as $role => $gf) {
    foreach ($gf as $k => $label) {
        $fields[$k] = SUPPORT_ROLES[$role]['short'] . ' ' . strtolower($label);
    }
}

if (is_post()) {
    verify_csrf();
    $id = (int) int_input('id', 0);
    $district = null;
    foreach (districts() as $d) {
        if ((int) $d['id'] === $id) $district = $d;
    }
    if (!$district) {
        forbidden('District not found.');
    }
    $data = [];
    foreach ($fields as $k => $label) {
        $data[$k] = nullable(mb_substr((string) input($k), 0, 160));
        if ($data[$k] && str_ends_with($k, '_email') && !filter_var($data[$k], FILTER_VALIDATE_EMAIL)) {
            flash('error', "$label is not a valid email.");
            redirect('districts', ['edit' => $id]);
        }
    }
    $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
    db()->prepare("UPDATE districts SET {$set} WHERE id = ?")->execute([...array_values($data), $id]);
    $changes = diff_changes($district, $data, $fields);
    if ($changes) {
        log_activity('update', 'district', $id, "Updated support team for {$district['name']}", $changes, null, $id);
    }
    flash('success', "{$district['name']} support team saved.");
    redirect('districts');
}

$editId = int_input('edit');
$pageTitle = 'Districts & Support Team';
require APP_ROOT . '/app/layout/header.php';
?>
<div class="mb-6">
  <nav class="text-xs text-slate-500 mb-2"><a class="link" href="<?= e(url('masters')) ?>">Masters</a> › Districts</nav>
  <h1 class="text-xl font-bold text-slate-900">Districts &amp; Support Team</h1>
  <p class="text-sm text-slate-500">The support team assigned to each district is shown on the dashboard of every institution in that district.</p>
</div>

<?php $roleColors = array_intersect_key(['tce' => 'bg-sky-100 text-sky-700', 'rpm' => 'bg-indigo-100 text-indigo-700', 'rh' => 'bg-purple-100 text-purple-700'], $groups); ?>
<div class="grid grid-cols-1 <?= grid_cols_class(count($roleColors), 'md:') ?> gap-4 mb-6">
  <?php foreach ($roleColors as $rk => $cls):
      $r = SUPPORT_ROLES[$rk]; ?>
    <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-xs flex items-start gap-3">
      <span class="px-2 py-1 rounded-lg text-[11px] font-bold shrink-0 <?= $cls ?>"><?= e($r['short'] === $r['full'] ? 'RH' : $r['short']) ?></span>
      <div>
        <p class="text-sm font-bold text-slate-900"><?= e($r['full']) ?></p>
        <p class="text-xs text-slate-500 mt-0.5"><?= e($r['purpose']) ?></p>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
  <?php foreach (districts() as $d): $editing = $editId === (int) $d['id']; ?>
    <div class="card <?= $editing ? 'border-sky-300 ring-2 ring-sky-100' : '' ?>">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-bold text-slate-900 flex items-center gap-2"><span class="text-[10px] font-mono bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded"><?= e($d['code']) ?></span> <?= e($d['name']) ?></h2>
        <?php if (!$editing): ?><a href="<?= e(url('districts', ['edit' => $d['id']])) ?>#d<?= $d['id'] ?>" class="btn-secondary btn-xs"><?= icon('pencil', 'w-3.5 h-3.5') ?> Edit</a><?php endif; ?>
      </div>
      <?php if ($editing): ?>
        <form method="post" id="d<?= $d['id'] ?>" class="space-y-4">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= $d['id'] ?>">
          <?php foreach ($groups as $role => $gf): $r = SUPPORT_ROLES[$role]; ?>
            <fieldset class="rounded-xl border border-slate-200 p-3">
              <legend class="px-1.5 text-xs font-bold text-slate-800">
                <?= e($r['full']) ?><?= $r['short'] !== $r['full'] ? ' <span class="font-semibold text-slate-400">(' . e($r['short']) . ')</span>' : '' ?>
                <?= role_tooltip($role) ?>
              </legend>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <?php $i = 0; foreach ($gf as $k => $label): ?>
                  <div>
                    <label class="label flex items-center gap-1" for="f-<?= $d['id'] ?>-<?= $k ?>">
                      <?= e($r['short']) ?> <?= e($label) ?> <?= role_tooltip($role, $i++ % 2 ? 'right' : 'left') ?>
                    </label>
                    <input class="input input-sm" id="f-<?= $d['id'] ?>-<?= $k ?>" name="<?= $k ?>" value="<?= e($d[$k]) ?>"
                           type="<?= str_ends_with($k, '_email') ? 'email' : (str_ends_with($k, '_phone') ? 'tel' : 'text') ?>"
                           placeholder="<?= e($r['full']) ?> <?= e(strtolower($label)) ?>">
                  </div>
                <?php endforeach; ?>
              </div>
            </fieldset>
          <?php endforeach; ?>
          <div class="flex justify-end gap-2 pt-1">
            <a href="<?= e(url('districts')) ?>" class="btn-secondary btn-xs">Cancel</a>
            <button class="btn-primary btn-xs">Save</button>
          </div>
        </form>
      <?php else: ?>
        <?php $summary = array_values(array_filter([['tce', $d['tce_name'], $d['tce_email']], ['rpm', $d['rpm_name'], $d['rpm_email']], ['rh', $d['rh_name'], $d['rh_email']]], fn($x) => isset($groups[$x[0]]))); ?>
        <dl class="grid <?= grid_cols_class(count($summary)) ?> gap-3 text-xs">
          <?php foreach ($summary as $si => [$role, $n, $em]): $align = $si === count($summary) - 1 && $si > 0 ? 'right' : 'left'; ?>
            <div><dt class="text-[10px] uppercase font-bold tracking-wider text-slate-400 flex items-center gap-1"><?= e(SUPPORT_ROLES[$role]['short']) ?> <?= role_tooltip($role, $align) ?></dt><dd class="font-semibold text-slate-800"><?= e($n ?: '—') ?></dd><dd class="text-slate-500 truncate"><?= e($em ?: '') ?></dd></div>
          <?php endforeach; ?>
        </dl>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
