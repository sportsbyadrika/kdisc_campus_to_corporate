<?php
require __DIR__ . '/app/bootstrap.php';
[$inst, $m, $canEdit] = institution_context();
$activeTab = 'institution-officers';
$instId = (int) $inst['id'];

$fetchOfficer = function (int $oid) use ($instId): ?array {
    $st = db()->prepare('SELECT * FROM institution_officers WHERE id = ? AND institution_id = ?');
    $st->execute([$oid, $instId]);
    return $st->fetch() ?: null;
};

if (is_post()) {
    verify_csrf();
    if (!$canEdit) {
        forbidden('You cannot edit this institution.');
    }
    $action = input('action');
    if ($action === 'delete') {
        $o = $fetchOfficer((int) int_input('officer_id'));
        if ($o) {
            db()->prepare('DELETE FROM institution_officers WHERE id = ?')->execute([$o['id']]);
            delete_upload($o['photo']);
            ensure_primary_officer($instId);
            log_activity('delete', 'officer', (int) $o['id'], "Removed placement officer {$o['name']}", [], $instId);
            flash('success', 'Officer removed.');
        }
        redirect('institution-officers', ['id' => $instId]);
    }

    $data = [
        'name'        => mb_substr((string) input('name'), 0, 160),
        'designation' => nullable(mb_substr((string) input('designation'), 0, 160)),
        'email'       => nullable(strtolower(mb_substr((string) input('email'), 0, 160))),
        'phone'       => input('phone') !== '' ? normalize_phone((string) input('phone')) : null,
        'alt_phone'   => nullable(mb_substr((string) input('alt_phone'), 0, 30)),
    ];
    $errors = [];
    if ($data['name'] === '') $errors[] = "Officer's name is required.";
    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if ($data['phone'] && !valid_mobile($data['phone'])) $errors[] = 'Mobile number must be a valid 10-digit number.';
    $oid = int_input('officer_id');
    $existing = $oid ? $fetchOfficer($oid) : null;
    if ($oid && !$existing) $errors[] = 'Officer not found.';

    // Photo: cropped image from the browser (data URL), or a plain file upload when JavaScript is off
    $photo = null;
    if (!$errors) {
        try {
            $photo = store_cropped_image('photo_data', 'photo_file', 'officers', 400);
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
        $old = $_POST;
        unset($old['photo_data']);
        $_SESSION['_old'] = $old;
        redirect('institution-officers', ['id' => $instId, 'edit' => $oid]);
    }

    if ($photo) {
        $data['photo'] = $photo;
    } elseif (input('remove_photo') === '1') {
        $data['photo'] = null;
    }

    db()->beginTransaction();
    if ($existing) {
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db()->prepare("UPDATE institution_officers SET {$set} WHERE id = ?")->execute([...array_values($data), $existing['id']]);
        $changes = diff_changes($existing, $data);
        if ($changes) {
            log_activity('update', 'officer', (int) $existing['id'], "Updated placement officer {$data['name']}", $changes, $instId);
        }
    } else {
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
        db()->prepare("INSERT INTO institution_officers (institution_id, {$cols}) VALUES (?" . str_repeat(',?', count($data)) . ')')
            ->execute([$instId, ...array_values($data)]);
        log_activity('create', 'officer', (int) db()->lastInsertId(), "Added placement officer {$data['name']}", [], $instId);
    }
    ensure_primary_officer($instId);
    db()->commit();
    if ($existing && array_key_exists('photo', $data) && $existing['photo'] !== $data['photo']) {
        delete_upload($existing['photo']);
    }
    flash('success', $existing ? 'Officer details updated.' : 'Placement officer added.');
    redirect(input('go') === 'next' ? 'institution-students' : 'institution-officers', ['id' => $instId]);
}

$st = db()->prepare('SELECT * FROM institution_officers WHERE institution_id = ? ORDER BY is_primary DESC, id');
$st->execute([$instId]);
$officers = $st->fetchAll();

$editId = int_input('edit');
$editing = $editId ? $fetchOfficer($editId) : null;
$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
$v = fn(string $k) => $old[$k] ?? $editing[$k] ?? '';
$showForm = $canEdit && ($editing || !$officers || isset($_GET['add']) || $old);

$pageTitle = $inst['name'] . ' · Placement Officers';
$extraHead = ['<link rel="stylesheet" href="' . e(asset('assets/vendor/cropperjs/cropper.min.css')) . '">'];
$extraScripts = [
    '<script src="' . e(asset('assets/vendor/cropperjs/cropper.min.js')) . '"></script>',
    '<script src="' . e(asset('assets/js/photo-crop.js')) . '"></script>',
];
require APP_ROOT . '/app/layout/header.php';
require APP_ROOT . '/app/layout/institution_nav.php';
?>
<div class="card sm:p-8">
  <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
    <?php section_heading('user', 'indigo', 'Step 2: Placement Officer Profile', 'Placement officers coordinating Campus to Corporate activities for the institution.'); ?>
    <?php if ($canEdit && !$showForm): ?>
      <a href="<?= e(url('institution-officers', ['id' => $instId, 'add' => 1])) ?>" class="btn-soft shrink-0"><?= icon('plus', 'w-3.5 h-3.5') ?> Add Officer</a>
    <?php endif; ?>
  </div>

  <?php if ($officers): ?>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
    <?php foreach ($officers as $o): ?>
      <div class="p-4 rounded-xl border border-slate-200 flex items-start gap-3.5">
        <?php if ($o['photo']): ?>
          <img src="<?= e(upload_url($o['photo'])) ?>" alt="<?= e($o['name']) ?>" class="w-16 h-16 rounded-xl object-cover border border-slate-200 shrink-0">
        <?php else: ?>
          <div class="w-16 h-16 rounded-xl bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-lg shrink-0"><?= e(initials($o['name'])) ?></div>
        <?php endif; ?>
        <div class="min-w-0 grow">
          <p class="text-sm font-bold text-slate-900"><?= e($o['name']) ?></p>
          <p class="text-xs text-slate-500"><?= e($o['designation'] ?: '—') ?></p>
          <p class="text-xs text-slate-600 mt-1.5 break-all">
            <?= $o['email'] ? e($o['email']) . ' &bull; ' : '' ?><?= e($o['phone'] ?: '') ?><?= $o['alt_phone'] ? ' &bull; ' . e($o['alt_phone']) : '' ?>
          </p>
        </div>
        <?php if ($canEdit): ?>
        <div class="flex items-center gap-1 shrink-0">
          <a href="<?= e(url('institution-officers', ['id' => $instId, 'edit' => $o['id']])) ?>" class="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100" title="Edit"><?= icon('pencil') ?></a>
          <form method="post" data-confirm="Remove <?= e($o['name']) ?>?">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="officer_id" value="<?= $o['id'] ?>">
            <button class="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50" title="Remove"><?= icon('trash') ?></button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php elseif (!$canEdit): ?>
    <p class="text-sm text-slate-500">No placement officers have been added yet.</p>
  <?php endif; ?>

  <?php if ($showForm): ?>
  <form method="post" enctype="multipart/form-data" class="<?= $officers ? 'pt-6 border-t border-slate-100' : '' ?>" data-photo-form>
    <?= csrf_field() ?>
    <input type="hidden" name="officer_id" value="<?= e($editing['id'] ?? '') ?>">
    <h3 class="text-sm font-bold text-slate-800 mb-4"><?= $editing ? 'Edit officer' : 'Add a placement officer' ?></h3>
    <div class="flex flex-col md:flex-row gap-8">
    <!-- Photo with crop -->
    <div class="shrink-0 md:w-44">
      <label class="label">Photo</label>
      <div class="w-40 h-40 rounded-2xl bg-slate-50 border border-dashed border-slate-300 overflow-hidden flex items-center justify-center">
        <img id="photo-preview" src="<?= e(($editing['photo'] ?? null) ? upload_url($editing['photo']) : '') ?>" alt="Officer photo" class="w-full h-full object-cover <?= ($editing['photo'] ?? null) ? '' : 'hidden' ?>">
        <span id="photo-placeholder" class="text-xs text-slate-400 text-center px-3 <?= ($editing['photo'] ?? null) ? 'hidden' : '' ?>">No photo</span>
      </div>
      <input type="hidden" name="photo_data" id="photo-data">
      <input type="file" id="photo-file" name="photo_file" accept="image/jpeg,image/png,image/webp" class="hidden">
      <div class="flex flex-wrap gap-1.5 mt-2">
        <button type="button" class="btn-secondary btn-xs" data-photo-choose data-no-busy><?= icon('pencil', 'w-3.5 h-3.5') ?> <?= ($editing['photo'] ?? null) ? 'Change' : 'Upload' ?></button>
      </div>
      <?php if ($editing['photo'] ?? null): ?>
        <label class="mt-2 flex items-center gap-1.5 text-xs text-slate-500"><input type="checkbox" name="remove_photo" value="1" class="rounded border-slate-300"> Remove photo</label>
      <?php endif; ?>
      <span class="hint">JPG / PNG / WEBP. You can crop it to a square after choosing.</span>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-3xl grow">
      <div>
        <label class="label">Officer Full Name <span class="req">*</span></label>
        <input class="input" name="name" value="<?= e($v('name')) ?>" required placeholder="Prof. Rajesh K">
      </div>
      <div>
        <label class="label">Designation</label>
        <input class="input" name="designation" value="<?= e($v('designation')) ?>" placeholder="Head of Training & Placement">
      </div>
      <div>
        <label class="label">Official / Placement Email ID</label>
        <input class="input" type="email" name="email" value="<?= e($v('email')) ?>" placeholder="rajesh.n@college.ac.in">
      </div>
      <div>
        <label class="label">Contact Mobile Number</label>
        <input class="input" type="tel" name="phone" value="<?= e($v('phone')) ?>" pattern="[6-9][0-9]{9}" placeholder="9847000000 (10 digits)">
      </div>
      <div>
        <label class="label">Alternative Contact / Placement Cell Helpline</label>
        <input class="input" name="alt_phone" value="<?= e($v('alt_phone')) ?>" placeholder="0471-2300000">
      </div>
    </div>
    </div>
    <div class="mt-8 pt-5 border-t border-slate-100 flex items-center justify-between gap-3">
      <a href="<?= e(url('institution-officers', ['id' => $instId])) ?>" class="btn-secondary"><?= $officers ? 'Cancel' : '&larr; Back' ?></a>
      <div class="flex gap-2">
        <button name="go" value="stay" class="btn-secondary">Save officer</button>
        <button name="go" value="next" class="btn-primary">Save &amp; Setup Student Strength <?= icon('arrow-right') ?></button>
      </div>
    </div>
  </form>
  <?php elseif ($canEdit && $officers): ?>
    <div class="mt-2 pt-5 border-t border-slate-100 flex justify-between">
      <a href="<?= e(url('institution-profile', ['id' => $instId])) ?>" class="btn-secondary">&larr; Back</a>
      <a href="<?= e(url('institution-students', ['id' => $instId])) ?>" class="btn-primary">Proceed to Student Strength <?= icon('arrow-right') ?></a>
    </div>
  <?php endif; ?>
</div>
<?php require APP_ROOT . '/app/layout/crop_modal.php'; ?>
<?php require APP_ROOT . '/app/layout/footer.php';
