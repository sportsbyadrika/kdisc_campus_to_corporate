<?php
/**
 * Send a test email and print the full SMTP conversation (for diagnosing mail problems).
 *   php database/mail-test.php you@example.com
 * Uses the same .env settings as the website. Passwords are not printed (AUTH lines are base64-masked by PHPMailer).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/bootstrap.php';

$to = $argv[1] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php database/mail-test.php you@example.com\n");
    exit(1);
}
$GLOBALS['config']['mail_debug'] = true;

echo "Driver:   ", config('mail_driver'), "\n";
echo "From:     ", config('mail_from') ?: '(not set)', "\n";
if (config('mail_driver') === 'smtp') {
    echo "SMTP:     ", config('smtp_host'), ':', config('smtp_port'), ' (', config('smtp_secure'), ")\n";
    echo "User:     ", config('smtp_user') ?: '(none)', "\n";
    echo "Password: ", config('smtp_pass') !== '' && config('smtp_pass') !== null ? '(set, ' . strlen((string) config('smtp_pass')) . ' characters)' : '(EMPTY)', "\n";
}
echo "App URL:  ", config('app_url') ?: '(C2C_APP_URL not set)', "\n\n";

$ok = send_mail($to, 'Campus to Corporate - test email', email_layout('Test email', '<p>Outgoing email from the portal is working.</p>'), 'test');
$err = db()->query("SELECT error FROM mail_log WHERE template = 'test' ORDER BY id DESC LIMIT 1")->fetchColumn();
echo "\n", $ok ? "RESULT: sent to the mail server. If it does not arrive, check spam and the domain's SPF/DKIM.\n" : "RESULT: FAILED - {$err}\n";
exit($ok ? 0 : 1);
