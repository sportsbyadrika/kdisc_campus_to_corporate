<?php
/**
 * "Forgot password": emails a one-time reset link. The response is identical whether or not
 * the account exists, so the page cannot be used to discover usernames or email addresses.
 */
require __DIR__ . '/app/bootstrap.php';

const RESET_LINK_HOURS = 2;

if (current_user()) {
    redirect('dashboard');
}

if (is_post()) {
    verify_csrf();
    if (!throttle_allow('forgot:' . client_ip(), 10, 3600)) {
        too_many_requests();
    }
    if (!form_time_ok((string) ($_POST['_ft'] ?? ''), 2) || trim((string) ($_POST['website'] ?? '')) !== '') {
        flash('error', 'Please try again.');
        redirect('forgot-password');
    }
    if (!captcha_verify('forgot', (string) ($_POST['captcha'] ?? ''))) {
        flash('error', 'The verification code was incorrect. Please type the characters shown in the new image.');
        redirect('forgot-password');
    }
    $id = mb_substr(strtolower(trim((string) ($_POST['identifier'] ?? ''))), 0, 160);
    if ($id !== '' && throttle_allow('forgot-id:' . hash('sha256', $id), 3, 3600)) {
        $st = db()->prepare("SELECT * FROM users WHERE is_active = 1 AND email IS NOT NULL AND email <> '' AND (username = ? OR email = ?)");
        $st->execute([$id, $id]);
        foreach ($st->fetchAll() as $user) {
            $token = create_password_token((int) $user['id'], 'reset', RESET_LINK_HOURS);
            $body = '<p>Dear ' . e($user['name']) . ',</p>'
                . '<p>We received a request to reset the password for the username <strong>' . e($user['username']) . '</strong>.</p>'
                . email_button(absolute_url('set-password', ['token' => $token]), 'Reset my password')
                . '<p>The link works once and expires in ' . RESET_LINK_HOURS . ' hours. If you did not ask for this, you can ignore this email - your password will not change.</p>';
            send_mail($user['email'], 'Reset your Campus to Corporate password', email_layout('Reset your password', $body), 'password_reset');
        }
    }
    flash('success', 'If an account matches the details you entered, a password reset link has been sent to its registered email address. Please check your inbox (and spam folder).');
    redirect('forgot-password');
}

$pageTitle = 'Forgot password';
$publicScripts = ['assets/js/captcha.js'];
require APP_ROOT . '/app/layout/public_header.php';
?>
<div class="max-w-md mx-auto">
  <form method="post" class="card space-y-5" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="_ft" value="<?= e(form_time_token()) ?>">
    <div class="absolute -left-[9999px] w-px h-px overflow-hidden" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <div>
      <h1 class="text-lg font-bold text-slate-900">Forgot your password?</h1>
      <p class="text-sm text-slate-500 mt-1">Enter your username or registered email address and we'll email you a link to set a new password.</p>
    </div>
    <div>
      <label class="label" for="identifier">Username or email</label>
      <input class="input" id="identifier" name="identifier" maxlength="160" required autocomplete="username">
    </div>
    <div class="flex items-end gap-3">
      <div class="flex items-center gap-2">
        <img id="captcha-img" src="<?= e(url('captcha', ['form' => 'forgot', 't' => time()])) ?>" alt="Verification code" width="180" height="56" class="rounded-lg border border-slate-200">
        <button type="button" id="captcha-refresh" class="p-2 rounded-lg text-slate-500 hover:bg-slate-100" title="Show a new code" aria-label="Show a new code" data-src="<?= e(url('captcha', ['form' => 'forgot'])) ?>">↻</button>
      </div>
    </div>
    <div>
      <label class="label" for="captcha">Type the characters</label>
      <input class="input font-mono uppercase tracking-widest" id="captcha" name="captcha" maxlength="8" required autocomplete="off" autocapitalize="characters" spellcheck="false">
    </div>
    <button class="btn-primary w-full">Email me a reset link</button>
    <p class="text-center text-sm"><a class="link" href="<?= e(url('login')) ?>">Back to sign in</a></p>
  </form>
</div>
<?php require APP_ROOT . '/app/layout/public_footer.php';
