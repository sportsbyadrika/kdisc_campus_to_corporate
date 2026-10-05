<?php
/**
 * Global masters maintained by the State user / Administrator.
 */
require __DIR__ . '/app/bootstrap.php';
require_role('admin', 'state');

$universityOptions = ['' => 'All universities (global)'];
foreach (lookup('universities', false) as $u) {
    $universityOptions[$u['id']] = $u['name'] . ($u['short_name'] ? " ({$u['short_name']})" : '');
}

$MASTERS = [
    'universities' => [
        'title' => 'Affiliated Universities', 'table' => 'universities', 'singular' => 'university', 'order' => 'name',
        'fields' => [
            'name'       => ['label' => 'University name', 'type' => 'text', 'required' => true],
            'short_name' => ['label' => 'Short name', 'type' => 'text', 'placeholder' => 'e.g. KTU'],
        ],
        'usage' => 'SELECT COUNT(*) FROM institutions WHERE university_id = ?',
    ],
    'categories' => [
        'title' => 'Institution Categories', 'table' => 'institution_categories', 'singular' => 'category', 'order' => 'name',
        'fields' => ['name' => ['label' => 'Category name', 'type' => 'text', 'required' => true]],
        'usage' => 'SELECT COUNT(*) FROM institutions WHERE category_id = ?',
    ],
    'courses' => [
        'title' => 'Courses / Streams', 'table' => 'courses', 'singular' => 'course', 'order' => 'level, name',
        'fields' => [
            'name'  => ['label' => 'Course name', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g. B.Tech'],
            'level' => ['label' => 'Level', 'type' => 'select', 'options' => ['UG' => 'UG', 'PG' => 'PG', 'Diploma' => 'Diploma', 'Other' => 'Other']],
        ],
        'usage' => 'SELECT COUNT(*) FROM institution_departments WHERE course_id = ?',
    ],
    'assessments' => [
        'title' => 'Assessment Tests', 'table' => 'assessment_tests', 'singular' => 'assessment test', 'order' => 'university_id IS NOT NULL, university_id, name',
        'fields' => [
            'name'          => ['label' => 'Test name', 'type' => 'text', 'required' => true],
            'university_id' => ['label' => 'University', 'type' => 'select', 'options' => $universityOptions, 'nullable' => true],
            'description'   => ['label' => 'Description', 'type' => 'text'],
            'is_mandatory'  => ['label' => 'Mandatory gateway test', 'type' => 'checkbox'],
        ],
        'usage' => 'SELECT COUNT(*) FROM institution_assessments WHERE assessment_test_id = ?',
        'help' => 'Tests are offered to institutions based on their affiliated university. Leave the university blank for tests that apply to every institution. The mandatory gateway test drives the readiness score.',
    ],
    'services' => [
        'title' => 'DWMS Services', 'table' => 'dwms_services', 'singular' => 'service', 'order' => 'sort_order, name',
        'fields' => [
            'name'        => ['label' => 'Service name', 'type' => 'text', 'required' => true],
            'description' => ['label' => 'Description', 'type' => 'text'],
            'tag'         => ['label' => 'Tag', 'type' => 'text', 'placeholder' => 'e.g. High Impact'],
            'tag_color'   => ['label' => 'Tag colour', 'type' => 'select', 'options' => ['sky' => 'Sky', 'emerald' => 'Emerald', 'indigo' => 'Indigo', 'purple' => 'Purple', 'amber' => 'Amber', 'rose' => 'Rose', 'slate' => 'Slate']],
            'sort_order'  => ['label' => 'Order', 'type' => 'number'],
        ],
        'usage' => 'SELECT COUNT(*) FROM institution_services WHERE service_id = ?',
    ],
];

$type = (string) input('type', 'universities');
if (!isset($MASTERS[$type])) {
    $type = 'universities';
}
$cfg = $MASTERS[$type];
$table = $cfg['table'];

if (is_post()) {
    verify_csrf();
    $action = input('action');
    $id = int_input('id');
    $row = null;
    if ($id) {
        $st = db()->prepare("SELECT * FROM {$table} WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            flash('error', 'Record not found.');
            redirect('masters', ['type' => $type]);
        }
    }

    if ($action === 'toggle' && $row) {
        db()->prepare("UPDATE {$table} SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
        log_activity('update', $type, $id, ($row['is_active'] ? 'Deactivated ' : 'Activated ') . "{$cfg['singular']} {$row['name']}");
        flash('success', 'Status updated.');
        redirect('masters', ['type' => $type]);
    }
    if ($action === 'delete' && $row) {
        $st = db()->prepare($cfg['usage']);
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            flash('error', "“{$row['name']}” is in use and cannot be deleted. Deactivate it instead.");
        } else {
            db()->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
            log_activity('delete', $type, $id, "Deleted {$cfg['singular']} {$row['name']}");
            flash('success', 'Deleted.');
        }
        redirect('masters', ['type' => $type]);
    }

    $data = [];
    $errors = [];
    foreach ($cfg['fields'] as $key => $fd) {
        $val = $fd['type'] === 'checkbox' ? (input($key) === '1' ? 1 : 0) : input($key);
        if ($fd['type'] === 'number') $val = (int) $val;
        if ($fd['type'] === 'select' && $val !== '' && !array_key_exists($val, $fd['options'])) $errors[] = "Invalid {$fd['label']}.";
        if (is_string($val)) $val = mb_substr($val, 0, $key === 'description' ? 500 : 200);
        if (($fd['nullable'] ?? false) && $val === '') $val = null;
        if (!empty($fd['required']) && ($val === '' || $val === null)) $errors[] = "{$fd['label']} is required.";
        if (is_string($val) && $val === '' && $fd['type'] === 'text') $val = null;
        $data[$key] = $val;
    }
    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
        redirect('masters', ['type' => $type, 'edit' => $id]);
    }
    try {
        if ($row) {
            $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
            db()->prepare("UPDATE {$table} SET {$set} WHERE id = ?")->execute([...array_values($data), $id]);
            log_activity('update', $type, $id, "Updated {$cfg['singular']} {$data['name']}", diff_changes($row, $data));
        } else {
            $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
            db()->prepare("INSERT INTO {$table} ({$cols}) VALUES (" . implode(',', array_fill(0, count($data), '?')) . ')')->execute(array_values($data));
            log_activity('create', $type, (int) db()->lastInsertId(), "Added {$cfg['singular']} {$data['name']}");
        }
        flash('success', ucfirst($cfg['singular']) . ' saved.');
    } catch (PDOException $ex) {
        flash('error', $ex->getCode() === '23000' ? 'A record with this name already exists.' : 'Could not save the record.');
    }
    redirect('masters', ['type' => $type]);
}

$rows = db()->query("SELECT * FROM {$table} ORDER BY {$cfg['order']}")->fetchAll();
$usage = [];
$st = db()->prepare($cfg['usage']);
foreach ($rows as $r) {
    $st->execute([$r['id']]);
    $usage[$r['id']] = (int) $st->fetchColumn();
}
$editId = int_input('edit');
$editing = null;
foreach ($rows as $r) {
    if ((int) $r['id'] === $editId) $editing = $r;
}

$pageTitle = 'Masters · ' . $cfg['title'];
require APP_ROOT . '/app/layout/header.php';
?>
<div class="mb-6">
  <h1 class="text-xl font-bold text-slate-900">Masters</h1>
  <p class="text-sm text-slate-500">Global reference data used by every institution across the state.</p>
</div>

<div class="flex flex-wrap gap-2 mb-6">
  <?php foreach ($MASTERS as $k => $mcfg): ?>
    <a href="<?= e(url('masters', ['type' => $k])) ?>" class="px-3.5 py-2 rounded-xl text-sm font-medium border transition-colors <?= $k === $type ? 'border-sky-600 bg-sky-50 text-sky-800' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' ?>"><?= e($mcfg['title']) ?></a>
  <?php endforeach; ?>
  <a href="<?= e(url('districts')) ?>" class="px-3.5 py-2 rounded-xl text-sm font-medium border border-slate-200 bg-white text-slate-600 hover:bg-slate-50">Districts &amp; Support Team</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <form method="post" class="card space-y-4 self-start">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
    <h2 class="card-title"><?= $editing ? 'Edit ' . e($cfg['singular']) : 'Add ' . e($cfg['singular']) ?></h2>
    <?php if (!empty($cfg['help'])): ?><p class="text-xs text-slate-500 -mt-2"><?= e($cfg['help']) ?></p><?php endif; ?>
    <?php foreach ($cfg['fields'] as $key => $fd):
        $val = $editing[$key] ?? ''; ?>
      <?php if ($fd['type'] === 'checkbox'): ?>
        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="<?= $key ?>" value="1" class="h-4 w-4 rounded border-slate-300 text-sky-600" <?= $val ? 'checked' : '' ?>> <?= e($fd['label']) ?></label>
      <?php elseif ($fd['type'] === 'select'): ?>
        <div><label class="label"><?= e($fd['label']) ?></label>
          <select name="<?= $key ?>" class="input">
            <?php foreach ($fd['options'] as $ov => $ol): ?><option value="<?= e($ov) ?>" <?= (string) $val === (string) $ov ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?>
          </select></div>
      <?php else: ?>
        <div><label class="label"><?= e($fd['label']) ?> <?= !empty($fd['required']) ? '<span class="req">*</span>' : '' ?></label>
          <input name="<?= $key ?>" type="<?= $fd['type'] === 'number' ? 'number' : 'text' ?>" value="<?= e($val) ?>" class="input" placeholder="<?= e($fd['placeholder'] ?? '') ?>" <?= !empty($fd['required']) ? 'required' : '' ?>></div>
      <?php endif; ?>
    <?php endforeach; ?>
    <div class="pt-4 border-t border-slate-100 flex justify-end gap-2">
      <?php if ($editing): ?><a href="<?= e(url('masters', ['type' => $type])) ?>" class="btn-secondary">Cancel</a><?php endif; ?>
      <button class="btn-primary"><?= $editing ? 'Save' : 'Add' ?></button>
    </div>
  </form>

  <div class="card p-0 overflow-hidden lg:col-span-2">
    <div class="px-6 pt-5 pb-3"><h2 class="card-title"><?= e($cfg['title']) ?></h2><p class="card-subtitle"><?= count($rows) ?> records</p></div>
    <div class="overflow-x-auto">
      <table class="table">
        <thead><tr>
          <?php foreach ($cfg['fields'] as $key => $fd): if ($key === 'description') continue; ?><th><?= e($fd['label']) ?></th><?php endforeach; ?>
          <th class="text-right">In use</th><th>Status</th><th class="text-right">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= $r['is_active'] ? '' : 'opacity-60' ?>">
            <?php foreach ($cfg['fields'] as $key => $fd): if ($key === 'description') continue; ?>
              <td class="<?= $key === 'name' ? 'font-medium text-slate-900' : 'text-xs' ?>">
                <?php if ($fd['type'] === 'checkbox'): ?><?= $r[$key] ? '<span class="text-emerald-700 font-semibold">Yes</span>' : '—' ?>
                <?php elseif ($key === 'tag_color'): ?><span class="px-2 py-0.5 rounded text-[10px] font-semibold uppercase <?= tag_classes($r[$key]) ?>"><?= e($r['tag'] ?: $r[$key]) ?></span>
                <?php elseif ($fd['type'] === 'select'): ?><?= e($fd['options'][$r[$key] ?? ''] ?? '—') ?>
                <?php else: ?><?= e($r[$key] ?? '—') ?><?php endif; ?>
                <?php if ($key === 'name' && !empty($r['description'])): ?><span class="block text-[11px] text-slate-400 font-normal"><?= e($r['description']) ?></span><?php endif; ?>
              </td>
            <?php endforeach; ?>
            <td class="text-right font-mono text-xs"><?= num($usage[$r['id']]) ?></td>
            <td><?= status_badge($r['is_active'] ? 'Active' : 'Inactive') ?></td>
            <td class="text-right whitespace-nowrap">
              <a href="<?= e(url('masters', ['type' => $type, 'edit' => $r['id']])) ?>" class="inline-flex p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 align-middle" title="Edit"><?= icon('pencil') ?></a>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button name="action" value="toggle" class="p-1.5 rounded-lg align-middle text-slate-500 hover:bg-slate-100" title="<?= $r['is_active'] ? 'Deactivate' : 'Activate' ?>"><?= icon($r['is_active'] ? 'x' : 'check') ?></button>
                <?php if (!$usage[$r['id']]): ?><button name="action" value="delete" class="p-1.5 rounded-lg align-middle text-rose-500 hover:bg-rose-50" title="Delete" data-confirm="Delete <?= e($r['name']) ?>?"><?= icon('trash') ?></button><?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
