<?php
/**
 * Set a password from an emailed one-time link (new login or "forgot password").
 * Only the SHA-256 hash of the token is stored; tokens expire and work once.
 */
require __DIR__ . '/app/bootstrap.php';
header('Referrer-Policy: no-referrer'); // keep the token out of Referer headers

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');

if (is_post()) {
    verify_csrf();
    if (!throttle_allow('setpw:' . client_ip(), 20, 3600)) {
        too_many_requests();
    }
    $row = find_password_token($token);
    if ($row) {
        $password = (string) ($_POST['password'] ?? '');
        $error = password_policy_error($password, (string) ($_POST['password_confirm'] ?? ''));
        if ($error === null && (strcasecmp($password, $row['username']) === 0 || strcasecmp($password, (string) $row['email']) === 0)) {
            $error = 'The password must not be the same as your username or email.';
        }
        if ($error) {
            flash('error', $error);
            redirect('set-password', ['token' => $token]);
        }
        db()->beginTransaction();
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
        db()->prepare('UPDATE password_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$row['user_id']]);
        db()->prepare("INSERT INTO activity_log (user_id, action, entity, entity_id, summary, ip) VALUES (?, 'update', 'user', ?, ?, ?)")
            ->execute([$row['user_id'], $row['user_id'], $row['purpose'] === 'setup' ? 'Set password from the login-created email' : 'Reset password from the emailed link', client_ip()]);
        db()->commit();
        db()->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$row['username']]);
        flash('success', "Your password has been set. Sign in with your username {$row['username']} (or your email address).");
        redirect('login');
    }
}

$row = find_password_token($token);
$pageTitle = 'Set your password';
require APP_ROOT . '/app/layout/public_header.php';
?>
<div class="max-w-md mx-auto">
  <?php if (!$row): ?>
    <div class="card text-center py-10">
      <div class="mx-auto w-12 h-12 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center mb-4"><?= icon('warning', 'w-6 h-6') ?></div>
      <h1 class="text-lg font-bold text-slate-900">This link is invalid or has expired</h1>
      <p class="text-sm text-slate-500 mt-2">Password links work once and expire after a limited time. Request a new link below, or ask your district office to resend your login email.</p>
      <a href="<?= e(url('forgot-password')) ?>" class="btn-primary mt-6">Request a new link</a>
    </div>
  <?php else: ?>
    <form method="post" class="card space-y-5" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <div>
        <h1 class="text-lg font-bold text-slate-900"><?= $row['purpose'] === 'setup' ? 'Welcome, ' . e($row['name']) : 'Reset your password' ?></h1>
        <p class="text-sm text-slate-500 mt-1">Choose a password for the username <strong class="font-mono text-slate-700"><?= e($row['username']) ?></strong>.</p>
      </div>
      <input type="text" name="username" value="<?= e($row['username']) ?>" autocomplete="username" class="hidden" readonly>
      <div>
        <label class="label" for="password">New password</label>
        <input class="input" type="password" id="password" name="password" minlength="8" required autocomplete="new-password">
        <span class="hint">At least 8 characters, with a letter and a number.</span>
      </div>
      <div>
        <label class="label" for="password_confirm">Confirm new password</label>
        <input class="input" type="password" id="password_confirm" name="password_confirm" minlength="8" required autocomplete="new-password">
      </div>
      <button class="btn-primary w-full">Set password</button>
      <p class="text-[11px] text-slate-400">This link expires <?= e(date('d M Y, h:i A', strtotime($row['expires_at']))) ?>.</p>
    </form>
  <?php endif; ?>
</div>
<?php require APP_ROOT . '/app/layout/public_footer.php';
