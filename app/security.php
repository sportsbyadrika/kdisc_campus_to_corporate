<?php
declare(strict_types=1);

/* ---------------------------------------------------------------------------
 * Rate limiting
 * ------------------------------------------------------------------------- */

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Count hits for $bucket within the last $seconds. */
function throttle_hits(string $bucket, int $seconds): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM throttle WHERE bucket = ? AND hit_at > NOW() - INTERVAL ? SECOND');
    $st->execute([mb_substr($bucket, 0, 120), $seconds]);
    return (int) $st->fetchColumn();
}

function throttle_hit(string $bucket): void
{
    db()->prepare('INSERT INTO throttle (bucket) VALUES (?)')->execute([mb_substr($bucket, 0, 120)]);
    if (random_int(1, 50) === 1) {
        db()->exec('DELETE FROM throttle WHERE hit_at < NOW() - INTERVAL 2 DAY'); // housekeeping
    }
}

/** Records a hit and returns false when more than $max hits happened within $seconds. */
function throttle_allow(string $bucket, int $max, int $seconds): bool
{
    if (throttle_hits($bucket, $seconds) >= $max) {
        return false;
    }
    throttle_hit($bucket);
    return true;
}

function too_many_requests(string $message = 'Too many requests. Please wait a while and try again.'): never
{
    http_response_code(429);
    header('Retry-After: 900');
    exit(e($message));
}

/* ---------------------------------------------------------------------------
 * One-time password set / reset tokens
 * ------------------------------------------------------------------------- */

/** Create a token for the user (invalidating earlier unused ones) and return the raw token for the email link. */
function create_password_token(int $userId, string $purpose, int $validHours): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('UPDATE password_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
    db()->prepare('INSERT INTO password_tokens (user_id, token_hash, purpose, expires_at) VALUES (?,?,?, NOW() + INTERVAL ? HOUR)')
        ->execute([$userId, hash('sha256', $token), $purpose, $validHours]);
    return $token;
}

/** Look up a valid (unused, unexpired) token row joined with its active user, or null. */
function find_password_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT t.*, u.username, u.name, u.email FROM password_tokens t JOIN users u ON u.id = t.user_id
                         WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW() AND u.is_active = 1');
    $st->execute([hash('sha256', $token)]);
    return $st->fetch() ?: null;
}

/** Returns an error message when the password is too weak, otherwise null. */
function password_policy_error(string $password, string $confirm): ?string
{
    if (strlen($password) < 8) {
        return 'The password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'The password must contain at least one letter and one number.';
    }
    if ($password !== $confirm) {
        return 'The two passwords do not match.';
    }
    return null;
}

/* ---------------------------------------------------------------------------
 * CAPTCHA (self-hosted, GD image) and form timing token
 * ------------------------------------------------------------------------- */

const CAPTCHA_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I/L

/** Render a new CAPTCHA image for $form and keep only its hash in the session. */
function captcha_render(string $form): never
{
    $code = '';
    for ($i = 0; $i < 5; $i++) {
        $code .= CAPTCHA_CHARS[random_int(0, strlen(CAPTCHA_CHARS) - 1)];
    }
    $_SESSION['_captcha'][$form] = ['hash' => hash_hmac('sha256', $code, session_id()), 'exp' => time() + 600];

    $w = 180; $h = 56;
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 240, 247, 255));
    for ($i = 0; $i < 6; $i++) { // noise lines
        $c = imagecolorallocate($img, random_int(120, 200), random_int(150, 210), random_int(180, 230));
        imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
    }
    for ($i = 0; $i < 260; $i++) { // noise dots
        imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), imagecolorallocate($img, random_int(100, 220), random_int(100, 220), random_int(100, 220)));
    }
    for ($i = 0; $i < 5; $i++) { // each character drawn large, rotated and placed with jitter
        $glyph = imagecreatetruecolor(20, 24);
        $bg = imagecolorallocatealpha($glyph, 0, 0, 0, 127);
        imagealphablending($glyph, false);
        imagefill($glyph, 0, 0, $bg);
        imagesavealpha($glyph, true);
        imagestring($glyph, 5, 5, 4, $code[$i], imagecolorallocate($glyph, random_int(10, 60), random_int(40, 90), random_int(90, 150)));
        $scaled = imagescale($glyph, 34, 40);
        $rot = imagerotate($scaled, random_int(-25, 25), imagecolorallocatealpha($scaled, 0, 0, 0, 127));
        imagealphablending($img, true);
        imagecopy($img, $rot, 8 + $i * 33 + random_int(-3, 3), random_int(2, 10), 0, 0, min(imagesx($rot), 40), min(imagesy($rot), 48));
        imagedestroy($glyph); imagedestroy($scaled); imagedestroy($rot);
    }
    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    imagepng($img);
    imagedestroy($img);
    exit;
}

/** Verify (and always consume) the CAPTCHA answer for $form. */
function captcha_verify(string $form, string $answer): bool
{
    $c = $_SESSION['_captcha'][$form] ?? null;
    unset($_SESSION['_captcha'][$form]);
    if (!$c || $c['exp'] < time()) {
        return false;
    }
    $answer = strtoupper(preg_replace('/\s+/', '', $answer));
    return hash_equals($c['hash'], hash_hmac('sha256', $answer, session_id()));
}

/** Signed token recording when a form was rendered (bots tend to submit instantly). */
function form_time_token(): string
{
    $t = (string) time();
    return $t . '.' . hash_hmac('sha256', $t, session_id() . csrf_token());
}

/** True when the form took at least $minSeconds and at most $maxSeconds to fill in. */
function form_time_ok(string $token, int $minSeconds = 5, int $maxSeconds = 7200): bool
{
    [$t, $sig] = array_pad(explode('.', $token, 2), 2, '');
    if (!ctype_digit($t) || !hash_equals(hash_hmac('sha256', $t, session_id() . csrf_token()), $sig)) {
        return false;
    }
    $age = time() - (int) $t;
    return $age >= $minSeconds && $age <= $maxSeconds;
}

/** Strict Content-Security-Policy for the public pages (login, enrollment, password pages). */
function send_public_csp(): void
{
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
}

/* ---------------------------------------------------------------------------
 * Validation helpers
 * ------------------------------------------------------------------------- */

/** Normalise an Indian phone number to 10 digits (drops +91 / 0 prefixes, spaces and dashes). */
function normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    return $digits;
}

function valid_mobile(string $digits): bool
{
    return (bool) preg_match('/^[6-9]\d{9}$/', $digits);
}
