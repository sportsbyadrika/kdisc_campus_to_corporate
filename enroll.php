<?php
/**
 * Public "Enroll my Institution" request form.
 *
 * Anti-abuse: CSRF token, self-hosted CAPTCHA (single use), honeypot field, minimum fill time
 * (signed timestamp), per-IP rate limits on submissions and institution lookups, strict
 * server-side validation with length limits, strict CSP (no inline scripts), and a database
 * unique key that allows only one open request per institution.
 */
require __DIR__ . '/app/bootstrap.php';
require_once APP_ROOT . '/app/enrollment.php';

if (current_user()) {
    redirect('dashboard');
}

// ---- JSON: institutions of a district ---------------------------------------
if (isset($_GET['list'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    if (!throttle_allow('enroll-list:' . client_ip(), 120, 600)) {
        http_response_code(429);
        exit(json_encode(['error' => 'Too many requests. Please wait a few minutes.']));
    }
    $districtId = (int) $_GET['list'];
    if (!in_array($districtId, array_map('intval', array_column(districts(), 'id')), true)) {
        http_response_code(400);
        exit(json_encode(['error' => 'Unknown district']));
    }
    exit(json_encode(['institutions' => enrollment_institutions($districtId)], JSON_UNESCAPED_UNICODE));
}

$fields = ['district_id', 'institution_id', 'head_name', 'head_designation', 'head_email', 'head_phone', 'po_name', 'po_email', 'po_phone'];

if (is_post()) {
    verify_csrf();
    $back = function (array $errors) use ($fields): never {
        foreach ($errors as $err) {
            flash('error', $err);
        }
        $_SESSION['_enroll_old'] = array_intersect_key($_POST, array_flip($fields));
        redirect('enroll');
    };

    if (!throttle_allow('enroll-post:' . client_ip(), 15, 3600)) {
        too_many_requests('Too many attempts from your network. Please try again in an hour.');
    }
    // Honeypot: real users never see or fill the "website" field. Pretend success, store nothing.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        $_SESSION['_enroll_done'] = 'ENR-' . str_pad((string) random_int(1000, 99999), 6, '0', STR_PAD_LEFT);
        redirect('enroll');
    }
    if (!form_time_ok((string) ($_POST['_ft'] ?? ''), 5)) {
        $back(['The form expired or was submitted too quickly. Please fill it in again.']);
    }
    if (!captcha_verify('enroll', (string) ($_POST['captcha'] ?? ''))) {
        $back(['The verification code was incorrect. Please type the characters shown in the new image.']);
    }

    $clean = fn(string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$k] ?? ''))), 0, $max);
    $d = [
        'district_id'      => (int) ($_POST['district_id'] ?? 0),
        'institution_id'   => (int) ($_POST['institution_id'] ?? 0),
        'head_name'        => $clean('head_name', 160),
        'head_designation' => $clean('head_designation', 160),
        'head_email'       => strtolower($clean('head_email', 160)),
        'head_phone'       => normalize_phone($clean('head_phone', 20)),
        'po_name'          => $clean('po_name', 160),
        'po_email'         => strtolower($clean('po_email', 160)),
        'po_phone'         => normalize_phone($clean('po_phone', 20)),
    ];

    $errors = [];
    $inst = null;
    if (!in_array($d['district_id'], array_map('intval', array_column(districts(), 'id')), true)) {
        $errors[] = 'Select your district.';
    } else {
        foreach (enrollment_institutions($d['district_id']) as $row) {
            if ($row['id'] === $d['institution_id']) {
                $inst = $row;
            }
        }
        if (!$inst) {
            $errors[] = 'Select your institution from the list for the chosen district.';
        } elseif ($inst['state'] === 'pending') {
            $errors[] = 'An enrollment request for this institution is already awaiting approval. Only one request per institution is allowed.';
        } elseif ($inst['state'] === 'enrolled') {
            $errors[] = 'This institution is already enrolled. The placement officer can use "Forgot password" on the sign-in page, or contact the district office.';
        }
    }
    $namePattern = '/^[\p{L}\p{M}][\p{L}\p{M} .\'\-]{1,159}$/u';
    $textPattern = '/^[\p{L}\p{M}\p{N} .,&\'()\/\-]{2,160}$/u';
    if (!preg_match($namePattern, $d['head_name'])) $errors[] = "Enter the Institution Head's name (letters only).";
    if (!preg_match($textPattern, $d['head_designation'])) $errors[] = "Enter the Institution Head's designation.";
    if (!filter_var($d['head_email'], FILTER_VALIDATE_EMAIL)) $errors[] = "Enter a valid email address for the Institution Head.";
    if (!valid_mobile($d['head_phone'])) $errors[] = "Enter a valid 10-digit mobile number for the Institution Head.";
    if (!preg_match($namePattern, $d['po_name'])) $errors[] = "Enter the Placement Officer's name (letters only).";
    if (!filter_var($d['po_email'], FILTER_VALIDATE_EMAIL)) $errors[] = "Enter a valid email address for the Placement Officer.";
    if (!valid_mobile($d['po_phone'])) $errors[] = "Enter a valid 10-digit mobile number for the Placement Officer.";
    if ($d['head_email'] !== '' && $d['head_email'] === $d['po_email']) $errors[] = 'The Institution Head and the Placement Officer must have different email addresses.';
    if (($_POST['confirm'] ?? '') === '') $errors[] = 'Please confirm that you are authorised to request enrollment for this institution.';
    if ($d['head_phone'] !== '' && $d['head_phone'] === $d['po_phone']) $errors[] = 'The Institution Head and the Placement Officer must have different contact numbers.';
    if (!$errors) {
        $st = db()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $st->execute([$d['po_email']]);
        if ($st->fetchColumn()) {
            $errors[] = "The Placement Officer's email address is already registered on the portal. Use \"Forgot password\" on the sign-in page, or contact the district office.";
        }
    }
    if ($errors) {
        $back($errors);
    }
    if (!throttle_allow('enroll-ok:' . client_ip(), 5, 86400)) {
        too_many_requests('The daily limit of enrollment requests from your network has been reached. Please try again tomorrow.');
    }

    try {
        db()->prepare('INSERT INTO enrollment_requests (district_id, institution_id, head_name, head_designation, head_email, head_phone, po_name, po_email, po_phone, ip, user_agent)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([...array_values($d), client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') { // unique open request per institution (race-safe)
            $back(['An enrollment request for this institution already exists. Only one request per institution is allowed.']);
        }
        throw $ex;
    }
    $id = (int) db()->lastInsertId();
    $req = load_enrollment($id);
    log_activity('create', 'enrollment', $id, "Enrollment request {$req['institution_name']} received from the public form", [], (int) $req['institution_id']);
    mail_enrollment_received($req);
    mail_enrollment_district($req);

    unset($_SESSION['_enroll_old']);
    $_SESSION['_enroll_done'] = enrollment_ref($id);
    redirect('enroll');
}

$done = $_SESSION['_enroll_done'] ?? null;
unset($_SESSION['_enroll_done']);
$old = $_SESSION['_enroll_old'] ?? [];
unset($_SESSION['_enroll_old']);
$v = fn(string $k) => (string) ($old[$k] ?? '');

$pageTitle = 'Enroll my Institution';
$publicScripts = ['assets/js/enroll.js'];
require APP_ROOT . '/app/layout/public_header.php';
?>
<?php if ($done): ?>
  <div class="card max-w-2xl mx-auto text-center py-12">
    <div class="mx-auto w-14 h-14 rounded-2xl bg-emerald-500 text-white flex items-center justify-center mb-4"><?= icon('check', 'w-7 h-7') ?></div>
    <h1 class="text-xl font-bold text-slate-900">Enrollment request submitted</h1>
    <p class="text-sm text-slate-500 mt-2">Your reference number is</p>
    <p class="text-2xl font-black font-mono text-sky-700 mt-1"><?= e($done) ?></p>
    <p class="text-sm text-slate-600 mt-4 max-w-md mx-auto">The district office will review the request. Once it is approved, the Placement Officer will receive an email with the sign-in details and a link to set a password.</p>
    <a href="<?= e(url('login')) ?>" class="btn-primary mt-8">Back to sign in</a>
  </div>
<?php else: ?>
<div class="mb-6">
  <h1 class="text-2xl font-black text-slate-900">Enroll my Institution</h1>
  <p class="text-sm text-slate-500 mt-1">Request access to the Campus to Corporate portal. The district office reviews each request; on approval, the Placement Officer receives a login by email.</p>
</div>

<form method="post" class="space-y-6" id="enroll-form" autocomplete="off" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="_ft" value="<?= e(form_time_token()) ?>">
  <!-- Honeypot: hidden from people, attractive to bots -->
  <div class="absolute -left-[9999px] w-px h-px overflow-hidden" aria-hidden="true">
    <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
  </div>

  <section class="card">
    <h2 class="card-title flex items-center gap-2 mb-1"><span class="w-6 h-6 rounded-lg bg-sky-600 text-white text-xs font-bold flex items-center justify-center">1</span> Institution</h2>
    <p class="card-subtitle mb-5">Choose your district, optionally narrow the list by type and category, then select your institution. Institutions already enrolled or with a pending request cannot be selected.</p>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
      <div>
        <label class="label" for="district">District <span class="req">*</span></label>
        <select class="input" id="district" name="district_id" required data-list-url="<?= e(url('enroll')) ?>" data-selected="<?= e($v('institution_id')) ?>">
          <option value="">Select District</option>
          <?php foreach (districts() as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= $v('district_id') === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label" for="filter-type">Institution Type</label>
        <select class="input" id="filter-type" aria-controls="institution">
          <option value="">All types</option>
          <?php foreach (lookup('institution_types') as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label" for="filter-category">Institution Category</label>
        <select class="input" id="filter-category" aria-controls="institution">
          <option value="">All categories</option>
          <?php foreach (lookup('institution_categories') as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="md:col-span-3">
        <label class="label" for="institution">Institution <span class="req">*</span></label>
        <select class="input" id="institution" name="institution_id" required disabled>
          <option value="">Select a district first</option>
        </select>
        <span class="hint" id="institution-hint">Type and Category narrow down the list. Not listed? Ask your district office to add the institution to the portal.</span>
      </div>
    </div>
  </section>

  <section class="card">
    <h2 class="card-title flex items-center gap-2 mb-1"><span class="w-6 h-6 rounded-lg bg-sky-600 text-white text-xs font-bold flex items-center justify-center">2</span> Head of the Institution</h2>
    <p class="card-subtitle mb-5">Principal / Director. Receives a confirmation email only.</p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
      <div><label class="label" for="head_name">Name <span class="req">*</span></label><input class="input" id="head_name" name="head_name" maxlength="160" required value="<?= e($v('head_name')) ?>" placeholder="Dr. Anitha S"></div>
      <div><label class="label" for="head_designation">Designation <span class="req">*</span></label><input class="input" id="head_designation" name="head_designation" maxlength="160" required value="<?= e($v('head_designation')) ?>" placeholder="Principal"></div>
      <div><label class="label" for="head_email">Email <span class="req">*</span></label><input class="input" type="email" id="head_email" name="head_email" maxlength="160" required value="<?= e($v('head_email')) ?>" placeholder="principal@college.ac.in"></div>
      <div><label class="label" for="head_phone">Contact Number <span class="req">*</span></label><input class="input" type="tel" id="head_phone" name="head_phone" maxlength="16" required inputmode="numeric" value="<?= e($v('head_phone')) ?>" placeholder="10-digit mobile"></div>
    </div>
  </section>

  <section class="card">
    <h2 class="card-title flex items-center gap-2 mb-1"><span class="w-6 h-6 rounded-lg bg-sky-600 text-white text-xs font-bold flex items-center justify-center">3</span> Placement Officer</h2>
    <p class="card-subtitle mb-5">Becomes the institution's portal administrator and receives the sign-in details. Email and contact number must be different from the Head's.</p>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
      <div><label class="label" for="po_name">Name <span class="req">*</span></label><input class="input" id="po_name" name="po_name" maxlength="160" required value="<?= e($v('po_name')) ?>" placeholder="Prof. Rajesh K"></div>
      <div><label class="label" for="po_email">Email <span class="req">*</span></label><input class="input" type="email" id="po_email" name="po_email" maxlength="160" required value="<?= e($v('po_email')) ?>" placeholder="placement@college.ac.in"></div>
      <div><label class="label" for="po_phone">Contact Number <span class="req">*</span></label><input class="input" type="tel" id="po_phone" name="po_phone" maxlength="16" required inputmode="numeric" value="<?= e($v('po_phone')) ?>" placeholder="10-digit mobile"></div>
    </div>
    <p class="text-xs text-rose-600 mt-3 hidden" id="same-contact-warning">The Placement Officer's email and contact number must be different from the Institution Head's.</p>
  </section>

  <section class="card">
    <h2 class="card-title flex items-center gap-2 mb-4"><span class="w-6 h-6 rounded-lg bg-sky-600 text-white text-xs font-bold flex items-center justify-center">4</span> Verification</h2>
    <div class="flex flex-col sm:flex-row sm:items-end gap-4">
      <div class="flex items-center gap-2">
        <img id="captcha-img" src="<?= e(url('captcha', ['form' => 'enroll', 't' => time()])) ?>" alt="Verification code" width="180" height="56" class="rounded-lg border border-slate-200">
        <button type="button" id="captcha-refresh" class="p-2 rounded-lg text-slate-500 hover:bg-slate-100" title="Show a new code" aria-label="Show a new code" data-src="<?= e(url('captcha', ['form' => 'enroll'])) ?>">↻</button>
      </div>
      <div class="sm:w-56">
        <label class="label" for="captcha">Type the characters <span class="req">*</span></label>
        <input class="input font-mono uppercase tracking-widest" id="captcha" name="captcha" maxlength="8" required autocomplete="off" autocapitalize="characters" spellcheck="false">
      </div>
    </div>
    <label class="flex items-start gap-2 text-sm text-slate-600 mt-5">
      <input type="checkbox" name="confirm" required class="mt-0.5 h-4 w-4 rounded border-slate-300 text-sky-600">
      I confirm that I am authorised to request enrollment for this institution and that the details above are correct.
    </label>
  </section>

  <div class="flex flex-col-reverse sm:flex-row justify-between gap-3">
    <a href="<?= e(url('login')) ?>" class="btn-secondary">Cancel</a>
    <button class="btn-gradient px-7 py-3"><?= icon('check-circle') ?> Submit enrollment request</button>
  </div>
</form>
<?php endif; ?>
<?php require APP_ROOT . '/app/layout/public_footer.php';
