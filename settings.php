<?php
require __DIR__ . '/app/bootstrap.php';
require_role('admin');

$fields = [
    'app_name'      => ['Portal name', 'text'],
    'app_tagline'   => ['Tagline', 'text'],
    'academic_year' => ['Current academic year', 'text'],
    'cohort_policy' => ['Cohort policy notice (Step 4)', 'textarea'],
    'footer_left'   => ['Footer text (left)', 'text'],
    'footer_right'  => ['Footer text (right)', 'text'],
    'login_heading' => ['Sign-in page heading', 'textarea'],
    'login_text'    => ['Sign-in page description', 'textarea'],
];
$defaults = ['login_heading' => LOGIN_HEADING_DEFAULT, 'login_text' => LOGIN_TEXT_DEFAULT];

$isSuper = user_role() === 'superadmin';

if (is_post()) {
    verify_csrf();

    // Portal logo (cropped square PNG, transparency kept)
    if (input('action') === 'logo') {
        $old = setting('app_logo');
        try {
            $new = store_cropped_image('photo_data', 'photo_file', 'branding', 256, 'png');
        } catch (RuntimeException $ex) {
            flash('error', $ex->getMessage());
            redirect('settings');
        }
        if (!$new && input('remove_photo') !== '1') {
            flash('error', 'Choose an image to upload.');
            redirect('settings');
        }
        db()->prepare("INSERT INTO settings (`key`, `value`) VALUES ('app_logo', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([$new ?? '']);
        if ($old !== '' && $old !== $new) {
            delete_upload($old);
        }
        log_activity('update', 'settings', null, $new ? 'Updated the portal logo' : 'Removed the portal logo');
        flash('success', $new ? 'Logo updated. It now appears in the header, sign-in page and browser tab.' : 'Logo removed - the default badge is shown again.');
        redirect('settings');
    }

    // Send a test email (administrators) to check the mail settings in .env
    if (input('action') === 'test_email') {
        $to = trim((string) input('test_to'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address for the test.');
            redirect('settings');
        }
        $ok = send_mail($to, 'Campus to Corporate - test email', email_layout('Test email',
            '<p>This is a test message from the Campus to Corporate portal.</p><p>If you can read this, outgoing email is working.</p>'), 'test');
        $err = db()->query("SELECT error FROM mail_log WHERE template = 'test' ORDER BY id DESC LIMIT 1")->fetchColumn();
        flash($ok ? 'success' : 'error', $ok
            ? "Test email handed to the mail server for {$to} (method: " . config('mail_driver') . '). If it does not arrive within a few minutes, check the spam folder and the domain\'s SPF/DKIM records.'
            : "Test email failed: {$err}");
        redirect('settings');
    }

    // Feature visibility - super admin only (hidden from and not accepted for administrators)
    if (input('action') === 'visibility') {
        if (!$isSuper) {
            forbidden();
        }
        $st = db()->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
        $changes = [];
        foreach (SUPPORT_ROLE_SETTINGS as $role => $key) {
            $new = input($key) === '1' ? '1' : '0';
            $old = setting($key, '0');
            $st->execute([$key, $new]);
            if ($old !== $new) {
                $changes[SUPPORT_ROLES[$role]['full']] = [$old === '1' ? 'shown' : 'hidden', $new === '1' ? 'shown' : 'hidden'];
            }
        }
        if ($changes) {
            log_activity('update', 'settings', null, 'Changed support team visibility', $changes);
        }
        flash('success', 'Visibility settings saved.');
        redirect('settings');
    }

    $current = [];
    foreach (db()->query('SELECT `key`, `value` FROM settings') as $r) {
        $current[$r['key']] = $r['value'];
    }
    $new = [];
    foreach ($fields as $k => [$label]) {
        $new[$k] = mb_substr(trim((string) input($k)), 0, in_array($k, ['cohort_policy', 'login_text'], true) ? 2000 : ($k === 'login_heading' ? 300 : 200));
    }
    if (!preg_match('/^(\d{4})-(\d{2})$/', $new['academic_year'], $mt) || (int) $mt[2] !== ((int) $mt[1] + 1) % 100) {
        flash('error', 'Academic year must look like 2026-27.');
        redirect('settings');
    }
    if ($new['app_name'] === '') {
        flash('error', 'Portal name is required.');
        redirect('settings');
    }
    $st = db()->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    foreach ($new as $k => $v) {
        $st->execute([$k, $v]);
    }
    $changes = diff_changes($current, $new);
    if ($changes) {
        log_activity('update', 'settings', null, 'Updated portal settings (' . implode(', ', array_keys($changes)) . ')', $changes);
    }
    flash('success', 'Settings saved.' . (isset($changes['academic_year']) ? ' New data entry now uses academic year ' . $new['academic_year'] . '.' : ''));
    redirect('settings');
}

// Data volume overview per academic year
$years = db()->query("SELECT academic_year, COUNT(DISTINCT institution_id) n FROM institution_cohorts GROUP BY academic_year
                      UNION SELECT academic_year, COUNT(DISTINCT institution_id) FROM institution_departments GROUP BY academic_year
                      ORDER BY academic_year DESC")->fetchAll();
$yearMap = [];
foreach ($years as $y) {
    $yearMap[$y['academic_year']] = max($yearMap[$y['academic_year']] ?? 0, (int) $y['n']);
}

$recentMail = db()->query('SELECT recipient, subject, template, status, error, created_at FROM mail_log ORDER BY id DESC LIMIT 15')->fetchAll();
$mailDriver = config('mail_driver', 'mail');

$pageTitle = 'Settings';
$extraHead = ['<link rel="stylesheet" href="' . e(asset('assets/vendor/cropperjs/cropper.min.css')) . '">'];
$extraScripts = [
    '<script src="' . e(asset('assets/vendor/cropperjs/cropper.min.js')) . '"></script>',
    '<script src="' . e(asset('assets/js/photo-crop.js')) . '"></script>',
];
$logo = setting('app_logo');
require APP_ROOT . '/app/layout/header.php';
?>
<div class="mb-6">
  <h1 class="text-xl font-bold text-slate-900">Settings</h1>
  <p class="text-sm text-slate-500">Portal-wide configuration for the Campus to Corporate programme.</p>
</div>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <form method="post" class="card lg:col-span-2 space-y-5">
    <?= csrf_field() ?>
    <?php foreach ($fields as $k => [$label, $type]): ?>
      <?php if ($k === 'login_heading'): ?>
        <div class="pt-4 border-t border-slate-100">
          <h3 class="text-sm font-bold text-slate-800">Sign-in page</h3>
          <p class="text-xs text-slate-500">Text on the dark panel of the sign-in page. Press Enter for a new line. Leave empty to use the default text.</p>
        </div>
      <?php endif; ?>
      <div>
        <label class="label" for="<?= $k ?>"><?= e($label) ?></label>
        <?php if ($type === 'textarea'): ?>
          <textarea class="input <?= $k === 'login_heading' ? 'min-h-20' : 'min-h-24' ?>" id="<?= $k ?>" name="<?= $k ?>" maxlength="<?= $k === 'login_heading' ? 300 : 2000 ?>"><?= e(setting($k) ?: ($defaults[$k] ?? '')) ?></textarea>
        <?php else: ?>
          <input class="input" id="<?= $k ?>" name="<?= $k ?>" value="<?= e(setting($k)) ?>" <?= $k === 'academic_year' ? 'pattern="\d{4}-\d{2}" placeholder="2026-27"' : '' ?>>
        <?php endif; ?>
        <?php if ($k === 'academic_year'): ?><span class="hint">Student strength, cohorts and assessments are recorded per academic year. Changing this starts a fresh year; earlier years stay in the database.</span><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div class="pt-4 border-t border-slate-100 flex justify-end"><button class="btn-primary">Save settings</button></div>
  </form>
  <div class="space-y-6 self-start">
  <form method="post" enctype="multipart/form-data" class="card" data-photo-form data-crop-size="256" data-crop-type="image/png">
    <?= csrf_field() ?><input type="hidden" name="action" value="logo">
    <h2 class="card-title mb-1">Portal logo</h2>
    <p class="card-subtitle mb-4">Shown in the header, on the sign-in and public pages, and as the browser tab icon. Cropped to a square; transparent PNGs keep their transparency.</p>
    <div class="flex items-center gap-4">
      <div class="w-24 h-24 rounded-2xl bg-slate-50 border border-dashed border-slate-300 overflow-hidden flex items-center justify-center shrink-0">
        <img id="photo-preview" src="<?= e($logo ? asset('uploads/' . $logo) : '') ?>" alt="Portal logo" class="w-full h-full object-contain <?= $logo ? '' : 'hidden' ?>">
        <span id="photo-placeholder" class="<?= $logo ? 'hidden' : '' ?>"><?= app_logo_html('w-14 h-14') ?></span>
      </div>
      <div class="space-y-2">
        <input type="hidden" name="photo_data" id="photo-data">
        <input type="file" id="photo-file" name="photo_file" accept="image/jpeg,image/png,image/webp" class="hidden">
        <button type="button" class="btn-secondary btn-xs" data-photo-choose data-no-busy><?= icon('pencil', 'w-3.5 h-3.5') ?> <?= $logo ? 'Change logo' : 'Upload logo' ?></button>
        <?php if ($logo): ?><label class="flex items-center gap-1.5 text-xs text-slate-500"><input type="checkbox" name="remove_photo" value="1" class="rounded border-slate-300"> Remove logo</label><?php endif; ?>
        <span class="hint">JPG / PNG / WEBP. Square, at least 256 × 256 px works best.</span>
      </div>
    </div>
    <div class="pt-4 mt-4 border-t border-slate-100 flex justify-end"><button class="btn-primary btn-xs">Save logo</button></div>
  </form>
  <?php if ($isSuper): ?>
  <form method="post" class="card border-amber-200">
    <?= csrf_field() ?><input type="hidden" name="action" value="visibility">
    <div class="flex items-center gap-2 mb-1">
      <h2 class="card-title">Feature visibility</h2>
      <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-amber-100 text-amber-800">Super Admin</span>
    </div>
    <p class="card-subtitle mb-4">Show or hide optional support team roles on the Districts &amp; Support Team page and on institution dashboards. Hidden details are kept, not deleted.</p>
    <div class="space-y-3">
      <?php foreach (SUPPORT_ROLE_SETTINGS as $role => $key): $r = SUPPORT_ROLES[$role]; ?>
        <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200 hover:bg-slate-50 cursor-pointer">
          <input type="checkbox" name="<?= e($key) ?>" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-sky-600" <?= support_role_visible($role) ? 'checked' : '' ?>>
          <span>
            <span class="block text-sm font-semibold text-slate-900">Show <?= e($r['full']) ?><?= $r['short'] !== $r['full'] ? ' (' . e($r['short']) . ')' : '' ?></span>
            <span class="block text-xs text-slate-500"><?= e($r['purpose']) ?></span>
          </span>
        </label>
      <?php endforeach; ?>
      <p class="text-[11px] text-slate-400">The Talent Connect Executive (TCE) is always shown.</p>
    </div>
    <div class="pt-4 mt-4 border-t border-slate-100 flex justify-end"><button class="btn-primary">Save visibility</button></div>
  </form>
  <?php endif; ?>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="action" value="test_email">
    <h2 class="card-title mb-1">Send a test email</h2>
    <p class="card-subtitle mb-3">Checks the email settings in <span class="font-mono">.env</span>. Sender: <span class="font-mono"><?= e(config('mail_from') ?: '(C2C_MAIL_FROM not set)') ?></span><?= config('mail_driver') === 'smtp' ? ' via <span class="font-mono">' . e(config('smtp_host')) . ':' . (int) config('smtp_port') . ' (' . e(config('smtp_secure')) . ')</span>' : '' ?></p>
    <div class="flex gap-2">
      <input class="input input-sm" type="email" name="test_to" required placeholder="you@example.com" value="<?= e(current_user()['email'] ?? '') ?>">
      <button class="btn-primary btn-xs shrink-0">Send</button>
    </div>
  </form>
  <div class="card">
    <h2 class="card-title mb-1">Recent emails</h2>
    <p class="card-subtitle mb-3">Delivery method: <span class="font-mono"><?= e($mailDriver) ?></span><?= $mailDriver === 'log' ? ' (testing - nothing is actually sent)' : '' ?> · set in <span class="font-mono">.env</span></p>
    <ul class="divide-y divide-slate-100 text-xs max-h-80 overflow-auto">
      <?php foreach ($recentMail as $ml): ?>
        <li class="py-2">
          <div class="flex items-center justify-between gap-2">
            <span class="truncate text-slate-700"><?= e($ml['recipient']) ?></span>
            <?= status_badge(ucfirst($ml['status'])) ?>
          </div>
          <p class="text-slate-500 truncate"><?= e($ml['subject']) ?> · <?= e(time_ago($ml['created_at'])) ?></p>
          <?php if ($ml['error']): ?><p class="text-rose-600 break-words"><?= e($ml['error']) ?></p><?php endif; ?>
        </li>
      <?php endforeach; ?>
      <?php if (!$recentMail): ?><li class="py-2 text-slate-400 italic">No emails yet.</li><?php endif; ?>
    </ul>
  </div>
  <div class="card">
    <h2 class="card-title mb-1">Academic years on record</h2>
    <p class="card-subtitle mb-4">Institutions with cohort or student data</p>
    <ul class="space-y-2 text-sm">
      <?php foreach ($yearMap as $y => $n): ?>
        <li class="flex justify-between"><span class="font-medium <?= $y === academic_year() ? 'text-sky-700' : 'text-slate-700' ?>"><?= e($y) ?><?= $y === academic_year() ? ' (current)' : '' ?></span><span class="font-mono"><?= num($n) ?></span></li>
      <?php endforeach; ?>
      <?php if (!$yearMap): ?><li class="text-xs text-slate-400 italic">No data yet.</li><?php endif; ?>
    </ul>
  </div>
  </div>
</div>
<?php require APP_ROOT . '/app/layout/crop_modal.php'; ?>
<?php require APP_ROOT . '/app/layout/footer.php';
