<?php
/**
 * Bulk upload of institutions (Administrator and Super Admin).
 *
 *  1. Download the CSV template (and the reference list of valid master values).
 *  2. Upload the filled file: every row is checked and shown in a preview as Ready or Issue.
 *  3. Import the Ready rows. Rows with issues can be downloaded as a CSV with an "Issues"
 *     column, corrected and uploaded again later (the extra column is ignored).
 *
 * Only new institutions are added; a row matching an existing institution (same name in the
 * same district, or the same DWMS Institution ID) is reported as an issue.
 */
require __DIR__ . '/app/bootstrap.php';
$user = require_role('admin');

const INST_UPLOAD_MAX_ROWS = 5000;
const INST_UPLOAD_SESSION = '_inst_upload';

/** Template columns: key => [header, required, note shown on the page]. */
const INST_UPLOAD_COLUMNS = [
    'name'             => ['Institution Name', true, 'Up to 255 characters'],
    'code'             => ['Affiliation Code', true, 'Up to 40 characters'],
    'dwms_id'          => ['DWMS Institution ID', false, 'Unique; letters, numbers and - _ / .'],
    'district'         => ['District', true, 'One of the 14 districts'],
    'university'       => ['Affiliated University', true, 'Name or short name, as in Masters'],
    'category'         => ['Institution Category', true, 'Management type, as in Masters'],
    'type'             => ['Institution Type', true, 'As in Masters'],
    'email'            => ['Email', false, 'Institution email'],
    'phone'            => ['Phone', false, 'Landline or mobile'],
    'website'          => ['Website', false, ''],
    'address'          => ['Address', false, 'Up to 400 characters'],
    'pincode'          => ['Pincode', false, '6 digits'],
    'established_year' => ['Established Year', false, 'e.g. 1995'],
    'head_name'        => ['Head of Institution Name', false, ''],
    'head_designation' => ['Head Designation', false, 'e.g. Principal'],
    'head_email'       => ['Head Email', false, ''],
    'head_phone'       => ['Head Mobile', false, '10-digit mobile'],
    'latitude'         => ['Latitude', false, 'Decimal, e.g. 8.5241'],
    'longitude'        => ['Longitude', false, 'Decimal, e.g. 76.9366'],
];

$norm = fn(?string $s): string => preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim((string) $s)));

// Master values, matched by name (or short name / id) ignoring case and punctuation
$masters = ['district' => [], 'university' => [], 'category' => [], 'type' => []];
foreach (districts() as $d) {
    $masters['district'][$norm($d['name'])] = ['id' => (int) $d['id'], 'name' => $d['name']];
}
foreach (lookup('universities') as $u) {
    foreach ([$u['name'], $u['short_name'], (string) $u['id'], $u['short_name'] ? "{$u['name']} ({$u['short_name']})" : ''] as $k) {   // the last form is how the reference file lists it
        if ($k !== null && $k !== '') $masters['university'][$norm($k)] = ['id' => (int) $u['id'], 'name' => $u['short_name'] ?: $u['name']];
    }
}
foreach (lookup('institution_categories') as $c) {
    foreach ([$c['name'], (string) $c['id']] as $k) $masters['category'][$norm($k)] = ['id' => (int) $c['id'], 'name' => $c['name']];
}
foreach (lookup('institution_types') as $t) {
    foreach ([$t['name'], (string) $t['id']] as $k) $masters['type'][$norm($k)] = ['id' => (int) $t['id'], 'name' => $t['name']];
}

/** Send a CSV download and stop. */
function send_csv(string $filename, array $header, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows Malayalam / accented text correctly
    fputcsv($out, $header);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

/**
 * Check one row. Returns [institution columns for INSERT, issues].
 * $seen tracks names / DWMS IDs already used earlier in the same file.
 */
function check_institution_row(array $raw, array $masters, callable $norm, array &$seen): array
{
    $v = fn(string $k): string => trim((string) ($raw[$k] ?? ''));
    $issues = [];
    $d = [
        'name' => preg_replace('/\s+/', ' ', $v('name')),
        'code' => $v('code'),
        'dwms_id' => $v('dwms_id') ?: null,
        'email' => strtolower($v('email')) ?: null,
        'phone' => $v('phone') ?: null,
        'website' => $v('website') ?: null,
        'address' => $v('address') ?: null,
        'pincode' => preg_replace('/\s+/', '', $v('pincode')) ?: null,
        'established_year' => $v('established_year') !== '' ? $v('established_year') : null,
        'head_name' => $v('head_name') ?: null,
        'head_designation' => $v('head_designation') ?: null,
        'head_email' => strtolower($v('head_email')) ?: null,
        'head_phone' => $v('head_phone') !== '' ? normalize_phone($v('head_phone')) : null,
        'latitude' => $v('latitude') !== '' ? $v('latitude') : null,
        'longitude' => $v('longitude') !== '' ? $v('longitude') : null,
    ];

    if ($d['name'] === '') $issues[] = 'Institution Name is required';
    elseif (mb_strlen($d['name']) > 255) $issues[] = 'Institution Name is longer than 255 characters';
    if ($d['code'] === '') $issues[] = 'Affiliation Code is required';
    elseif (mb_strlen($d['code']) > 40) $issues[] = 'Affiliation Code is longer than 40 characters';

    $labels = ['district' => 'District', 'university' => 'Affiliated University', 'category' => 'Institution Category', 'type' => 'Institution Type'];
    $columns = ['district' => 'district_id', 'university' => 'university_id', 'category' => 'category_id', 'type' => 'type_id'];
    foreach ($labels as $k => $label) {
        $given = $v($k);
        $match = $masters[$k][$norm($given)] ?? null;
        if ($given === '') $issues[] = "{$label} is required";
        elseif (!$match) $issues[] = "{$label} “{$given}” is not in the master list";
        $d[$columns[$k]] = $match['id'] ?? null;
    }

    if ($d['dwms_id'] !== null && ($err = dwms_id_error($d['dwms_id']))) $issues[] = rtrim($err, '.');
    if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $issues[] = 'Email is not valid';
    if ($d['head_email'] && !filter_var($d['head_email'], FILTER_VALIDATE_EMAIL)) $issues[] = 'Head Email is not valid';
    if ($d['head_phone'] !== null && !valid_mobile($d['head_phone'])) $issues[] = 'Head Mobile must be a 10-digit mobile number';
    if ($d['phone'] !== null && !preg_match('/^[0-9+\-\s()\/,]{6,30}$/', $d['phone'])) $issues[] = 'Phone may contain only digits, spaces and + - ( ) /';
    if ($d['website'] !== null && mb_strlen($d['website']) > 200) $issues[] = 'Website is longer than 200 characters';
    if ($d['address'] !== null && mb_strlen($d['address']) > 400) $issues[] = 'Address is longer than 400 characters';
    if ($d['pincode'] !== null && !preg_match('/^\d{6}$/', $d['pincode'])) $issues[] = 'Pincode must be 6 digits';
    if ($d['established_year'] !== null && (!ctype_digit($d['established_year']) || (int) $d['established_year'] < 1800 || (int) $d['established_year'] > (int) date('Y'))) $issues[] = 'Established Year must be a year between 1800 and ' . date('Y');
    foreach (['head_name' => 'Head of Institution Name', 'head_designation' => 'Head Designation', 'head_email' => 'Head Email'] as $k => $label) {
        if ($d[$k] !== null && mb_strlen($d[$k]) > 160) $issues[] = "{$label} is longer than 160 characters";
    }
    if ($d['latitude'] !== null && (!is_numeric($d['latitude']) || $d['latitude'] < 8 || $d['latitude'] > 13)) $issues[] = 'Latitude must be a decimal number within Kerala (8 to 13)';
    if ($d['longitude'] !== null && (!is_numeric($d['longitude']) || $d['longitude'] < 74 || $d['longitude'] > 78)) $issues[] = 'Longitude must be a decimal number within Kerala (74 to 78)';

    // Existing institutions and repeats within the file
    if ($d['name'] !== '' && $d['district_id']) {
        $st = db()->prepare('SELECT COUNT(*) FROM institutions WHERE name = ? AND district_id = ?');
        $st->execute([$d['name'], $d['district_id']]);
        if ((int) $st->fetchColumn()) $issues[] = 'An institution with this name already exists in the district';
        $key = mb_strtolower($d['name']) . '|' . $d['district_id'];
        if (isset($seen['name'][$key])) $issues[] = "Same name and district as line {$seen['name'][$key]}";
        $seen['name'][$key] ??= $raw['_line'];
    }
    if ($d['dwms_id'] !== null) {
        $key = mb_strtolower($d['dwms_id']);
        if (isset($seen['dwms'][$key])) $issues[] = "Same DWMS Institution ID as line {$seen['dwms'][$key]}";
        $seen['dwms'][$key] ??= $raw['_line'];
    }
    return [$d, $issues];
}

$batch = $_SESSION[INST_UPLOAD_SESSION] ?? null;

// ---- Downloads ------------------------------------------------------------------
$headers = array_map(fn($c) => $c[0], INST_UPLOAD_COLUMNS);
if (input('download') === 'template') {
    send_csv('institutions-upload-template.csv', $headers, []);
}
if (input('download') === 'reference') {
    $lists = [
        'District' => array_column(districts(), 'name'),
        'Affiliated University' => array_map(fn($u) => $u['short_name'] ? "{$u['name']} ({$u['short_name']})" : $u['name'], lookup('universities')),
        'Institution Category' => array_column(lookup('institution_categories'), 'name'),
        'Institution Type' => array_column(lookup('institution_types'), 'name'),
    ];
    $rows = [];
    for ($i = 0, $n = max(array_map('count', $lists)); $i < $n; $i++) {
        $rows[] = array_map(fn($l) => $l[$i] ?? '', array_values($lists));
    }
    send_csv('institutions-upload-reference-values.csv', array_keys($lists), $rows);
}
if (input('download') === 'issues' && $batch) {
    $rows = [];
    foreach ($batch['rows'] as $r) {
        if ($r['issues']) $rows[] = [...array_map(fn($k) => $r['raw'][$k] ?? '', array_keys(INST_UPLOAD_COLUMNS)), implode('; ', $r['issues'])];
    }
    send_csv('institutions-upload-issues.csv', [...$headers, 'Issues'], $rows);
}

// ---- Upload, import, discard ------------------------------------------------------
if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'discard') {
        unset($_SESSION[INST_UPLOAD_SESSION]);
        redirect('institution-upload');
    }

    if ($action === 'upload') {
        $fail = function (string $msg): never {
            flash('error', $msg);
            redirect('institution-upload');
        };
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $fail('Choose a CSV file to upload.');
        if ($f['size'] > 4 * 1024 * 1024) $fail('The file must be smaller than 4 MB.');
        if (!preg_match('/\.(csv|txt)$/i', (string) $f['name'])) $fail('Upload the file in CSV format (in Excel: File → Save As → CSV UTF-8).');

        $content = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($f['tmp_name']));
        if (!mb_check_encoding($content, 'UTF-8')) $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : (substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',');
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $content);
        rewind($fh);

        $header = fgetcsv($fh, 0, $delimiter, '"', '');
        if (!$header || !array_filter($header)) $fail('The file is empty.');
        $byHeader = [];
        foreach (INST_UPLOAD_COLUMNS as $key => [$label]) $byHeader[$norm($label)] = $key;
        $byHeader += ['name' => 'name', 'institution' => 'name', 'affiliationcode' => 'code', 'code' => 'code', 'dwmsid' => 'dwms_id',
            'university' => 'university', 'category' => 'category', 'managementtype' => 'category', 'type' => 'type', 'headname' => 'head_name', 'headphone' => 'head_phone'];
        $map = [];
        foreach ($header as $idx => $h) {
            $key = $byHeader[$norm($h)] ?? null;
            if ($key && !in_array($key, $map, true)) $map[$idx] = $key;
        }
        $missing = array_diff(array_keys(array_filter(INST_UPLOAD_COLUMNS, fn($c) => $c[1])), $map);
        if ($missing) $fail('Required column(s) missing: ' . implode(', ', array_map(fn($k) => INST_UPLOAD_COLUMNS[$k][0], $missing)) . '. Download the template and keep its header row.');

        $rows = [];
        $seen = ['name' => [], 'dwms' => []];
        $line = 1;
        while (($cells = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if (!array_filter($cells, fn($c) => trim((string) $c) !== '')) continue;   // blank line
            if (count($rows) >= INST_UPLOAD_MAX_ROWS) $fail('The file has more than ' . INST_UPLOAD_MAX_ROWS . ' rows. Split it and upload the parts separately.');
            $raw = ['_line' => $line];
            foreach ($map as $idx => $key) $raw[$key] = trim((string) ($cells[$idx] ?? ''));
            [$data, $issues] = check_institution_row($raw, $masters, $norm, $seen);
            $rows[] = ['line' => $line, 'raw' => $raw, 'data' => $data, 'issues' => $issues];
        }
        if (!$rows) $fail('No data rows were found below the header row.');
        $_SESSION[INST_UPLOAD_SESSION] = ['file' => mb_substr((string) $f['name'], 0, 120), 'uploaded_at' => time(), 'rows' => $rows, 'imported' => null];
        redirect('institution-upload');
    }

    if ($action === 'import' && $batch && $batch['imported'] === null) {
        // Re-check every ready row inside the transaction: the registry may have changed since the preview
        $seen = ['name' => [], 'dwms' => []];
        $ins = null;
        $added = 0;
        db()->beginTransaction();
        foreach ($batch['rows'] as &$r) {
            if ($r['issues']) continue;
            [$d, $issues] = check_institution_row($r['raw'], $masters, $norm, $seen);
            if ($issues) {
                $r['issues'] = $issues;
                continue;
            }
            $d['created_by'] = $user['id'];
            if (!$ins) {
                $ins = db()->prepare('INSERT INTO institutions (' . implode(', ', array_map(fn($k) => "`$k`", array_keys($d))) . ') VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')');
            }
            $ins->execute(array_values($d));
            $r['institution_id'] = (int) db()->lastInsertId();
            $added++;
        }
        unset($r);
        db()->commit();
        $issueCount = count(array_filter($batch['rows'], fn($r) => $r['issues']));
        $batch['imported'] = $added;
        $_SESSION[INST_UPLOAD_SESSION] = $batch;
        if ($added) {
            log_activity('create', 'institution', null, "Bulk uploaded {$added} institutions from {$batch['file']}" . ($issueCount ? " ({$issueCount} rows with issues not imported)" : ''));
        }
        flash($added ? 'success' : 'error', $added
            ? "{$added} institution(s) added." . ($issueCount ? " {$issueCount} row(s) with issues were not imported — download them, correct and upload again." : '')
            : 'No institutions were added.');
        redirect('institution-upload');
    }
    redirect('institution-upload');
}

// ---- Page ------------------------------------------------------------------------
$view = in_array(input('view'), ['ready', 'issues'], true) ? input('view') : 'all';
$counts = ['all' => 0, 'ready' => 0, 'issues' => 0];
$shown = [];
if ($batch) {
    foreach ($batch['rows'] as $r) {
        $kind = $r['issues'] ? 'issues' : 'ready';
        $counts['all']++;
        $counts[$kind]++;
        if ($view === 'all' || $view === $kind) $shown[] = $r;
    }
}
const INST_UPLOAD_PREVIEW_LIMIT = 1000;

$pageTitle = 'Bulk upload institutions';
require APP_ROOT . '/app/layout/header.php';
?>
<nav class="text-xs text-slate-500 mb-2 flex items-center gap-1.5"><a class="link" href="<?= e(url('institutions')) ?>">Institutions</a><?= icon('chevron-right', 'w-3 h-3') ?><span class="text-slate-700 font-medium">Bulk upload</span></nav>
<div class="mb-6">
  <h1 class="text-xl font-bold text-slate-900">Bulk upload institutions</h1>
  <p class="text-sm text-slate-500">Add many institutions at once from a CSV file. Rows are checked first; you can import the good rows and download the rest to correct later.</p>
</div>

<?php if (!$batch): ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="card lg:col-span-2">
    <h2 class="card-title mb-1">1. Download the template</h2>
    <p class="text-sm text-slate-500 mb-4">Fill one institution per row in Excel, then save as <strong>CSV UTF-8</strong>. Use the master values exactly as listed in the reference file (names or short names; letter case does not matter).</p>
    <div class="flex flex-wrap gap-2 mb-5">
      <a href="<?= e(url('institution-upload', ['download' => 'template'])) ?>" class="btn-primary"><?= icon('download') ?> Download template</a>
      <a href="<?= e(url('institution-upload', ['download' => 'reference'])) ?>" class="btn-secondary"><?= icon('download') ?> Reference values (districts, universities, categories, types)</a>
    </div>
    <div class="overflow-x-auto border border-slate-200 rounded-xl">
      <table class="table">
        <thead><tr><th>Column</th><th>Required</th><th>Notes</th></tr></thead>
        <tbody>
          <?php foreach (INST_UPLOAD_COLUMNS as [$label, $req, $note]): ?>
            <tr><td class="font-medium text-slate-800"><?= e($label) ?></td><td><?= $req ? '<span class="text-rose-600 font-semibold text-xs">Required</span>' : '<span class="text-slate-400 text-xs">Optional</span>' ?></td><td class="text-xs text-slate-500"><?= e($note) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <form method="post" enctype="multipart/form-data" class="card self-start space-y-4">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <h2 class="card-title">2. Upload and preview</h2>
    <div>
      <label class="label">CSV file <span class="req">*</span></label>
      <input class="input" type="file" name="file" accept=".csv,text/csv,.txt" required>
      <span class="hint">Up to <?= num(INST_UPLOAD_MAX_ROWS) ?> rows, 4 MB. Nothing is saved until you confirm the import on the preview.</span>
    </div>
    <button class="btn-primary w-full justify-center"><?= icon('upload') ?> Check &amp; preview</button>
    <p class="text-xs text-slate-500">Only new institutions are added. A row with the same name in the same district as an existing institution, or an existing DWMS Institution ID, is reported as an issue.</p>
  </form>
</div>

<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
  <div class="kpi"><p class="text-xs font-semibold text-slate-500">Rows in file</p><p class="text-2xl font-extrabold text-slate-900"><?= num($counts['all']) ?></p><p class="text-[11px] text-slate-400 truncate"><?= e($batch['file']) ?></p></div>
  <div class="kpi"><p class="text-xs font-semibold text-slate-500"><?= $batch['imported'] === null ? 'Ready to import' : 'Imported' ?></p><p class="text-2xl font-extrabold text-emerald-600"><?= num($batch['imported'] ?? $counts['ready']) ?></p></div>
  <div class="kpi"><p class="text-xs font-semibold text-slate-500">Rows with issues</p><p class="text-2xl font-extrabold <?= $counts['issues'] ? 'text-rose-600' : 'text-slate-400' ?>"><?= num($counts['issues']) ?></p></div>
</div>

<div class="card p-4 mb-4 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
  <p class="text-sm text-slate-700">
    <?php if ($batch['imported'] !== null): ?>
      <?= icon('check-circle', 'w-5 h-5 inline text-emerald-600') ?> Import finished: <?= num($batch['imported']) ?> institution(s) added.<?= $counts['issues'] ? ' Download the issue records, correct them and upload that file again.' : '' ?>
    <?php elseif ($counts['ready']): ?>
      Check the preview below, then import the <?= num($counts['ready']) ?> ready row(s).<?= $counts['issues'] ? ' Rows with issues are skipped — download them to correct and upload later.' : '' ?>
    <?php else: ?>
      No row is ready to import. Download the issue records, correct them and upload the file again.
    <?php endif; ?>
  </p>
  <div class="flex flex-wrap gap-2 shrink-0">
    <?php if ($counts['issues']): ?><a href="<?= e(url('institution-upload', ['download' => 'issues'])) ?>" class="btn-secondary btn-xs"><?= icon('download', 'w-3.5 h-3.5') ?> Download issue records (<?= num($counts['issues']) ?>)</a><?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="discard"><button class="btn-secondary btn-xs"><?= $batch['imported'] === null ? 'Cancel' : 'Upload another file' ?></button></form>
    <?php if ($batch['imported'] === null && $counts['ready']): ?>
      <form method="post" data-confirm="Add <?= num($counts['ready']) ?> institution(s) to the registry?"><?= csrf_field() ?><input type="hidden" name="action" value="import"><button class="btn-primary btn-xs"><?= icon('check', 'w-3.5 h-3.5') ?> Import <?= num($counts['ready']) ?> ready row(s)</button></form>
    <?php endif; ?>
    <?php if ($batch['imported'] !== null): ?><a href="<?= e(url('institutions')) ?>" class="btn-primary btn-xs">View institutions</a><?php endif; ?>
  </div>
</div>

<div class="flex gap-1 mb-3">
  <?php foreach (['all' => 'All', 'ready' => $batch['imported'] === null ? 'Ready' : 'Imported', 'issues' => 'Issues'] as $k => $lbl): ?>
    <a href="<?= e(url('institution-upload', ['view' => $k === 'all' ? null : $k])) ?>" class="px-3 py-1.5 rounded-lg text-sm font-medium <?= $view === $k ? 'bg-sky-50 text-sky-700' : 'text-slate-600 hover:bg-slate-50' ?>"><?= e($lbl) ?> (<?= num($counts[$k]) ?>)</a>
  <?php endforeach; ?>
</div>
<div class="card p-0 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr><th>Line</th><th>Institution</th><th>Code / DWMS ID</th><th>District</th><th>University · Category · Type</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($shown, 0, INST_UPLOAD_PREVIEW_LIMIT) as $r): $raw = $r['raw']; ?>
        <tr class="<?= $r['issues'] ? 'bg-rose-50/40' : '' ?>">
          <td class="font-mono text-xs text-slate-500"><?= (int) $r['line'] ?></td>
          <td><p class="font-semibold text-slate-900"><?= e($raw['name'] ?? '') ?: '<span class="text-slate-300">—</span>' ?></p><p class="text-[11px] text-slate-400"><?= e(implode(' · ', array_filter([$raw['head_name'] ?? '', $raw['email'] ?? '']))) ?></p></td>
          <td class="text-xs font-mono"><?= e($raw['code'] ?? '') ?><span class="block text-slate-400"><?= e($raw['dwms_id'] ?? '') ?></span></td>
          <td class="text-xs"><?= e($raw['district'] ?? '') ?></td>
          <td class="text-xs"><?= e(implode(' · ', array_filter([$raw['university'] ?? '', $raw['category'] ?? '', $raw['type'] ?? '']))) ?></td>
          <td class="text-xs max-w-sm">
            <?php if ($r['issues']): ?>
              <?= status_badge('Issue') ?>
              <ul class="mt-1 text-rose-700 list-disc pl-4 space-y-0.5"><?php foreach ($r['issues'] as $iss): ?><li><?= e($iss) ?></li><?php endforeach; ?></ul>
            <?php elseif (!empty($r['institution_id'])): ?>
              <a class="link" href="<?= e(url('institution-dashboard', ['id' => $r['institution_id']])) ?>"><?= status_badge('Imported') ?></a>
            <?php else: ?><?= status_badge('Ready') ?><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$shown): ?><tr><td colspan="6" class="text-center text-slate-400 py-8">No rows in this view.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($shown) > INST_UPLOAD_PREVIEW_LIMIT): ?><p class="p-3 text-xs text-slate-500 border-t border-slate-100">Showing the first <?= num(INST_UPLOAD_PREVIEW_LIMIT) ?> of <?= num(count($shown)) ?> rows. All rows are imported / included in the download.</p><?php endif; ?>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/app/layout/footer.php';
