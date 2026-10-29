<?php
declare(strict_types=1);

/*
 * Public home (landing) page content. Texts and images are edited by administrators under
 * Settings → Home page; until then the defaults below (the client's design wording) are shown.
 */

/** Image slots: key => [label, hint, max width in px]. Stored under uploads/branding. */
const HOME_IMAGES = [
    'home_hero'         => ['Hero photo', 'People on the right, plain or white background on the left (light style); about 1500 × 820 px.', 2000],
    'home_banner_left'  => ['Left banner (Better Skills…)', 'Landscape photo, about 960 × 280 px.', 1200],
    'home_banner_right' => ['Right banner (Together for a…)', 'Landscape photo, about 840 × 260 px.', 1200],
    'logo_gok'          => ['Government of Kerala emblem', 'PNG with transparent background, about 200 px tall.', 600],
    'logo_kdisc'        => ['K-DISC logo', 'PNG with transparent background, the full logo with its name.', 800],
    'logo_kkem'         => ['KKEM logo', 'PNG with transparent background, the full logo with its name.', 800],
];

/** Editable texts: key => [label, default, input type]. */
const HOME_TEXTS = [
    'home_hero_theme'   => ['Hero style', 'light', 'select'],
    'home_eyebrow'      => ['Hero: small heading', 'A Statewide Employment Execution Mission', 'text'],
    'home_subtitle'     => ['Hero: subtitle', 'From Higher Education to Meaningful Employment', 'text'],
    'home_intro'        => ['Hero: introduction', "Connecting Kerala's final-year students with the skills, opportunities and industry connections they need to move confidently from campus to corporate life.", 'textarea'],
    'home_stat1_value'  => ['Target 1: number', '30,000', 'text'],
    'home_stat1_label'  => ['Target 1: label', 'Final-year student registration target', 'text'],
    'home_stat2_value'  => ['Target 2: number', '20,000', 'text'],
    'home_stat2_label'  => ['Target 2: label', 'Placement target', 'text'],
    'home_stat_note'    => ['Targets: note', 'Targets as stated in the programme concept note; not achieved results.', 'text'],
    'home_tagline'      => ['Hero: handwritten tagline', 'Skilled Youth, Stronger Kerala', 'text'],
    'home_about'        => ['About the programme', 'Campus to Corporate is a statewide employment mission by K-DISC, under the Kerala Knowledge Economy Mission (KKEM), designed to ensure that every final-year student is assessed, prepared and connected to real employment opportunities.', 'textarea'],
    'contact_email'     => ['Contact email', 'campustocorporate@kdisc.kerala.gov.in', 'text'],
    'contact_location'  => ['Contact location', 'Thiruvananthapuram, Kerala', 'text'],
    'social_youtube'    => ['YouTube link', '', 'url'],
    'social_linkedin'   => ['LinkedIn link', '', 'url'],
    'social_x'          => ['X (Twitter) link', '', 'url'],
    'privacy_url'       => ['Privacy Policy link', '', 'url'],
    'terms_url'         => ['Terms of Use link', '', 'url'],
    'accessibility_url' => ['Accessibility link', '', 'url'],
];

/** Hero styles: light = white background with dark labels (for a photo on white), dark = navy with white labels. */
const HOME_HERO_THEMES = ['light' => 'Light: white background, dark labels', 'dark' => 'Dark: navy background, white labels'];

/** Texts that may be left empty to hide them. */
const HOME_OPTIONAL_TEXTS = ['home_eyebrow', 'home_stat_note', 'home_tagline'];

function home_text(string $key): string
{
    return setting($key, HOME_TEXTS[$key][1] ?? '');
}

/** Public URL of an uploaded home-page image, or null when none was uploaded. */
function home_image(string $key): ?string
{
    return upload_url(setting($key) ?: null);
}

/** Store an uploaded PDF under uploads/updates (max 10 MB). Returns the relative path or null when none was sent. */
function store_pdf_upload(string $field): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . (int) $f['error'] . ').');
    }
    if ($f['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('The PDF must be smaller than 10 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if ($mime !== 'application/pdf' || file_get_contents($f['tmp_name'], false, null, 0, 5) !== '%PDF-') {
        throw new RuntimeException('Only PDF files can be attached.');
    }
    $dir = APP_ROOT . '/uploads/updates';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Upload folder is not writable.');
    }
    $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.pdf';
    if (!move_uploaded_file($f['tmp_name'], "{$dir}/{$name}")) {
        throw new RuntimeException('The PDF could not be saved.');
    }
    return 'updates/' . $name;
}

/** Active updates, newest first. */
function site_updates(?int $limit = null): array
{
    $sql = 'SELECT * FROM site_updates WHERE is_active = 1 ORDER BY published_on DESC, id DESC' . ($limit ? ' LIMIT ' . (int) $limit : '');
    return db()->query($sql)->fetchAll();
}

/** Where an update links to: its PDF, else its link, else nothing. */
function site_update_href(array $u): ?string
{
    return $u['file'] ? upload_url($u['file']) : ($u['link_url'] ?: null);
}

/** Simplified outline of Kerala (viewBox 0 0 100 300), used as a decorative shape. */
const KERALA_PATH = 'M20 0L40 6L46 30L54 62L64 98L72 132L80 166L88 200L96 236L98 262L92 284L82 300L72 292L62 266L50 234L40 200L30 166L20 132L12 98L4 62L0 30L6 8z';

/**
 * A partner logo: the uploaded image, or a drawn stand-in with the organisation's name
 * until one is uploaded. $onDark renders it white for the footer.
 */
function home_logo(string $key, bool $onDark = false): string
{
    $names = [
        'logo_gok'   => ['Government of Kerala', ''],
        'logo_kdisc' => ['K-DISC', 'Kerala Development and Innovation Strategic Council'],
        'logo_kkem'  => ['KKEM', 'Kerala Knowledge Economy Mission'],
    ];
    [$name, $sub] = $names[$key];
    if ($src = home_image($key)) {
        return '<img src="' . e($src) . '" alt="' . e($name) . '" class="' . ($key === 'logo_gok' ? 'h-14' : 'h-11') . ' w-auto object-contain' . ($onDark ? ' brightness-0 invert' : '') . '">';
    }
    $ink = $onDark ? 'text-white' : 'text-c2c-navy';
    $soft = $onDark ? 'text-white/75' : 'text-slate-500';
    if ($key === 'logo_gok') {
        return '<span class="flex flex-col items-center leading-none">'
            . '<svg viewBox="0 0 40 40" class="w-10 h-10 ' . $ink . '" aria-hidden="true"><circle cx="20" cy="20" r="18" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M12 28h16M14 28V17m4 11V17m4 11V17m4 11V17M11 17h18l-9-6z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>'
            . '<span class="mt-1 text-[9px] font-semibold ' . $ink . '">' . e($name) . '</span></span>';
    }
    $mark = $key === 'logo_kdisc'
        ? '<svg viewBox="0 0 40 40" class="w-10 h-10 shrink-0" aria-hidden="true"><circle cx="20" cy="20" r="13" fill="none" stroke="' . ($onDark ? '#fff' : '#0b3a5c') . '" stroke-width="5" stroke-dasharray="5 3"/><circle cx="20" cy="20" r="6" fill="#0f9a92"/></svg>'
        : '<svg viewBox="0 0 40 40" class="w-10 h-10 shrink-0" aria-hidden="true"><path d="M20 4L37 35H3z" fill="#0f9a92"/><path d="M20 14l10 21H10z" fill="' . ($onDark ? '#fff' : '#0b3a5c') . '"/><path d="M20 24l5 11H15z" fill="#7cc242"/></svg>';
    return '<span class="flex items-center gap-2">' . $mark
        . '<span class="leading-tight"><span class="block font-display text-lg font-bold ' . $ink . '">' . e($name) . '</span>'
        . '<span class="hidden 2xl:block text-[9px] max-w-[9.5rem] ' . $soft . '">' . e($sub) . '</span></span></span>';
}
