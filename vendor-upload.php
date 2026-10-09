<?php
/**
 * Bulk upload of "tests conducted" counts reported by the assessment vendor's software.
 *
 * Admin (all institutions) and District users (own district) download a CSV template listing
 * institutions by DWMS Institution ID with one column per assessment test, fill in the vendor
 * figures and upload it back. Values are stored per academic year in assessment_vendor_counts
 * and shown read-only on each institution's Cohorts & Tests page.
 */
require __DIR__ . '/app/bootstrap.php';
$user = require_role('admin', 'district');
$ay = academic_year();
$isDistrict = $user['role'] === 'district';

const UPLOAD_MAX_ROWS = 5000;

// Institutions in the user's scope
$scopeSql = $isDistrict ? ' AND i.district_id = ' . (int) $user['district_id'] : '';
$institutions = db()->query("SELECT i.id, i.name, i.dwms_id, i.university_id, i.district_id, d.name AS district_name
                             FROM institutions i JOIN districts d ON d.id = i.district_id
                             WHERE i.is_active = 1{$scopeSql} ORDER BY d.sort_order, i.name")->fetchAll();
$tests = db()->query('SELECT t.id, t.name, t.university_id, t.is_mandatory, u.short_name AS university_short
                      FROM assessment_tests t LEFT JOIN universities u ON u.id = t.university_id
                      WHERE t.is_active = 1 ORDER BY t.is_mandatory DESC, t.university_id IS NOT NULL, t.name')->fetchAll();

$applies = fn(array $test, array $inst): bool => $test['university_id'] === null || (int) $test['university_id'] === (int) $inst['university_id'];
$testHeader = fn(array $t): string => $t['name'] . ' [' . $t['id'] . ']';

// ---- Template download --------------------------------------------------------
if (input('template') === '1') {
    $existing = [];
    $st = db()->prepare('SELECT institution_id, assessment_test_id, tests_conducted FROM assessment_vendor_counts WHERE academic_year = ?');
    $st->execute([$ay]);
    foreach ($st->fetchAll() as $r) {
        $existing[$r['institution_id']][$r['assessment_test_id']] = $r['tests_conducted'];
    }
    $fname = 'vendor-test-counts-' . ($isDistrict ? strtolower(district_name((int) $user['district_id'])) . '-' : '') . $ay . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9.\-]/i', '-', $fname) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge(['DWMS Institution ID', 'Institution', 'District'], array_map($testHeader, $tests)));
    foreach ($institutions as $i) {
        $row = [$i['dwms_id'] ?? '', $i['name'], $i['district_name']];
        foreach ($tests as $t) {
            $row[] = $applies($t, $i) ? ($existing[$i['id']][$t['id']] ?? '') : 'n/a';
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// ---- Upload -------------------------------------------------------------------
if (is_post()) {
    verify_csrf();
    $f = $_FILES['file'] ?? null;
    $fail = function (string $msg): never {
        flash('error', $msg);
        redirect('vendor-upload');
    };
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $fail('Choose a CSV file to upload.');
    }
    if ($f['size'] > 2 * 1024 * 1024) {
        $fail('The file must be smaller than 2 MB.');
    }
    if (!preg_match('/\.(csv|txt)$/i', (string) $f['name'])) {
        $fail('Upload the file in CSV format (in Excel: File → Save As → CSV UTF-8).');
    }

    $content = (string) file_get_contents($f['tmp_name']);
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
    $firstLine = strtok($content, "\r\n") ?: '';
    $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : (substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',');
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $content);
    rewind($fh);

    $header = fgetcsv($fh, 0, $delimiter, '"', '');
    if (!$header) {
        $fail('The file is empty.');
    }
    $header = array_map(fn($h) => trim((string) $h), $header);

    // Map columns
    $dwmsCol = null;
    $testCols = [];
    $testsById = array_column($tests, null, 'id');
    $testsByName = [];
    foreach ($tests as $t) {
        $testsByName[mb_strtolower($t['name'])] = $t;
    }
    foreach ($header as $idx => $h) {
        if ($dwmsCol === null && stripos($h, 'dwms') !== false) {
            $dwmsCol = $idx;
        } elseif (preg_match('/\[(\d+)\]\s*$/', $h, $mm) && isset($testsById[(int) $mm[1]])) {
            $testCols[$idx] = $testsById[(int) $mm[1]];
        } elseif (isset($testsByName[mb_strtolower($h)])) {
            $testCols[$idx] = $testsByName[mb_strtolower($h)];
        }
    }
    if ($dwmsCol === null) {
        $fail('No "DWMS Institution ID" column was found. Download the template and keep its header row.');
    }
    if (!$testCols) {
        $fail('No assessment test columns were recognised. Keep the test column headers from the template, e.g. "English Score Assessment [1]".');
    }

    $byDwms = [];
    foreach ($institutions as $i) {
        if ($i['dwms_id'] !== null && $i['dwms_id'] !== '') {
            $byDwms[mb_strtolower($i['dwms_id'])] = $i;
        }
    }
    $existing = [];
    $st = db()->prepare('SELECT institution_id, assessment_test_id, tests_conducted FROM assessment_vendor_counts WHERE academic_year = ?');
    $st->execute([$ay]);
    foreach ($st->fetchAll() as $r) {
        $existing[$r['institution_id']][$r['assessment_test_id']] = (int) $r['tests_conducted'];
    }

    $result = ['rows' => 0, 'matched' => 0, 'updated' => 0, 'unchanged' => 0, 'issues' => [], 'file' => mb_substr((string) $f['name'], 0, 120)];
    $issue = function (string $msg) use (&$result): void {
        if (count($result['issues']) < 200) {
            $result['issues'][] = $msg;
        }
    };
    $upsert = db()->prepare('INSERT INTO assessment_vendor_counts (institution_id, academic_year, assessment_test_id, tests_conducted, uploaded_by, uploaded_at)
                             VALUES (?,?,?,?,?, NOW())
                             ON DUPLICATE KEY UPDATE tests_conducted = VALUES(tests_conducted), uploaded_by = VALUES(uploaded_by), uploaded_at = NOW()');
    $perInstitution = [];
    $seen = [];
    $line = 1;

    db()->beginTransaction();
    while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
        $line++;
        if ($row === [null] || !array_filter($row, fn($c) => trim((string) $c) !== '')) {
            continue; // blank line
        }
        if (++$result['rows'] > UPLOAD_MAX_ROWS) {
            $issue('Stopped after ' . UPLOAD_MAX_ROWS . ' rows. Split the file and upload the rest separately.');
            break;
        }
        $dwms = trim((string) ($row[$dwmsCol] ?? ''));
        if ($dwms === '') {
            $issue("Row {$line}: DWMS Institution ID is empty — row skipped.");
            continue;
        }
        $inst = $byDwms[mb_strtolower($dwms)] ?? null;
        if (!$inst) {
            $issue("Row {$line}: DWMS Institution ID {$dwms} " . ($isDistrict ? 'does not belong to an active institution in your district.' : 'does not match any active institution.'));
            continue;
        }
        $iid = (int) $inst['id'];
        if (isset($seen[$iid])) {
            $issue("Row {$line}: {$inst['name']} ({$dwms}) appears more than once — later values overwrite earlier ones.");
        }
        $seen[$iid] = true;
        $result['matched']++;

        foreach ($testCols as $idx => $t) {
            $raw = trim((string) ($row[$idx] ?? ''));
            if ($raw === '' || strcasecmp($raw, 'n/a') === 0 || $raw === '-') {
                continue; // blank = leave unchanged
            }
            $num = str_replace([',', ' '], '', $raw);
            if (!ctype_digit($num)) {
                $issue("Row {$line}: \"{$raw}\" for {$t['name']} is not a whole number — ignored.");
                continue;
            }
            if (!$applies($t, $inst)) {
                $issue("Row {$line}: {$t['name']} does not apply to {$inst['name']}'s university — ignored.");
                continue;
            }
            $value = (int) $num;
            $old = $existing[$iid][$t['id']] ?? null;
            $upsert->execute([$iid, $ay, $t['id'], $value, $user['id']]);
            if ($old === $value) {
                $result['unchanged']++;
            } else {
                $result['updated']++;
                $perInstitution[$iid][$t['name']] = [$old, $value];
            }
            $existing[$iid][$t['id']] = $value;
        }
    }
    db()->commit();
    fclose($fh);

    foreach ($perInstitution as $iid => $changes) {
        log_activity('update', 'vendor_counts', null, 'Vendor test counts uploaded for ' . $ay . ' (' . count($changes) . ' test' . (count($changes) > 1 ? 's' : '') . ')', $changes, $iid);
    }
    log_activity('create', 'vendor_upload', null,
        "Uploaded vendor test counts for {$ay} from {$result['file']}: {$result['matched']} institutions, {$result['updated']} values changed, " . count($result['issues']) . ' issues',
        [], null, $isDistrict ? (int) $user['district_id'] : null);

    $_SESSION['_upload_result'] = $result;
    flash($result['matched'] ? 'success' : 'error', $result['matched']
        ? "Upload processed: {$result['matched']} institutions matched, {$result['updated']} values updated."
        : 'No rows could be matched to institutions. See the issues below.');
    redirect('vendor-upload');
}

$result = $_SESSION['_upload_result'] ?? null;
unset($_SESSION['_upload_result']);

$withoutDwms = array_values(array_filter($institutions, fn($i) => $i['dwms_id'] === null || $i['dwms_id'] === ''));
$st = db()->prepare("SELECT l.summary, l.created_at, " . user_name_sql('u') . " AS user_name FROM activity_log l LEFT JOIN users u ON u.id = l.user_id
                     WHERE l.entity = 'vendor_upload'" . ($isDistrict ? ' AND l.district_id = ?' : '') . ' ORDER BY l.id DESC LIMIT 8');
$st->execute($isDistrict ? [(int) $user['district_id']] : []);
$history = $st->fetchAll();

$pageTitle = 'Vendor Test Upload';
require APP_ROOT . '/app/layout/header.php';
?>
<div class="mb-6">
  <h1 class="text-xl font-bold text-slate-900">Vendor Test Counts Upload</h1>
  <p class="text-sm text-slate-500">
    Upload the "tests conducted" figures from the assessment vendor's dashboard, matched by DWMS Institution ID, for academic year <?= e($ay) ?>.
    <?= $isDistrict ? 'Only institutions in ' . e(district_name((int) $user['district_id'])) . ' are updated.' : '' ?>
  </p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="lg:col-span-2 space-y-6">
    <div class="card">
      <h2 class="card-title mb-4">How it works</h2>
      <ol class="space-y-3 text-sm text-slate-700">
        <li class="flex gap-3"><span class="w-6 h-6 rounded-lg bg-sky-600 text-white font-bold text-xs flex items-center justify-center shrink-0">1</span>
          <span>Download the template. It lists <?= num(count($institutions)) ?> active institutions with their DWMS Institution ID and one column per assessment test (current values pre-filled; <span class="font-mono">n/a</span> where a test doesn't apply to the institution's university).</span></li>
        <li class="flex gap-3"><span class="w-6 h-6 rounded-lg bg-sky-600 text-white font-bold text-xs flex items-center justify-center shrink-0">2</span>
          <span>Fill in the tests-conducted count from the vendor's dashboard. Leave a cell blank to keep its current value. Keep the header row and the <span class="font-mono">[number]</span> at the end of each test name.</span></li>
        <li class="flex gap-3"><span class="w-6 h-6 rounded-lg bg-sky-600 text-white font-bold text-xs flex items-center justify-center shrink-0">3</span>
          <span>Save as <strong>CSV UTF-8</strong> and upload it here. The values appear as "Tests Conducted (Vendor)" on each institution's Cohorts &amp; Tests page.</span></li>
      </ol>
      <a href="<?= e(url('vendor-upload', ['template' => 1])) ?>" class="btn-secondary mt-5"><?= icon('download') ?> Download template (CSV)</a>
    </div>

    <form method="post" enctype="multipart/form-data" class="card">
      <?= csrf_field() ?>
      <h2 class="card-title mb-1">Upload file</h2>
      <p class="card-subtitle mb-4">CSV, up to 2 MB / <?= num(UPLOAD_MAX_ROWS) ?> rows. Comma, semicolon or tab separated.</p>
      <input type="file" name="file" accept=".csv,text/csv" required class="block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-sky-50 file:text-sky-700 file:font-semibold hover:file:bg-sky-100">
      <div class="pt-4 mt-4 border-t border-slate-100 flex justify-end"><button class="btn-primary"><?= icon('check') ?> Upload &amp; apply</button></div>
    </form>

    <?php if ($result): ?>
    <div class="card">
      <h2 class="card-title mb-4">Result — <?= e($result['file']) ?></h2>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
        <?php foreach ([['Rows read', $result['rows'], 'text-slate-900'], ['Institutions matched', $result['matched'], 'text-sky-700'], ['Values updated', $result['updated'], 'text-emerald-700'], ['Unchanged', $result['unchanged'], 'text-slate-500']] as [$lbl, $n, $cls]): ?>
          <div class="kpi"><span class="kpi-label"><?= $lbl ?></span><span class="kpi-value <?= $cls ?>"><?= num($n) ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php if ($result['issues']): ?>
        <h3 class="text-sm font-bold text-amber-800 mb-2"><?= count($result['issues']) ?> issue<?= count($result['issues']) > 1 ? 's' : '' ?></h3>
        <ul class="text-xs text-amber-900 bg-amber-50 border border-amber-200 rounded-xl p-3 space-y-1 max-h-72 overflow-auto">
          <?php foreach ($result['issues'] as $msg): ?><li>• <?= e($msg) ?></li><?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="text-sm text-emerald-700">No issues — every row was applied.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="space-y-6">
    <div class="card">
      <h2 class="card-title mb-1">DWMS IDs</h2>
      <p class="card-subtitle mb-3">Uploads match institutions by DWMS Institution ID.</p>
      <?= meter('Institutions with a DWMS ID', pct(count($institutions) - count($withoutDwms), count($institutions)), num(count($institutions) - count($withoutDwms)) . ' / ' . num(count($institutions))) ?>
      <?php if ($withoutDwms): ?>
        <p class="text-xs text-slate-500 mt-4 mb-2">Missing a DWMS Institution ID:</p>
        <ul class="text-xs space-y-1 max-h-60 overflow-auto">
          <?php foreach (array_slice($withoutDwms, 0, 50) as $i): ?>
            <li><a class="link" href="<?= e(url($isDistrict ? 'institution-profile' : 'institutions', $isDistrict ? ['id' => $i['id']] : ['edit' => $i['id']])) ?>"><?= e($i['name']) ?></a><?= $isDistrict ? '' : ' <span class="text-slate-400">· ' . e($i['district_name']) . '</span>' ?></li>
          <?php endforeach; ?>
          <?php if (count($withoutDwms) > 50): ?><li class="text-slate-400">… and <?= count($withoutDwms) - 50 ?> more</li><?php endif; ?>
        </ul>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2 class="card-title mb-3">Recent uploads</h2>
      <ul class="divide-y divide-slate-100 text-xs">
        <?php foreach ($history as $h): ?>
          <li class="py-2"><p class="text-slate-700"><?= e($h['summary']) ?></p><p class="text-slate-400"><?= e($h['user_name'] ?? 'System') ?> · <?= e(time_ago($h['created_at'])) ?></p></li>
        <?php endforeach; ?>
        <?php if (!$history): ?><li class="py-2 text-slate-400 italic">No uploads yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
