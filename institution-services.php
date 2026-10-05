<?php
require __DIR__ . '/app/bootstrap.php';
[$inst, $m, $canEdit] = institution_context();
$activeTab = 'institution-services';
$instId = (int) $inst['id'];

const SERVICE_STATUSES = ['Requested', 'Scheduled', 'In Progress', 'Completed'];

$services = lookup('dwms_services');
$st = db()->prepare('SELECT * FROM institution_services WHERE institution_id = ?');
$st->execute([$instId]);
$availed = [];
foreach ($st->fetchAll() as $r) {
    $availed[(int) $r['service_id']] = $r;
}

if (is_post()) {
    verify_csrf();
    if (!$canEdit) {
        forbidden('You cannot edit this institution.');
    }
    $validIds = array_map('intval', array_column($services, 'id'));
    $selected = array_map('intval', (array) ($_POST['service'] ?? []));
    $selected = array_values(array_intersect($selected, $validIds));
    $details = (array) ($_POST['detail'] ?? []);
    $names = array_column($services, 'name', 'id');
    $uid = current_user()['id'];

    db()->beginTransaction();
    $changes = [];
    foreach ($availed as $sid => $row) {
        if (!in_array($sid, $selected, true) && in_array($sid, $validIds, true)) {
            db()->prepare('DELETE FROM institution_services WHERE id = ?')->execute([$row['id']]);
            $changes[$names[$sid]] = [$row['status'], 'removed'];
        }
    }
    $up = db()->prepare('INSERT INTO institution_services (institution_id, service_id, status, beneficiaries, availed_on, remarks, updated_by)
                         VALUES (?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE status = VALUES(status), beneficiaries = VALUES(beneficiaries),
                           availed_on = VALUES(availed_on), remarks = VALUES(remarks), updated_by = VALUES(updated_by)');
    foreach ($selected as $sid) {
        $d = (array) ($details[$sid] ?? []);
        $row = [
            'status'        => in_array($d['status'] ?? '', SERVICE_STATUSES, true) ? $d['status'] : 'Requested',
            'beneficiaries' => max(0, (int) ($d['beneficiaries'] ?? 0)),
            'availed_on'    => !empty($d['availed_on']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['availed_on']) ? $d['availed_on'] : null,
            'remarks'       => nullable(mb_substr((string) ($d['remarks'] ?? ''), 0, 500)),
        ];
        $up->execute([$instId, $sid, ...array_values($row), $uid]);
        $prev = $availed[$sid] ?? null;
        if (!$prev) {
            $changes[$names[$sid]] = [null, $row['status']];
        } else {
            foreach (diff_changes($prev, $row) as $k => $c) {
                $changes[$names[$sid] . ' ' . $k] = $c;
            }
        }
    }
    db()->commit();

    if ($changes) {
        log_activity('update', 'services', null, 'Updated DWMS services: ' . count($selected) . ' availed', $changes, $instId);
    }
    flash('success', 'DWMS services saved.');
    redirect(input('go') === 'next' ? 'institution-dashboard' : 'institution-services', ['id' => $instId]);
}

$pageTitle = $inst['name'] . ' · DWMS Services';
require APP_ROOT . '/app/layout/header.php';
require APP_ROOT . '/app/layout/institution_nav.php';
?>
<form method="post" class="card sm:p-8">
  <?= csrf_field() ?>
  <?php section_heading('briefcase', 'purple', 'Step 5: DWMS Available Services', 'Select the employability services and modules availed by the institution and track their progress.'); ?>
  <p class="text-xs text-slate-600 mb-4">All selected services are integrated into the institutional action plan and monitored by the assigned Talent Connect Executive (TCE).</p>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
    <?php foreach ($services as $s):
        $sid = (int) $s['id'];
        $a = $availed[$sid] ?? null; ?>
      <div class="rounded-xl border border-slate-200 hover:border-sky-300 transition-all" data-service-card>
        <label class="relative flex items-start p-4 cursor-pointer">
          <div class="flex items-center h-5">
            <input type="checkbox" name="service[]" value="<?= $sid ?>" <?= $a ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?> class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500">
          </div>
          <div class="ml-3 grow">
            <span class="block text-sm font-semibold text-slate-900"><?= e($s['name']) ?></span>
            <p class="text-xs text-slate-500 mt-0.5"><?= e($s['description']) ?></p>
            <div class="flex items-center gap-2 mt-2">
              <?php if ($s['tag']): ?><span class="inline-block text-[10px] font-semibold uppercase px-2 py-0.5 rounded <?= tag_classes($s['tag_color']) ?>"><?= e($s['tag']) ?></span><?php endif; ?>
              <?php if ($a): ?><?= status_badge($a['status']) ?><?php endif; ?>
            </div>
          </div>
        </label>
        <div class="hidden px-4 pb-4 grid grid-cols-2 gap-3" data-service-details>
          <div>
            <label class="label">Status</label>
            <select name="detail[<?= $sid ?>][status]" class="input input-sm" <?= $canEdit ? '' : 'disabled' ?>>
              <?php foreach (SERVICE_STATUSES as $opt): ?>
                <option <?= ($a['status'] ?? 'Requested') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label">Students benefited</label>
            <input type="number" min="0" name="detail[<?= $sid ?>][beneficiaries]" value="<?= e($a['beneficiaries'] ?? '') ?>" class="input input-sm font-mono" <?= $canEdit ? '' : 'disabled' ?>>
          </div>
          <div>
            <label class="label">Availed on</label>
            <input type="date" name="detail[<?= $sid ?>][availed_on]" value="<?= e($a['availed_on'] ?? '') ?>" class="input input-sm" <?= $canEdit ? '' : 'disabled' ?>>
          </div>
          <div>
            <label class="label">Remarks</label>
            <input name="detail[<?= $sid ?>][remarks]" value="<?= e($a['remarks'] ?? '') ?>" maxlength="500" class="input input-sm" <?= $canEdit ? '' : 'disabled' ?>>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($canEdit): ?>
  <div class="mt-8 pt-5 border-t border-slate-100 flex items-center justify-between gap-3">
    <a href="<?= e(url('institution-cohorts', ['id' => $instId])) ?>" class="btn-secondary">&larr; Back</a>
    <div class="flex gap-2">
      <button name="go" value="stay" class="btn-secondary">Save</button>
      <button name="go" value="next" class="btn-gradient px-7 py-3"><?= icon('check-circle') ?> Save &amp; View Dashboard</button>
    </div>
  </div>
  <?php endif; ?>
</form>
<?php require APP_ROOT . '/app/layout/footer.php';
