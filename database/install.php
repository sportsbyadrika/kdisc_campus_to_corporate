<?php
/**
 * Command-line installer.
 *
 *   php database/install.php [--demo] [--superadmin-password=...] [--admin-password=...]
 *
 * - Creates all tables and seed masters (database/schema.sql, idempotent)
 * - Creates the hidden super admin ("superadmin") and an administrator ("admin")
 *   if they do not exist yet; passwords are generated and printed when not supplied.
 * - --demo adds a State user, 14 district users, sample institutions, institution users
 *   and historical figures so the dashboards can be explored. Do not use in production.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

$opts = getopt('', ['demo', 'superadmin-password:', 'admin-password:', 'help']);
if (isset($opts['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 900), "\n";
    exit(0);
}

$out = fn(string $s) => fwrite(STDOUT, $s . PHP_EOL);

// Create the database itself when the configured user is allowed to.
try {
    $server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', config('db_host'), config('db_port')), config('db_user'), config('db_pass'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', config('db_name')) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (PDOException $e) {
    $out('Note: could not create the database automatically (' . $e->getMessage() . '). Continuing with the existing database.');
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$out('✔ Schema and master data installed.');

function ensure_user(string $username, string $name, string $role, ?string $password, ?int $districtId = null): array
{
    $st = db()->prepare('SELECT id FROM users WHERE username = ?');
    $st->execute([$username]);
    if ($id = $st->fetchColumn()) {
        return [(int) $id, null];
    }
    $password ??= rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Kc'), '=');
    db()->prepare('INSERT INTO users (name, username, role, district_id, password_hash) VALUES (?,?,?,?,?)')
        ->execute([$name, $username, $role, $districtId, password_hash($password, PASSWORD_DEFAULT)]);
    return [(int) db()->lastInsertId(), $password];
}

$created = [];
[, $p] = ensure_user('superadmin', 'Super Administrator', 'superadmin', $opts['superadmin-password'] ?? null);
if ($p) $created[] = ['superadmin', $p, 'Super Admin (hidden)'];
[$adminId, $p] = ensure_user('admin', 'Portal Administrator', 'admin', $opts['admin-password'] ?? null);
if ($p) $created[] = ['admin', $p, 'Administrator'];

if (isset($opts['demo'])) {
    $demoPass = 'Demo@2026';
    [$stateId, $p] = ensure_user('state', 'State Programme Office', 'state', $demoPass);
    if ($p) $created[] = ['state', $p, 'State User'];
    $districtUsers = [];
    foreach (districts() as $d) {
        $uname = 'dist_' . strtolower($d['code']);
        [$uid, $p] = ensure_user($uname, $d['name'] . ' District Office', 'district', $demoPass, (int) $d['id']);
        $districtUsers[(int) $d['id']] = $uid;
        if ($p) $created[] = [$uname, $p, 'District User · ' . $d['name']];
    }

    if ((int) db()->query('SELECT COUNT(*) FROM institutions')->fetchColumn() === 0) {
        mt_srand(2026);
        $ay = academic_year();
        $people = ['Anandhu S. Kumar', 'Dr. Sunitha M', 'George Matthew', 'Lekshmi R', 'Arun Nair', 'Fathima Beevi', 'Joseph Kurian', 'Divya Menon', 'Rahul Krishnan', 'Shameer P', 'Anjali Varma', 'Vinod Thomas', 'Meera Pillai', 'Ajith Kumar'];
        $centroids = [
            1 => [8.52, 76.94], 2 => [8.89, 76.61], 3 => [9.26, 76.78], 4 => [9.49, 76.33], 5 => [9.59, 76.52], 6 => [9.85, 76.97], 7 => [9.98, 76.30],
            8 => [10.52, 76.21], 9 => [10.78, 76.65], 10 => [11.07, 76.07], 11 => [11.26, 75.78], 12 => [11.69, 76.08], 13 => [11.87, 75.37], 14 => [12.50, 74.99],
        ];
        $kinds = [
            ['College of Engineering', 1, [[1, 'Computer Science & Engineering'], [1, 'Electronics & Communication'], [1, 'Mechanical Engineering'], [1, 'Civil Engineering'], [8, 'Master of Computer Applications']]],
            ['Arts and Science College', null, [[2, 'Physics'], [2, 'Chemistry'], [3, 'Commerce'], [4, 'English'], [6, 'Computer Applications']]],
            ['Institute of Management', null, [[9, 'Business Administration'], [5, 'Business Administration (UG)']]],
            ['Polytechnic College', 7, [[13, 'Mechanical'], [13, 'Electrical & Electronics'], [13, 'Computer Engineering']]],
        ];
        $places = ['Govt.', 'St. Thomas', 'Sree Narayana', 'Mar Baselios', 'NSS', 'Christ', 'MES', 'Model', 'Marian', 'TKM', 'Holy Cross', 'Union'];
        $univByDistrict = [1 => 2, 2 => 2, 3 => 3, 4 => 2, 5 => 3, 6 => 3, 7 => 3, 8 => 4, 9 => 4, 10 => 4, 11 => 4, 12 => 5, 13 => 5, 14 => 5];

        $ins = db()->prepare('INSERT INTO institutions (name, code, email, phone, address, district_id, university_id, category_id, latitude, longitude, established_year, history, created_by, created_at)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $instCount = 0;
        foreach (districts() as $d) {
            $did = (int) $d['id'];
            $dist = db()->prepare('UPDATE districts SET tce_name=?, tce_email=?, tce_phone=?, rpm_name=?, rpm_email=?, rh_name=?, rh_email=? WHERE id=?');
            $region = $did <= 5 ? 'south' : ($did <= 9 ? 'central' : 'north');
            $dist->execute([$people[$did - 1], 'tce.' . strtolower($d['code']) . '@c2c.kerala.gov.in', '+91 94471 ' . (23400 + $did),
                $people[($did + 4) % 14], 'rpm.' . strtolower($d['code']) . '@c2c.kerala.gov.in',
                $people[($did + 9) % 14], 'regionalhead.' . $region . '@c2c.kerala.gov.in', $did]);

            $n = mt_rand(2, 5);
            for ($k = 0; $k < $n; $k++) {
                $kind = $kinds[($did + $k) % count($kinds)];
                $name = $places[($did * 3 + $k) % count($places)] . ' ' . $kind[0] . ', ' . $d['name'];
                $univ = $kind[1] ?? $univByDistrict[$did];
                $stage = mt_rand(0, 9); // how far onboarding has progressed
                $lat = $centroids[$did][0] + mt_rand(-150, 150) / 10000;
                $lng = $centroids[$did][1] + mt_rand(-150, 150) / 10000;
                $created_at = date('Y-m-d H:i:s', strtotime('-' . mt_rand(40, 120) . ' days'));
                $ins->execute([$name, 'C-' . mt_rand(10000, 49999), 'placement' . $did . $k . '@college.ac.in', '04' . mt_rand(70, 99) . '-' . mt_rand(2200000, 2999999),
                    $stage > 1 ? 'Main Road, ' . $d['name'] : null, $did, $stage > 0 ? $univ : null, $stage > 1 ? mt_rand(1, 4) : null,
                    $stage > 1 ? $lat : null, $stage > 1 ? $lng : null, mt_rand(1950, 2012),
                    $stage > 4 ? "Established to provide quality higher education to the people of {$d['name']}.\nAccredited by NAAC with a strong placement record." : null,
                    $adminId, $created_at]);
                $iid = (int) db()->lastInsertId();
                $instCount++;

                if ($stage > 2) {
                    db()->prepare('INSERT INTO institution_officers (institution_id, name, designation, email, phone, is_primary) VALUES (?,?,?,?,?,1)')
                        ->execute([$iid, 'Prof. ' . $people[($did + $k) % 14], 'Training & Placement Officer', 'tpo' . $iid . '@college.ac.in', '98470' . str_pad((string) mt_rand(0, 99999), 5, '0', STR_PAD_LEFT)]);
                }
                $finalTotal = 0;
                if ($stage > 3) {
                    $dep = db()->prepare('INSERT INTO institution_departments (institution_id, academic_year, course_id, department, total_students, final_year_count) VALUES (?,?,?,?,?,?)');
                    foreach ($kind[2] as [$cid, $dname]) {
                        $fy = mt_rand(4, 14) * 10;
                        $finalTotal += $fy;
                        $dep->execute([$iid, $ay, $cid, $dname, $fy * ($cid === 13 ? 3 : 4), $fy]);
                    }
                }
                if ($stage > 4) {
                    $dwms = (int) round($finalTotal * mt_rand(45, 98) / 100);
                    $js = (int) round($finalTotal * mt_rand(55, 80) / 100);
                    $gw = (int) round($js * mt_rand(20, 100) / 100);
                    db()->prepare('INSERT INTO institution_cohorts (institution_id, academic_year, total_final_year, dwms_registered, job_seekers, higher_studies, updated_by) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$iid, $ay, $finalTotal, $dwms, $js, (int) round(($finalTotal - $js) * 0.6), $districtUsers[$did]]);
                    db()->prepare('INSERT INTO institution_assessments (institution_id, academic_year, assessment_test_id, students_completed, status, drive_date) VALUES (?,?,1,?,?,?)')
                        ->execute([$iid, $ay, $gw, $gw >= $js ? 'Completed' : ($gw > $js / 2 ? 'Initial Batch Completed' : 'Drive Scheduled'), date('Y-m-d', strtotime('-' . mt_rand(1, 30) . ' days'))]);
                    if (mt_rand(0, 1)) {
                        db()->prepare('INSERT INTO institution_assessments (institution_id, academic_year, assessment_test_id, students_completed, status) VALUES (?,?,2,?,?)')
                            ->execute([$iid, $ay, (int) round($gw * 0.6), 'Drive Scheduled']);
                    }
                    // history snapshots: steady growth over ~6 weeks
                    $snap = db()->prepare('INSERT INTO cohort_snapshots (institution_id, academic_year, total_final_year, dwms_registered, job_seekers, gateway_completed, recorded_at) VALUES (?,?,?,?,?,?,?)');
                    $steps = mt_rand(4, 8);
                    for ($s = 1; $s <= $steps; $s++) {
                        $f = $s / $steps;
                        $snap->execute([$iid, $ay, $finalTotal, (int) round($dwms * (0.4 + 0.6 * $f)), (int) round($js * (0.7 + 0.3 * $f)), (int) round($gw * $f * $f),
                            date('Y-m-d H:i:s', strtotime('-' . (($steps - $s) * 6) . ' days'))]);
                    }
                }
                if ($stage > 5) {
                    $svc = db()->prepare('INSERT INTO institution_services (institution_id, service_id, status, beneficiaries, availed_on) VALUES (?,?,?,?,?)');
                    foreach (array_slice([1, 2, 3, 4, 5, 6], 0, mt_rand(2, 6)) as $sid) {
                        $status = ['Requested', 'Scheduled', 'In Progress', 'Completed'][mt_rand(0, 3)];
                        $svc->execute([$iid, $sid, $status, $status === 'Completed' ? mt_rand(30, 200) : 0, date('Y-m-d', strtotime('-' . mt_rand(1, 40) . ' days'))]);
                    }
                }
                db()->prepare('INSERT INTO activity_log (user_id, institution_id, district_id, action, entity, entity_id, summary, created_at) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$adminId, $iid, $did, 'create', 'institution', $iid, 'Added institution ' . $name, $created_at]);

                if ($k === 0) {
                    $uname = 'inst_' . strtolower($d['code']);
                    [$uid, $p] = ensure_user($uname, 'Placement Cell, ' . $d['name'], 'institution', $demoPass, $did);
                    db()->prepare('INSERT IGNORE INTO user_institutions (user_id, institution_id, assigned_by) VALUES (?,?,?)')->execute([$uid, $iid, $districtUsers[$did]]);
                    if ($p && $did <= 2) $created[] = [$uname, $p, 'Institution User · ' . $name];
                } elseif ($k === 1 && $did === 1) {
                    // demonstrate a user mapped to multiple institutions
                    $uid = (int) db()->query("SELECT id FROM users WHERE username = 'inst_tvm'")->fetchColumn();
                    db()->prepare('INSERT IGNORE INTO user_institutions (user_id, institution_id, assigned_by) VALUES (?,?,?)')->execute([$uid, $iid, $districtUsers[$did]]);
                }
            }
        }
        db()->prepare('INSERT INTO institution_requests (district_id, name, university_id, category_id, remarks, requested_by) VALUES (?,?,?,?,?,?)')
            ->execute([7, 'Rajagiri School of Engineering & Technology, Ernakulam', 1, 3, 'Institution has requested inclusion in the C2C programme for the current batch.', $districtUsers[7]]);
        $out("✔ Demo data created: {$instCount} institutions across 14 districts.");
    } else {
        $out('• Institutions already exist - demo institutions were not added.');
    }
}

if ($created) {
    $out('');
    $out('Accounts created (store these passwords safely and change them after first login):');
    foreach ($created as [$u, $p, $r]) {
        $out(sprintf('  %-16s %-20s %s', $u, $p, $r));
    }
    if (isset($opts['demo'])) {
        $out('  (other demo district users: dist_<code> e.g. dist_ekm; institution users: inst_<code>; password ' . 'Demo@2026)');
    }
}
$out('');
$out('Done.');
