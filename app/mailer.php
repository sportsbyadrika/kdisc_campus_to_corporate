<?php
declare(strict_types=1);

/**
 * Outgoing email via PHPMailer (vendor/phpmailer, committed so cPanel needs no Composer run).
 *
 * Drivers (C2C_MAIL_DRIVER): "mail" (PHP mail()), "sendmail", "smtp" (SSL on 465 or STARTTLS on 587),
 * "log" (development: nothing is sent, the message is stored in mail_log).
 * Every attempt is recorded in mail_log (bodies only for the log driver, since they can hold links).
 */

/** Absolute URL for links inside emails (never built from the request's Host header when configured). */
function absolute_url(string $path, array $query = []): string
{
    $base = config('app_url');
    if (!$base) {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $base = ($https ? 'https' : 'http') . '://' . $host;
    }
    return $base . url($path, $query);
}

/**
 * Send one email through PHPMailer. Returns true on success (or when recorded by the log driver).
 * $html is the HTML body; a plain-text alternative is generated from $text or from the HTML.
 */
function send_mail(string $to, string $subject, string $html, string $template, ?string $text = null): bool
{
    $to = trim($to);
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
    $driver = config('mail_driver', 'mail');
    $error = null;

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid recipient address';
    } elseif ($driver !== 'log') {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;
            $mail->XMailer = ' '; // do not advertise the mailer library
            if ($driver === 'smtp') {
                $mail->isSMTP();
                $mail->Host = (string) config('smtp_host');
                $mail->Port = (int) config('smtp_port', 587);
                $mail->Timeout = 20;
                $secure = config('smtp_secure', 'tls');
                $mail->SMTPSecure = $secure === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                    : ($secure === 'tls' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
                $mail->SMTPAutoTLS = $secure !== 'none';
                if ((string) config('smtp_user') !== '') {
                    $mail->SMTPAuth = true;
                    $mail->Username = (string) config('smtp_user');
                    $mail->Password = (string) config('smtp_pass');
                }
            } elseif ($driver === 'sendmail') {
                $mail->isSendmail();
            } else {
                $mail->isMail();
            }
            $fromEmail = config('mail_from') ?: 'no-reply@' . (parse_url(absolute_url(''), PHP_URL_HOST) ?: 'localhost');
            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || !str_contains(substr(strrchr($fromEmail, '@'), 1), '.')) {
                throw new RuntimeException('Set C2C_MAIL_FROM in .env to a valid sender address on your domain');
            }
            $mail->setFrom($fromEmail, (string) config('mail_from_name', 'Campus to Corporate'));
            $mail->addAddress($to);
            $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text ?? html_to_text($html);
            $mail->send();
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 500);
            error_log("C2C mail to {$to} failed: {$error}");
        }
    }

    try {
        db()->prepare('INSERT INTO mail_log (recipient, subject, template, status, error, body) VALUES (?,?,?,?,?,?)')
            ->execute([mb_substr($to, 0, 160), mb_substr($subject, 0, 255), $template,
                $error ? 'failed' : ($driver === 'log' ? 'logged' : 'sent'), $error, $driver === 'log' ? $html : null]);
    } catch (Throwable $e) {
        error_log('C2C mail_log insert failed: ' . $e->getMessage());
    }
    return $error === null;
}

function html_to_text(string $html): string
{
    $html = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html);
    $html = preg_replace('#<(br|/p|/div|/tr|/h[1-6]|/li)[^>]*>#i', "\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text)));
}

/** Branded HTML email wrapper. $bodyHtml must already be escaped. */
function email_layout(string $title, string $bodyHtml): string
{
    $app = e(setting('app_name', 'Campus to Corporate'));
    return '<!doctype html><html><body style="margin:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px"><tr><td align="center">'
        . '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0">'
        . '<tr><td style="background:#0c4a6e;padding:20px 28px;color:#ffffff"><div style="font-size:18px;font-weight:bold">' . $app . '</div>'
        . '<div style="font-size:12px;color:#bae6fd">' . e(setting('app_tagline')) . '</div></td></tr>'
        . '<tr><td style="padding:28px"><h1 style="font-size:18px;margin:0 0 16px">' . e($title) . '</h1>'
        . '<div style="font-size:14px;line-height:1.6">' . $bodyHtml . '</div></td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f8fafc;font-size:11px;color:#64748b;border-top:1px solid #e2e8f0">'
        . 'This is an automated message from ' . $app . '. Please do not reply to this email.<br>' . e(setting('footer_right')) . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function email_button(string $href, string $label): string
{
    return '<p style="margin:24px 0"><a href="' . e($href) . '" style="background:#0284c7;color:#ffffff;text-decoration:none;'
        . 'padding:12px 22px;border-radius:10px;font-weight:bold;display:inline-block">' . e($label) . '</a></p>'
        . '<p style="font-size:12px;color:#64748b">If the button does not work, copy this link into your browser:<br>'
        . '<span style="word-break:break-all">' . e($href) . '</span></p>';
}
