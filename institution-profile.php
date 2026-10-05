<?php
require __DIR__ . '/app/bootstrap.php';
[$inst, $m, $canEdit] = institution_context();
$activeTab = 'institution-profile';
$isOffice = has_role('admin', 'district'); // offices may also correct identity fields

if (is_post()) {
    verify_csrf();
    if (!$canEdit) {
        forbidden('You cannot edit this institution.');
    }
    $data = [
        'email'            => nullable(input('email')),
        'phone'            => nullable(input('phone')),
        'website'          => nullable(input('website')),
        'address'          => nullable(input('address')),
        'pincode'          => nullable(input('pincode')),
        'established_year' => int_input('established_year'),
        'university_id'    => int_input('university_id'),
        'category_id'      => int_input('category_id'),
        'latitude'         => is_numeric(input('latitude')) ? round((float) input('latitude'), 7) : null,
        'longitude'        => is_numeric(input('longitude')) ? round((float) input('longitude'), 7) : null,
        'history'          => nullable(input('history')),
    ];
    if ($isOffice) {
        $data['name'] = (string) input('name');
        $data['code'] = nullable(input('code'));
    }

    $errors = [];
    if ($isOffice && $data['name'] === '') $errors[] = 'Institution name is required.';
    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid institutional email.';
    if ($data['website'] && !filter_var($data['website'], FILTER_VALIDATE_URL)) $errors[] = 'Website must be a full URL (https://…).';
    if (!$data['university_id']) $errors[] = 'Select the affiliated university.';
    if (!$data['category_id']) $errors[] = 'Select the institution category.';
    if ($data['latitude'] !== null && ($data['latitude'] < 8 || $data['latitude'] > 13)) $errors[] = 'Latitude is outside Kerala (8° – 13° N).';
    if ($data['longitude'] !== null && ($data['longitude'] < 74 || $data['longitude'] > 78)) $errors[] = 'Longitude is outside Kerala (74° – 78° E).';
    if ($data['established_year'] !== null && ($data['established_year'] < 1800 || $data['established_year'] > (int) date('Y'))) $errors[] = 'Enter a valid year of establishment.';

    try {
        $logo = $errors ? null : store_image_upload('logo', 'logos', 600);
        $photo = $errors ? null : store_image_upload('photo', 'photos', 1600);
    } catch (RuntimeException $ex) {
        $errors[] = $ex->getMessage();
        $logo = $photo = null;
    }

    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
        $_SESSION['_old'] = $_POST;
        redirect('institution-profile', ['id' => $inst['id']]);
    }

    if ($logo) { $data['logo'] = $logo; }
    if ($photo) { $data['photo'] = $photo; }
    if (input('remove_logo') === '1' && !$logo) { $data['logo'] = null; }
    if (input('remove_photo') === '1' && !$photo) { $data['photo'] = null; }

    $changes = diff_changes($inst, $data);
    $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
    db()->prepare("UPDATE institutions SET {$set} WHERE id = ?")->execute([...array_values($data), $inst['id']]);
    if (array_key_exists('logo', $data) && $inst['logo'] !== $data['logo']) delete_upload($inst['logo']);
    if (array_key_exists('photo', $data) && $inst['photo'] !== $data['photo']) delete_upload($inst['photo']);

    if ($changes) {
        log_activity('update', 'institution', (int) $inst['id'], 'Updated institution profile (' . implode(', ', array_keys($changes)) . ')', $changes, (int) $inst['id']);
    }
    flash('success', 'Institution profile saved.');
    redirect(input('go') === 'next' ? 'institution-officers' : 'institution-profile', ['id' => $inst['id']]);
}

$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
$v = fn(string $k) => $old[$k] ?? $inst[$k] ?? '';
$universities = lookup('universities');
$categories = lookup('institution_categories');
$ro = $canEdit ? '' : 'disabled';

$pageTitle = $inst['name'] . ' · Profile';
$extraHead = [
    '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">',
];
$extraScripts = [
    '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>',
    '<script src="' . e(asset('assets/js/map-picker.js')) . '"></script>',
];
require APP_ROOT . '/app/layout/header.php';
require APP_ROOT . '/app/layout/institution_nav.php';
?>
<form method="post" enctype="multipart/form-data" class="card sm:p-8">
  <?= csrf_field() ?>
  <?php section_heading('building', 'emerald', 'Step 1: Institution Profile', 'Territorial affiliation, category, location on the map, branding and history.'); ?>

  <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-2">
      <label class="label">Institution Full Legal Name <span class="req">*</span></label>
      <input class="input" name="name" value="<?= e($v('name')) ?>" <?= $isOffice && $canEdit ? 'required' : 'disabled' ?>>
      <?php if (!$isOffice): ?><span class="hint">Contact your district office to correct the registered name.</span><?php endif; ?>
    </div>
    <div>
      <label class="label">AISHE / Affiliation Code</label>
      <input class="input" name="code" value="<?= e($v('code')) ?>" placeholder="e.g. C-43521" <?= $isOffice && $canEdit ? '' : 'disabled' ?>>
    </div>

    <div>
      <label class="label">Official Institutional Email</label>
      <input class="input" type="email" name="email" value="<?= e($v('email')) ?>" placeholder="placement@college.ac.in" <?= $ro ?>>
    </div>
    <div>
      <label class="label">Phone</label>
      <input class="input" name="phone" value="<?= e($v('phone')) ?>" placeholder="0471-2300000" <?= $ro ?>>
    </div>
    <div>
      <label class="label">Website</label>
      <input class="input" type="url" name="website" value="<?= e($v('website')) ?>" placeholder="https://" <?= $ro ?>>
    </div>

    <div>
      <label class="label">Affiliated University <span class="req">*</span></label>
      <select class="input" name="university_id" required <?= $ro ?>>
        <option value="">Select University</option>
        <?php foreach ($universities as $u): ?>
          <option value="<?= $u['id'] ?>" <?= (int) $v('university_id') === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?><?= $u['short_name'] ? ' (' . e($u['short_name']) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <span class="hint">Determines the assessment tests available to this institution.</span>
    </div>
    <div>
      <label class="label">Institution Category <span class="req">*</span></label>
      <select class="input" name="category_id" required <?= $ro ?>>
        <option value="">Select Category</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= (int) $v('category_id') === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label">District</label>
      <input class="input" value="<?= e($inst['district_name']) ?>" disabled>
    </div>

    <div class="md:col-span-2">
      <label class="label">Campus Physical Address</label>
      <input class="input" name="address" value="<?= e($v('address')) ?>" placeholder="Vanchiyoor P.O., Barton Hill" <?= $ro ?>>
    </div>
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="label">PIN Code</label>
        <input class="input" name="pincode" value="<?= e($v('pincode')) ?>" pattern="[0-9]{6}" placeholder="695035" <?= $ro ?>>
      </div>
      <div>
        <label class="label">Established</label>
        <input class="input" type="number" name="established_year" value="<?= e($v('established_year')) ?>" min="1800" max="<?= date('Y') ?>" placeholder="1939" <?= $ro ?>>
      </div>
    </div>
  </div>

  <!-- Location picker (OpenStreetMap) -->
  <div class="mt-8 bg-slate-50 p-4 rounded-xl border border-slate-200">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
      <div>
        <h3 class="text-sm font-bold text-slate-800 flex items-center gap-1.5"><?= icon('map', 'w-4 h-4 text-sky-600') ?> Campus Location</h3>
        <p class="text-xs text-slate-500"><?= $canEdit ? 'Search, click on the map, or drag the pin to set the latitude / longitude.' : 'Campus location on OpenStreetMap.' ?></p>
      </div>
      <?php if ($canEdit): ?>
      <div class="flex gap-2">
        <input type="search" id="map-search" class="input input-sm w-56" placeholder="Search place on OpenStreetMap">
        <button type="button" id="map-search-btn" class="btn-secondary btn-xs" data-no-busy><?= icon('search', 'w-3.5 h-3.5') ?> Find</button>
      </div>
      <?php endif; ?>
    </div>
    <div id="map" class="h-80 rounded-xl border border-slate-200 z-0"
         data-lat="<?= e($v('latitude')) ?>" data-lng="<?= e($v('longitude')) ?>"
         data-editable="<?= $canEdit ? '1' : '0' ?>" data-district="<?= e($inst['district_name']) ?>"></div>
    <div id="map-results" class="hidden mt-2 bg-white border border-slate-200 rounded-xl text-xs divide-y divide-slate-100 max-h-48 overflow-auto"></div>
    <div class="grid grid-cols-2 gap-4 mt-3">
      <div>
        <label class="label">Latitude</label>
        <input class="input font-mono" id="latitude" name="latitude" value="<?= e($v('latitude')) ?>" inputmode="decimal" placeholder="8.5241" <?= $ro ?>>
      </div>
      <div>
        <label class="label">Longitude</label>
        <input class="input font-mono" id="longitude" name="longitude" value="<?= e($v('longitude')) ?>" inputmode="decimal" placeholder="76.9366" <?= $ro ?>>
      </div>
    </div>
  </div>

  <!-- Branding -->
  <div class="mt-8 grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="p-4 rounded-xl border border-slate-200">
      <label class="label">Institution Logo</label>
      <div class="h-32 rounded-lg bg-slate-50 border border-dashed border-slate-300 flex items-center justify-center overflow-hidden mb-3">
        <img id="logo-preview" src="<?= e(upload_url($inst['logo']) ?? '') ?>" alt="Logo" class="max-h-28 object-contain <?= $inst['logo'] ? '' : 'hidden' ?>">
        <span id="logo-preview-placeholder" class="text-xs text-slate-400 <?= $inst['logo'] ? 'hidden' : '' ?>">No logo uploaded</span>
      </div>
      <?php if ($canEdit): ?>
        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" data-preview="#logo-preview" class="block w-full text-xs text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-sky-50 file:text-sky-700 file:font-semibold hover:file:bg-sky-100">
        <span class="hint">PNG / JPG / WEBP, max 2 MB. Square logos look best.</span>
        <?php if ($inst['logo']): ?><label class="mt-2 flex items-center gap-2 text-xs text-slate-500"><input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300"> Remove current logo</label><?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="md:col-span-2 p-4 rounded-xl border border-slate-200">
      <label class="label">Institution Photo</label>
      <div class="h-32 rounded-lg bg-slate-50 border border-dashed border-slate-300 flex items-center justify-center overflow-hidden mb-3">
        <img id="photo-preview" src="<?= e(upload_url($inst['photo']) ?? '') ?>" alt="Campus photo" class="h-full w-full object-cover <?= $inst['photo'] ? '' : 'hidden' ?>">
        <span id="photo-preview-placeholder" class="text-xs text-slate-400 <?= $inst['photo'] ? 'hidden' : '' ?>">No photo uploaded</span>
      </div>
      <?php if ($canEdit): ?>
        <input type="file" name="photo" accept="image/png,image/jpeg,image/webp" data-preview="#photo-preview" class="block w-full text-xs text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-sky-50 file:text-sky-700 file:font-semibold hover:file:bg-sky-100">
        <span class="hint">A wide campus photograph, max 2 MB.</span>
        <?php if ($inst['photo']): ?><label class="mt-2 flex items-center gap-2 text-xs text-slate-500"><input type="checkbox" name="remove_photo" value="1" class="rounded border-slate-300"> Remove current photo</label><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="mt-8">
    <label class="label">History of the Institution <span class="normal-case font-normal text-slate-400">(optional)</span></label>
    <textarea class="input min-h-36" name="history" placeholder="Founding, milestones, accreditations, notable alumni…" <?= $ro ?>><?= e($v('history')) ?></textarea>
  </div>

  <?php if ($canEdit): ?>
  <div class="mt-8 pt-5 border-t border-slate-100 flex items-center justify-between gap-3">
    <span class="text-xs text-slate-400">Last updated <?= e(time_ago($inst['updated_at'])) ?></span>
    <div class="flex gap-2">
      <button name="go" value="stay" class="btn-secondary">Save</button>
      <button name="go" value="next" class="btn-primary">Save &amp; Proceed to Officers <?= icon('arrow-right') ?></button>
    </div>
  </div>
  <?php endif; ?>
</form>
<?php require APP_ROOT . '/app/layout/footer.php';
