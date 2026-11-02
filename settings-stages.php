<?php
/** Settings → How It Works: page heading texts and the stages (label, heading, content, image). */
require __DIR__ . '/app/bootstrap.php';
require_role('admin');

$load = function (int $id): ?array {
    $st = db()->prepare('SELECT * FROM site_stages WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
};

if (is_post()) {
    verify_csrf();
    $action = input('action');
    $id = (int) int_input('id', 0);
    $stage = $id ? $load($id) : null;

    if ($action === 'texts') {
        $errors = home_save_texts(home_text_keys('hiw'), 'How It Works page texts');
        foreach ($errors as $err) flash('error', $err);
        if (!$errors) flash('success', 'Page texts saved.');
    } elseif ($action === 'toggle' && $stage) {
        db()->prepare('UPDATE site_stages SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        flash('success', $stage['is_active'] ? 'Stage hidden from the page.' : 'Stage shown on the page.');
    } elseif ($action === 'delete' && $stage) {
        db()->prepare('DELETE FROM site_stages WHERE id = ?')->execute([$id]);
        delete_upload($stage['image']);
        log_activity('delete', 'settings', null, "Deleted How It Works stage: {$stage['stage_label']} {$stage['heading']}");
        flash('success', 'Stage deleted.');
    } elseif ($action === 'save') {
        $d = [
            'stage_label' => mb_substr(trim((string) input('stage_label')), 0, 40),
            'heading'     => mb_substr(trim((string) input('heading')), 0, 200),
            'content'     => mb_substr(trim((string) input('content')), 0, 3000),
            'sort_order'  => (int) int_input('sort_order', 0),
        ];
        $back = $stage ? ['edit' => $id] : ['new' => 1];
        if ($d['stage_label'] === '' || $d['heading'] === '' || $d['content'] === '') {
            flash('error', 'Enter the stage label, heading and content.');
            $_SESSION['_old'] = $_POST;
            redirect('settings-stages', $back);
        }
        try {
            $image = store_image_upload('image', 'branding', 600);
        } catch (RuntimeException $ex) {
            flash('error', $ex->getMessage());
            $_SESSION['_old'] = $_POST;
            redirect('settings-stages', $back);
        }
        $oldImage = $stage['image'] ?? null;
        if ($image !== null) {
            $d['image'] = $image;
        } elseif ($stage && input('remove_image') === '1') {
            $d['image'] = null;
        }
        if ($stage) {
            $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($d)));
            db()->prepare("UPDATE site_stages SET {$set} WHERE id = ?")->execute([...array_values($d), $id]);
            if ($oldImage && array_key_exists('image', $d) && $d['image'] !== $oldImage) delete_upload($oldImage);
            log_activity('update', 'settings', null, "Updated How It Works stage: {$d['stage_label']} {$d['heading']}");
            flash('success', 'Stage updated.');
        } else {
            if (!$d['sort_order']) $d['sort_order'] = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM site_stages')->fetchColumn();
            $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($d)));
            db()->prepare("INSERT INTO site_stages ({$cols}) VALUES (" . implode(',', array_fill(0, count($d), '?')) . ')')->execute(array_values($d));
            log_activity('create', 'settings', null, "Added How It Works stage: {$d['stage_label']} {$d['heading']}");
            flash('success', 'Stage added.');
        }
    }
    redirect('settings-stages');
}

$stages = db()->query('SELECT * FROM site_stages ORDER BY sort_order, id')->fetchAll();
$editing = ($e = int_input('edit')) ? $load($e) : null;
$showForm = $editing || isset($_GET['new']);
$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
$v = fn(string $k, $def = '') => $old[$k] ?? $editing[$k] ?? $def;

$pageTitle = 'Settings · How It Works';
require APP_ROOT . '/app/layout/header.php';
?>
<div class="mb-6 flex flex-col sm:flex-row sm:items-end justify-between gap-3">
  <div><h1 class="text-xl font-bold text-slate-900">Settings</h1><p class="text-sm text-slate-500">Stages and texts of the public How It Works page.</p></div>
  <a href="<?= e(url('how-it-works')) ?>" target="_blank" rel="noopener" class="btn-secondary"><?= icon('globe') ?> View How It Works page</a>
</div>
<?php $settingsTab = 'settings-stages'; require APP_ROOT . '/app/layout/settings_tabs.php'; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
  <div class="xl:col-span-2 space-y-4">
    <?php if ($showForm): $img = upload_url($editing['image'] ?? null); ?>
      <form method="post" enctype="multipart/form-data" class="card space-y-4">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
        <h2 class="card-title"><?= $editing ? 'Edit stage' : 'Add a stage' ?></h2>
        <div class="grid grid-cols-1 md:grid-cols-[180px_1fr_120px] gap-4">
          <div><label class="label" for="stage_label">Stage label <span class="req">*</span></label><input class="input" id="stage_label" name="stage_label" maxlength="40" value="<?= e($v('stage_label')) ?>" placeholder="e.g. STAGE 01" required></div>
          <div><label class="label" for="heading">Heading <span class="req">*</span></label><input class="input" id="heading" name="heading" maxlength="200" value="<?= e($v('heading')) ?>" required></div>
          <div><label class="label" for="sort_order">Order</label><input class="input" id="sort_order" type="number" name="sort_order" value="<?= e($v('sort_order')) ?>" placeholder="Last"></div>
        </div>
        <div><label class="label" for="content">Content <span class="req">*</span></label><textarea class="input" id="content" name="content" rows="4" required><?= e($v('content')) ?></textarea></div>
        <div class="flex items-start gap-4">
          <div class="w-24 h-24 rounded-xl border border-slate-200 bg-slate-50 overflow-hidden flex items-center justify-center shrink-0">
            <?php if ($img): ?><img src="<?= e($img) ?>" alt="" class="w-full h-full object-cover"><?php else: ?><span class="text-[10px] text-slate-400">No image</span><?php endif; ?>
          </div>
          <div>
            <label class="label">Image</label>
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="text-xs">
            <span class="hint">A photo for this stage; shown as a rounded square (centre-cropped). JPG, PNG or WEBP.</span>
            <?php if ($img): ?><label class="flex items-center gap-2 text-xs text-slate-600 mt-2"><input type="checkbox" name="remove_image" value="1" class="h-4 w-4 rounded border-slate-300"> Remove the current image</label><?php endif; ?>
          </div>
        </div>
        <div class="pt-4 border-t border-slate-100 flex justify-end gap-2">
          <a href="<?= e(url('settings-stages')) ?>" class="btn-secondary">Cancel</a>
          <button class="btn-primary"><?= $editing ? 'Save stage' : 'Add stage' ?></button>
        </div>
      </form>
    <?php endif; ?>

    <div class="card p-0 overflow-hidden">
      <div class="p-5 pb-3 flex items-center justify-between gap-3">
        <div><h2 class="card-title">Stages</h2><p class="card-subtitle"><?= count($stages) ?> in total · shown in order</p></div>
        <?php if (!$showForm): ?><a href="<?= e(url('settings-stages', ['new' => 1])) ?>" class="btn-primary btn-xs"><?= icon('plus', 'w-3.5 h-3.5') ?> Add stage</a><?php endif; ?>
      </div>
      <ul class="divide-y divide-slate-100 border-t border-slate-100">
        <?php foreach ($stages as $s): $img = upload_url($s['image']); ?>
          <li class="p-4 flex items-start gap-4 <?= $s['is_active'] ? '' : 'bg-slate-50' ?>">
            <?php if ($img): ?><img src="<?= e($img) ?>" alt="" class="w-14 h-14 rounded-lg object-cover shrink-0"><?php else: ?><span class="w-14 h-14 rounded-lg bg-slate-100 text-slate-400 flex items-center justify-center shrink-0"><?= icon('academic', 'w-6 h-6') ?></span><?php endif; ?>
            <div class="min-w-0 grow">
              <p class="text-[11px] font-bold uppercase text-sky-700"><?= e($s['stage_label']) ?> <span class="text-slate-400 font-mono font-normal normal-case">· order <?= (int) $s['sort_order'] ?></span> <?= $s['is_active'] ? '' : status_badge('Inactive') ?></p>
              <p class="font-semibold text-slate-900 text-sm"><?= e($s['heading']) ?></p>
              <p class="text-xs text-slate-500 mt-0.5 line-clamp-2"><?= e($s['content']) ?></p>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <a href="<?= e(url('settings-stages', ['edit' => $s['id']])) ?>" class="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100" title="Edit"><?= icon('pencil') ?></a>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn-secondary btn-xs"><?= $s['is_active'] ? 'Hide' : 'Show' ?></button></form>
              <form method="post" data-confirm="Delete this stage?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50" title="Delete"><?= icon('trash') ?></button></form>
            </div>
          </li>
        <?php endforeach; ?>
        <?php if (!$stages): ?><li class="p-8 text-center text-slate-400 text-sm">No stages yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>

  <form method="post" class="card self-start">
    <?= csrf_field() ?><input type="hidden" name="action" value="texts">
    <h2 class="card-title mb-1">Page texts</h2>
    <p class="card-subtitle mb-4">The heading block above the stages.</p>
    <div class="grid grid-cols-1 gap-4"><?= str_replace('md:col-span-2', '', home_text_fields(home_text_keys('hiw'))) ?></div>
    <div class="mt-5 flex justify-end"><button class="btn-primary">Save texts</button></div>
  </form>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
