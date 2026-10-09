<?php
declare(strict_types=1);

/* ---------------------------------------------------------------------------
 * Output & URLs
 * ------------------------------------------------------------------------- */

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an extension-less application URL: url('institutions', ['district' => 3]) */
function url(string $path = '', array $query = []): string
{
    $path = ltrim($path, '/');
    $u = config('base_path') . '/' . $path;
    $query = array_filter($query, fn($v) => $v !== null && $v !== '');
    return $u . ($query ? '?' . http_build_query($query) : '');
}

function asset(string $path): string
{
    $file = APP_ROOT . '/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 0;
    return url($path) . '?v=' . $v;
}

function upload_url(?string $path): ?string
{
    return $path ? url('uploads/' . $path) : null;
}

function redirect(string $path, array $query = []): never
{
    header('Location: ' . url($path, $query));
    exit;
}

function current_page(): string
{
    $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '', '.php');
    return $script === 'index' ? '' : $script;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, mixed $default = ''): mixed
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function int_input(string $key, ?int $default = null): ?int
{
    $v = $_POST[$key] ?? $_GET[$key] ?? null;
    if ($v === null || $v === '' || !is_numeric($v)) {
        return $default;
    }
    return (int) $v;
}

function nullable(?string $v): ?string
{
    $v = $v === null ? null : trim($v);
    return $v === '' ? null : $v;
}

/* ---------------------------------------------------------------------------
 * CSRF & flash messages
 * ------------------------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        flash('error', 'Your session has expired. Please try again.');
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? url('')));
        exit;
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/* ---------------------------------------------------------------------------
 * Settings & lookups
 * ------------------------------------------------------------------------- */

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT `key`, `value` FROM settings') as $row) {
            $cache[$row['key']] = (string) $row['value'];
        }
    }
    return $cache[$key] ?? $default;
}

function academic_year(): string
{
    return setting('academic_year', date('Y') . '-' . substr((string) (date('Y') + 1), 2));
}

function districts(): array
{
    static $rows = null;
    return $rows ??= db()->query('SELECT * FROM districts ORDER BY sort_order, name')->fetchAll();
}

function district_name(?int $id): string
{
    foreach (districts() as $d) {
        if ((int) $d['id'] === $id) {
            return $d['name'];
        }
    }
    return '—';
}

function lookup(string $table, bool $activeOnly = true): array
{
    $allowed = ['universities', 'institution_categories', 'courses', 'dwms_services'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Unknown lookup table');
    }
    $sql = "SELECT * FROM {$table}" . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY ' .
        ($table === 'dwms_services' ? 'sort_order, name' : 'name');
    return db()->query($sql)->fetchAll();
}

/* ---------------------------------------------------------------------------
 * Activity log (audit trail)
 * ------------------------------------------------------------------------- */

/** Compare two associative arrays and return {field: [old, new]} for changed keys. */
function diff_changes(array $old, array $new, array $labels = []): array
{
    $changes = [];
    foreach ($new as $k => $v) {
        $o = $old[$k] ?? null;
        if (is_numeric($o) && is_numeric($v) && (float) $o === (float) $v) {
            continue;
        }
        if ((string) ($o ?? '') !== (string) ($v ?? '')) {
            $changes[$labels[$k] ?? $k] = [$o, $v];
        }
    }
    return $changes;
}

function log_activity(string $action, string $entity, ?int $entityId, string $summary,
                      array $changes = [], ?int $institutionId = null, ?int $districtId = null): void
{
    $user = current_user();
    if ($institutionId && !$districtId) {
        $st = db()->prepare('SELECT district_id FROM institutions WHERE id = ?');
        $st->execute([$institutionId]);
        $districtId = (int) $st->fetchColumn() ?: null;
    }
    $st = db()->prepare('INSERT INTO activity_log (user_id, institution_id, district_id, action, entity, entity_id, summary, changes, ip)
                         VALUES (?,?,?,?,?,?,?,?,?)');
    $st->execute([
        $user['id'] ?? null, $institutionId, $districtId, $action, $entity, $entityId,
        mb_substr($summary, 0, 500), $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

/* ---------------------------------------------------------------------------
 * File uploads (institution logo / photo)
 * ------------------------------------------------------------------------- */

/**
 * Validate and store an uploaded image. Returns the relative path (inside /uploads)
 * or null when no file was submitted. Throws RuntimeException with a user-facing
 * message on invalid input.
 */
function store_image_upload(string $field, string $folder, int $maxWidth = 1600): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . (int) $f['error'] . ').');
    }
    if ($f['size'] > config('upload_max_bytes')) {
        throw new RuntimeException('Image must be smaller than ' . round(config('upload_max_bytes') / 1048576, 1) . ' MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext || @getimagesize($f['tmp_name']) === false) {
        throw new RuntimeException('Only JPG, PNG or WEBP images are allowed.');
    }
    $dir = APP_ROOT . '/uploads/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Upload folder is not writable.');
    }
    $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $name;

    // Re-encode through GD when available: strips metadata and any embedded payloads.
    if (function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring((string) file_get_contents($f['tmp_name']));
        if ($img === false) {
            throw new RuntimeException('The image could not be processed.');
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w > $maxWidth) {
            $nh = (int) round($h * $maxWidth / $w);
            $resized = imagecreatetruecolor($maxWidth, $nh);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $maxWidth, $nh, $w, $h);
            imagedestroy($img);
            $img = $resized;
        } else {
            imagesavealpha($img, true);
        }
        $ok = match ($ext) {
            'jpg'  => imagejpeg($img, $dest, 85),
            'png'  => imagepng($img, $dest, 6),
            'webp' => imagewebp($img, $dest, 85),
        };
        imagedestroy($img);
        if (!$ok) {
            throw new RuntimeException('The image could not be saved.');
        }
    } elseif (!move_uploaded_file($f['tmp_name'], $dest)) {
        throw new RuntimeException('The image could not be saved.');
    }
    return $folder . '/' . $name;
}

function delete_upload(?string $relative): void
{
    if (!$relative) {
        return;
    }
    $path = realpath(APP_ROOT . '/uploads/' . $relative);
    $base = realpath(APP_ROOT . '/uploads');
    if ($path && $base && str_starts_with($path, $base . DIRECTORY_SEPARATOR) && is_file($path)) {
        @unlink($path);
    }
}

/* ---------------------------------------------------------------------------
 * Formatting
 * ------------------------------------------------------------------------- */

function num(int|float|null $n): string
{
    return number_format((float) ($n ?? 0));
}

function pct(int|float|null $part, int|float|null $whole, int $cap = 100): int
{
    if (!$whole) {
        return 0;
    }
    return (int) min($cap, round(((float) $part / (float) $whole) * 100));
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\s]/u', ' ', $name)));
    $parts = array_values(array_filter($parts, fn($p) => mb_strlen($p) > 1 || count($parts) === 1));
    $i = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
    return $i ?: '?';
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return 'never';
    }
    $diff = time() - strtotime($datetime);
    return match (true) {
        $diff < 60     => 'just now',
        $diff < 3600   => floor($diff / 60) . ' min ago',
        $diff < 86400  => floor($diff / 3600) . ' hr ago',
        $diff < 604800 => floor($diff / 86400) . ' days ago',
        default        => date('d M Y', strtotime($datetime)),
    };
}

/** Tailwind classes for the colour tags used by DWMS services (whitelisted so the CSS build sees them). */
function tag_classes(string $color): string
{
    return [
        'sky'     => 'bg-sky-100 text-sky-700',
        'emerald' => 'bg-emerald-100 text-emerald-700',
        'indigo'  => 'bg-indigo-100 text-indigo-700',
        'purple'  => 'bg-purple-100 text-purple-700',
        'amber'   => 'bg-amber-100 text-amber-700',
        'rose'    => 'bg-rose-100 text-rose-700',
        'slate'   => 'bg-slate-100 text-slate-700',
    ][$color] ?? 'bg-slate-100 text-slate-700';
}

function status_badge(string $status): string
{
    $map = [
        'Onboarded'   => 'bg-emerald-100 text-emerald-700',
        'Active'      => 'bg-emerald-100 text-emerald-700',
        'In Progress' => 'bg-amber-100 text-amber-800',
        'Not Started' => 'bg-slate-100 text-slate-600',
        'Inactive'    => 'bg-rose-100 text-rose-700',
        'Completed'   => 'bg-emerald-100 text-emerald-700',
        'Scheduled'   => 'bg-sky-100 text-sky-700',
        'Requested'   => 'bg-indigo-100 text-indigo-700',
        'pending'     => 'bg-amber-100 text-amber-800',
        'approved'    => 'bg-emerald-100 text-emerald-700',
        'rejected'    => 'bg-rose-100 text-rose-700',
    ];
    $cls = $map[$status] ?? 'bg-slate-100 text-slate-600';
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold whitespace-nowrap ' . $cls . '">' . e(ucfirst($status)) . '</span>';
}

/** Inline SVG icons (Heroicons outline paths used throughout the design). */
function icon(string $name, string $class = 'w-4 h-4'): string
{
    $paths = [
        'home'      => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
        'building'  => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
        'chart'     => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        'users'     => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
        'user'      => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
        'cog'       => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
        'database'  => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4',
        'inbox'     => 'M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4',
        'clock'     => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
        'logout'    => 'M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1',
        'plus'      => 'M12 4v16m8-8H4',
        'trash'     => 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
        'pencil'    => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
        'check'     => 'M5 13l4 4L19 7',
        'check-circle' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        'x'         => 'M6 18L18 6M6 6l12 12',
        'arrow-right' => 'M14 5l7 7m0 0l-7 7m7-7H3',
        'chevron-right' => 'M9 5l7 7-7 7',
        'info'      => 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'warning'   => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
        'academic'  => 'M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z',
        'briefcase' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
        'map'       => 'M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z M15 11a3 3 0 11-6 0 3 3 0 016 0z',
        'printer'   => 'M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z',
        'download'  => 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4',
        'search'    => 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
        'menu'      => 'M4 6h16M4 12h16M4 18h16',
        'key'       => 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z',
        'bolt'      => 'M13 10V3L4 14h7v7l9-11h-7z',
        'globe'     => 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'clipboard' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
    ];
    $d = $paths[$name] ?? $paths['info'];
    $out = '';
    foreach (explode(' M', $d) as $i => $seg) {
        $out .= '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' . ($i ? 'M' : '') . $seg . '"/>';
    }
    return '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">' . $out . '</svg>';
}

/* ---------------------------------------------------------------------------
 * District support team roles (shown on the district master and institution dashboards)
 * ------------------------------------------------------------------------- */

const SUPPORT_ROLES = [
    'tce' => [
        'short'   => 'TCE',
        'full'    => 'Talent Connect Executive',
        'purpose' => 'Field executive assigned to the institutions of the district. Day-to-day point of contact for the placement cell: drives DWMS registration, the English Score / assessment drives, mock interviews and the rollout of DWMS services.',
    ],
    'rpm' => [
        'short'   => 'RPM',
        'full'    => 'Regional Programme Manager',
        'purpose' => 'Manages programme delivery for the region the district belongs to. Supervises the TCEs, monitors institution onboarding and cohort progress, resolves escalations and coordinates placement drives.',
    ],
    'rh' => [
        'short'   => 'Regional Head',
        'full'    => 'Regional Head',
        'purpose' => 'Senior lead for the region (South / Central / North). Oversees the RPMs and TCEs, reviews regional performance and liaises with the State head office on Campus to Corporate operations.',
    ],
];

/**
 * Small info icon with an accessible tooltip (hover, keyboard focus, or tap on touch screens).
 * $align: 'left' anchors the bubble to the icon's left edge, 'right' to its right edge.
 */
function tooltip(string $title, string $body, string $align = 'left'): string
{
    $pos = $align === 'right' ? 'right-0' : 'left-0';
    return '<span class="tip group relative inline-flex align-middle" tabindex="0" aria-label="' . e($title . ': ' . $body) . '">'
        . '<span class="text-slate-400 group-hover:text-sky-600 group-focus:text-sky-600 cursor-help">' . icon('info', 'w-3.5 h-3.5') . '</span>'
        . '<span role="tooltip" class="tip-body ' . $pos . '">'
        . '<span class="block font-bold text-white">' . e($title) . '</span>'
        . '<span class="block mt-0.5 text-slate-300">' . e($body) . '</span></span></span>';
}

/** Tooltip describing one support role: "TCE — Talent Connect Executive" + purpose. */
function role_tooltip(string $key, string $align = 'left'): string
{
    $r = SUPPORT_ROLES[$key];
    $title = $r['short'] === $r['full'] ? $r['full'] : $r['short'] . ' — ' . $r['full'];
    return tooltip($title, $r['purpose'], $align);
}

/** Settings keys that switch optional support roles on/off (managed by the super admin only). */
const SUPPORT_ROLE_SETTINGS = [
    'rpm' => 'show_rpm',
    'rh'  => 'show_regional_head',
];

/** Whether a support role is shown in the portal. TCE is always shown; RPM / Regional Head are hidden by default. */
function support_role_visible(string $key): bool
{
    return !isset(SUPPORT_ROLE_SETTINGS[$key]) || setting(SUPPORT_ROLE_SETTINGS[$key], '0') === '1';
}

/** Keys of SUPPORT_ROLES that are currently visible, in display order. */
function visible_support_roles(): array
{
    return array_values(array_filter(array_keys(SUPPORT_ROLES), 'support_role_visible'));
}

/** Tailwind grid-column class for n items (literal strings so the CSS build picks them up). */
function grid_cols_class(int $n, string $prefix = ''): string
{
    $map = ['' => [1 => 'grid-cols-1', 2 => 'grid-cols-2', 3 => 'grid-cols-3'],
            'md:' => [1 => 'md:grid-cols-1', 2 => 'md:grid-cols-2', 3 => 'md:grid-cols-3']];
    return $map[$prefix][max(1, min(3, $n))];
}

/** Validation message for a DWMS institution id, or null when it is valid and not used by another institution. */
function dwms_id_error(?string $dwmsId, int $institutionId = 0): ?string
{
    if ($dwmsId === null || $dwmsId === '') {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_\/.]{0,39}$/', $dwmsId)) {
        return 'DWMS Institution ID may contain only letters and numbers (and - _ / .), up to 40 characters.';
    }
    $st = db()->prepare('SELECT name FROM institutions WHERE dwms_id = ? AND id <> ?');
    $st->execute([$dwmsId, $institutionId]);
    $other = $st->fetchColumn();
    return $other ? "DWMS Institution ID {$dwmsId} is already assigned to {$other}." : null;
}
