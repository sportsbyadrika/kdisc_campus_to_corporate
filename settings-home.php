<?php
/** Settings → Home page: texts, photos and partner logos of the public home page, and its Latest Updates & Resources. */
require __DIR__ . '/app/bootstrap.php';
$me = require_role('admin');

$saveSetting = function (string $key, string $value): void {
    db()->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$key, $value]);
};

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'texts') {
        $errors = [];
        $changes = [];
        foreach (HOME_TEXTS as $key => [$label, $default, $type]) {
            $value = trim((string) input($key));
            $value = mb_substr($value, 0, $type === 'textarea' ? 1000 : 300);
            if ($type === 'url' && $value !== '' && !preg_match('#^https?://#i', $value)) { $errors[] = "{$label} must start with http:// or https://"; continue; }
            if ($key === 'contact_email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid contact email.'; continue; }
            if ($type === 'select' && !isset(HOME_HERO_THEMES[$value])) $value = $default;
            if ($value === '' && $default !== '' && $type !== 'url' && !in_array($key, HOME_OPTIONAL_TEXTS, true)) $value = $default;   // never leave the page blank
            if ($value !== home_text($key)) $changes[$label] = [home_text($key), $value];
            $saveSetting($key, $value);
        }
        foreach ($errors as $err) flash('error', $err);
        if ($changes) log_activity('update', 'settings', null, 'Updated home page texts', $changes);
        if (!$errors) flash('success', 'Home page texts saved.');
        redirect('settings-home');
    }

    if ($action === 'image' && isset(HOME_IMAGES[$slot = (string) input('slot')])) {
        $old = setting($slot);
        try {
            $new = input('remove') === '1' ? '' : store_image_upload('image', 'branding', HOME_IMAGES[$slot][2]);
        } catch (RuntimeException $ex) {
            flash('error', $ex->getMessage());
            redirect('settings-home');
        }
        if ($new === null) {
            flash('error', 'Choose an image to upload.');
            redirect('settings-home');
        }
        $saveSetting($slot, $new);
        if ($old !== '' && $old !== $new) delete_upload($old);
        log_activity('update', 'settings', null, ($new ? 'Updated' : 'Removed') . ' home page image: ' . HOME_IMAGES[$slot][0]);
        flash('success', HOME_IMAGES[$slot][0] . ($new ? ' updated.' : ' removed.'));
        redirect('settings-home');
    }

    if ($action === 'update_add') {
        $title = mb_substr(trim((string) input('title')), 0, 200);
        $date = (string) input('published_on');
        $link = trim((string) input('link_url'));
        $errors = [];
        if ($title === '') $errors[] = 'Enter the title of the update.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) $errors[] = 'Enter a valid date.';
        if ($link !== '' && !preg_match('#^(https?://|/)#i', $link)) $errors[] = 'The link must start with http://, https:// or / (a page of this portal).';
        $file = null;
        if (!$errors) {
            try {
                $file = store_pdf_upload('file');
            } catch (RuntimeException $ex) {
                $errors[] = $ex->getMessage();
            }
        }
        if ($errors) {
            foreach ($errors as $err) flash('error', $err);
            $_SESSION['_old'] = $_POST;
            redirect('settings-home');
        }
        db()->prepare('INSERT INTO site_updates (title, published_on, link_url, file, created_by) VALUES (?,?,?,?,?)')
            ->execute([$title, $date, $link ?: null, $file, $me['id']]);
        log_activity('create', 'settings', null, "Posted home page update: {$title}");
        flash('success', 'Update posted on the home page.');
        redirect('settings-home');
    }

    $uid = (int) int_input('update_id', 0);
    $st = db()->prepare('SELECT * FROM site_updates WHERE id = ?');
    $st->execute([$uid]);
    if ($u = $st->fetch()) {
        if ($action === 'update_toggle') {
            db()->prepare('UPDATE site_updates SET is_active = 1 - is_active WHERE id = ?')->execute([$uid]);
            flash('success', $u['is_active'] ? 'Update hidden from the home page.' : 'Update shown on the home page.');
        } elseif ($action === 'update_delete') {
            db()->prepare('DELETE FROM site_updates WHERE id = ?')->execute([$uid]);
            delete_upload($u['file']);
            log_activity('delete', 'settings', null, "Deleted home page update: {$u['title']}");
            flash('success', 'Update deleted.');
        }
    }
    redirect('settings-home');
}

$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
$allUpdates = db()->query('SELECT * FROM site_updates ORDER BY published_on DESC, id DESC')->fetchAll();

$pageTitle = 'Settings · Home page';
require APP_ROOT . '/app/layout/header.php';
?>
<div class="mb-6 flex flex-col sm:flex-row sm:items-end justify-between gap-3">
  <div>
    <h1 class="text-xl font-bold text-slate-900">Settings</h1>
    <p class="text-sm text-slate-500">Texts, photos, logos and updates of the public home page.</p>
  </div>
  <a href="<?= e(url('')) ?>" target="_blank" rel="noopener" class="btn-secondary"><?= icon('globe') ?> View home page</a>
</div>
<?php $settingsTab = 'settings-home'; require APP_ROOT . '/app/layout/settings_tabs.php'; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
  <form method="post" class="card xl:col-span-2">
    <?= csrf_field() ?><input type="hidden" name="action" value="texts">
    <h2 class="card-title mb-1">Texts and links</h2>
    <p class="card-subtitle mb-5">Clearing a text brings back its default, except the hero small heading, targets note and handwritten tagline, which are hidden when empty. Footer links and social icons appear only when a link is entered.</p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <?php foreach (HOME_TEXTS as $key => [$label, $default, $type]): ?>
        <div class="<?= $type === 'textarea' ? 'md:col-span-2' : '' ?>">
          <label class="label" for="<?= $key ?>"><?= e($label) ?></label>
          <?php if ($type === 'select'): ?><select class="input" id="<?= $key ?>" name="<?= $key ?>"><?php foreach (HOME_HERO_THEMES as $tk => $tl): ?><option value="<?= $tk ?>" <?= home_text($key) === $tk ? 'selected' : '' ?>><?= e($tl) ?></option><?php endforeach; ?></select>
          <span class="hint">Use Light with a photo on a white background, Dark with a darker photo.</span>
          <?php elseif ($type === 'textarea'): ?><textarea class="input" id="<?= $key ?>" name="<?= $key ?>" rows="3"><?= e(home_text($key)) ?></textarea>
          <?php else: ?><input class="input" id="<?= $key ?>" name="<?= $key ?>" type="<?= $type === 'url' ? 'url' : 'text' ?>" value="<?= e(home_text($key)) ?>" <?= $type === 'url' ? 'placeholder="https://"' : '' ?>><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-6 pt-5 border-t border-slate-100 flex justify-end"><button class="btn-primary">Save texts</button></div>
  </form>

  <div class="space-y-4">
    <?php foreach (HOME_IMAGES as $slot => [$label, $hint]): $src = home_image($slot); ?>
      <form method="post" enctype="multipart/form-data" class="card p-4">
        <?= csrf_field() ?><input type="hidden" name="action" value="image"><input type="hidden" name="slot" value="<?= $slot ?>">
        <div class="flex items-start gap-3">
          <div class="w-24 h-16 rounded-lg border border-slate-200 bg-slate-50 flex items-center justify-center overflow-hidden shrink-0">
            <?php if ($src): ?><img src="<?= e($src) ?>" alt="" class="max-w-full max-h-full object-contain"><?php else: ?><span class="text-[10px] text-slate-400">Default</span><?php endif; ?>
          </div>
          <div class="min-w-0 grow">
            <p class="text-sm font-semibold text-slate-800"><?= e($label) ?></p>
            <p class="text-[11px] text-slate-500"><?= e($hint) ?></p>
            <div class="flex flex-wrap items-center gap-2 mt-2">
              <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="text-xs w-full">
              <button class="btn-primary btn-xs">Upload</button>
              <?php if ($src): ?><button class="btn-secondary btn-xs text-rose-600" name="remove" value="1" data-confirm="Remove this image and show the default?">Remove</button><?php endif; ?>
            </div>
          </div>
        </div>
      </form>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mt-6" id="updates">
  <form method="post" enctype="multipart/form-data" class="card space-y-4 self-start">
    <?= csrf_field() ?><input type="hidden" name="action" value="update_add">
    <h2 class="card-title">Post an update</h2>
    <div><label class="label">Title <span class="req">*</span></label><input class="input" name="title" maxlength="200" value="<?= e($old['title'] ?? '') ?>" required placeholder="e.g. Programme guidelines for participating institutions"></div>
    <div><label class="label">Date <span class="req">*</span></label><input class="input" type="date" name="published_on" value="<?= e($old['published_on'] ?? date('Y-m-d')) ?>" required></div>
    <div><label class="label">Link</label><input class="input" name="link_url" value="<?= e($old['link_url'] ?? '') ?>" placeholder="https://… or /enroll"></div>
    <div><label class="label">Or attach a PDF</label><input type="file" name="file" accept="application/pdf" class="text-xs w-full"><span class="hint">Up to 10 MB. A PDF takes the place of the link.</span></div>
    <button class="btn-primary w-full justify-center"><?= icon('plus') ?> Post update</button>
  </form>
  <div class="card p-0 overflow-hidden xl:col-span-2">
    <div class="p-5 pb-3"><h2 class="card-title">Latest Updates &amp; Resources</h2><p class="card-subtitle">The newest three appear on the home page; all visible ones on the Updates page.</p></div>
    <div class="overflow-x-auto">
      <table class="table">
        <thead><tr><th>Update</th><th>Date</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($allUpdates as $u): $href = site_update_href($u); ?>
            <tr>
              <td><p class="font-medium text-slate-900"><?= e($u['title']) ?></p><?php if ($href): ?><a href="<?= e($href) ?>" target="_blank" rel="noopener" class="text-[11px] link"><?= $u['file'] ? 'PDF' : e(mb_strimwidth($u['link_url'], 0, 60, '…')) ?></a><?php endif; ?></td>
              <td class="text-xs whitespace-nowrap"><?= e(date('d M Y', strtotime($u['published_on']))) ?></td>
              <td><?= status_badge($u['is_active'] ? 'Active' : 'Inactive') ?></td>
              <td class="text-right whitespace-nowrap">
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="update_toggle"><input type="hidden" name="update_id" value="<?= $u['id'] ?>"><button class="btn-secondary btn-xs"><?= $u['is_active'] ? 'Hide' : 'Show' ?></button></form>
                <form method="post" class="inline" data-confirm="Delete this update?"><?= csrf_field() ?><input type="hidden" name="action" value="update_delete"><input type="hidden" name="update_id" value="<?= $u['id'] ?>"><button class="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50 align-middle" title="Delete"><?= icon('trash') ?></button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$allUpdates): ?><tr><td colspan="4" class="text-center text-slate-400 py-8">No updates posted yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
