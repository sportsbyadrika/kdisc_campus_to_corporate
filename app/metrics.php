<?php
declare(strict_types=1);

/**
 * Per-institution statistics for the current academic year.
 *
 * Onboarding sections (each worth 20% of profile completion):
 *   1. Institution profile (university, category, address, location)
 *   2. Placement officer(s)
 *   3. Course / department student strength
 *   4. Cohort & assessment data
 *   5. DWMS services
 *
 * Readiness score (0-100), adapted from the onboarding design:
 *   30% DWMS coverage + 50% gateway assessment coverage of job seekers + 20% profile completion
 *
 * @param string $where  SQL condition on alias `i` (use institution_scope())
 */
function institution_metrics(string $where = '1=1', array $params = [], ?string $ay = null): array
{
    $ay ??= academic_year();
    $sql = "SELECT i.id, i.name, i.code, i.dwms_id, i.district_id, d.name AS district_name, i.logo, i.is_active,
                   i.university_id, u.short_name AS university_short, u.name AS university_name,
                   c.name AS category_name, i.type_id, it.name AS type_name, i.address, i.latitude, i.longitude, i.updated_at,
                   COALESCE(dep.dept_count, 0)  AS dept_count,
                   COALESCE(dep.final_sum, 0)   AS dept_final_year,
                   COALESCE(dep.total_sum, 0)   AS dept_total_students,
                   co.total_final_year, co.dwms_registered, co.job_seekers, co.higher_studies,
                   co.campus_placed, cp.campus_placed AS campus_placed_prev,
                   COALESCE(vnd.gateway_vendor, 0) AS gateway_vendor, vnd.vendor_rows,
                   COALESCE(asm.gateway_done, 0) AS gateway_done,
                   COALESCE(asm.assessed_total, 0) AS assessed_total,
                   COALESCE(asm.assess_rows, 0) AS assess_rows,
                   COALESCE(svc.services, 0)    AS services,
                   COALESCE(svc.services_done, 0) AS services_done,
                   COALESCE(svc.beneficiaries, 0) AS beneficiaries,
                   COALESCE(ofc.officers, 0)    AS officers
            FROM institutions i
            JOIN districts d ON d.id = i.district_id
            LEFT JOIN universities u ON u.id = i.university_id
            LEFT JOIN institution_categories c ON c.id = i.category_id
            LEFT JOIN institution_types it ON it.id = i.type_id
            LEFT JOIN (SELECT institution_id, COUNT(*) dept_count, SUM(final_year_count) final_sum, SUM(total_students) total_sum
                       FROM institution_departments WHERE academic_year = ? GROUP BY institution_id) dep ON dep.institution_id = i.id
            LEFT JOIN institution_cohorts co ON co.institution_id = i.id AND co.academic_year = ?
            LEFT JOIN institution_cohorts cp ON cp.institution_id = i.id AND cp.academic_year = ?
            LEFT JOIN (SELECT v.institution_id, COUNT(*) vendor_rows,
                              MAX(CASE WHEN t.is_mandatory = 1 THEN v.tests_conducted ELSE 0 END) gateway_vendor
                       FROM assessment_vendor_counts v JOIN assessment_tests t ON t.id = v.assessment_test_id
                       WHERE v.academic_year = ? GROUP BY v.institution_id) vnd ON vnd.institution_id = i.id
            LEFT JOIN (SELECT ia.institution_id,
                              MAX(CASE WHEN t.is_mandatory = 1 THEN ia.students_completed ELSE 0 END) gateway_done,
                              SUM(ia.students_completed) assessed_total, COUNT(*) assess_rows
                       FROM institution_assessments ia JOIN assessment_tests t ON t.id = ia.assessment_test_id
                       WHERE ia.academic_year = ? GROUP BY ia.institution_id) asm ON asm.institution_id = i.id
            LEFT JOIN (SELECT institution_id, COUNT(*) services, SUM(status = 'Completed') services_done, SUM(beneficiaries) beneficiaries
                       FROM institution_services GROUP BY institution_id) svc ON svc.institution_id = i.id
            LEFT JOIN (SELECT institution_id, COUNT(*) officers FROM institution_officers GROUP BY institution_id) ofc ON ofc.institution_id = i.id
            WHERE {$where}
            ORDER BY d.sort_order, i.name";
    $st = db()->prepare($sql);
    $st->execute(array_merge([$ay, $ay, previous_academic_year($ay), $ay, $ay], $params));
    $rows = $st->fetchAll();
    return array_map('derive_metrics', $rows);
}

function derive_metrics(array $r): array
{
    $hasCohort = $r['total_final_year'] !== null;
    $r['final_year'] = (int) ($hasCohort && (int) $r['total_final_year'] > 0 ? $r['total_final_year'] : $r['dept_final_year']);
    $r['dwms_registered'] = (int) ($r['dwms_registered'] ?? 0);
    $r['job_seekers'] = (int) ($r['job_seekers'] ?? 0);
    $r['higher_studies'] = (int) ($r['higher_studies'] ?? 0);
    $r['campus_placed'] = $r['campus_placed'] === null ? null : (int) $r['campus_placed'];
    $r['campus_placed_prev'] = $r['campus_placed_prev'] === null ? null : (int) $r['campus_placed_prev'];
    $r['gateway_vendor'] = (int) $r['gateway_vendor'];
    $r['has_vendor'] = (int) ($r['vendor_rows'] ?? 0) > 0;
    foreach (['dept_count', 'dept_final_year', 'dept_total_students', 'gateway_done', 'assessed_total', 'services', 'services_done', 'beneficiaries', 'officers'] as $k) {
        $r[$k] = (int) $r[$k];
    }

    $sections = [
        'profile'  => $r['university_id'] && $r['category_name'] && $r['address'] && $r['latitude'] !== null,
        'officers' => $r['officers'] > 0,
        'students' => $r['dept_count'] > 0,
        'cohorts'  => $hasCohort && $r['job_seekers'] > 0 && (int) $r['assess_rows'] > 0,
        'services' => $r['services'] > 0,
    ];
    $r['sections'] = $sections;
    $r['profile_pct'] = (int) round(count(array_filter($sections)) / count($sections) * 100);

    $r['dwms_pct'] = pct($r['dwms_registered'], $r['final_year']);
    $gatewayBase = $r['job_seekers'] ?: $r['final_year'];
    $r['gateway_base'] = $gatewayBase;
    $r['gateway_pct'] = pct($r['gateway_done'], $gatewayBase);
    $r['jobseeker_pct'] = pct($r['job_seekers'], $r['final_year']);
    $r['score'] = (int) round($r['dwms_pct'] * 0.3 + $r['gateway_pct'] * 0.5 + $r['profile_pct'] * 0.2);

    $r['status'] = !(int) $r['is_active'] ? 'Inactive'
        : ($r['profile_pct'] === 100 ? 'Onboarded' : ($r['profile_pct'] > 0 ? 'In Progress' : 'Not Started'));
    return $r;
}

/** Sum a list of institution metric rows into one aggregate row. */
function aggregate_metrics(array $rows): array
{
    $a = [
        'institutions' => 0, 'onboarded' => 0, 'in_progress' => 0, 'not_started' => 0,
        'campus_placed' => 0, 'campus_placed_prev' => 0, 'gateway_vendor' => 0,
        'final_year' => 0, 'dwms_registered' => 0, 'job_seekers' => 0, 'higher_studies' => 0,
        'gateway_done' => 0, 'gateway_base' => 0, 'assessed_total' => 0, 'services' => 0,
        'services_done' => 0, 'beneficiaries' => 0, 'officers' => 0, 'dept_count' => 0, 'score_sum' => 0,
    ];
    foreach ($rows as $r) {
        if (!(int) $r['is_active']) {
            continue;
        }
        $a['institutions']++;
        match ($r['status']) {
            'Onboarded'   => $a['onboarded']++,
            'In Progress' => $a['in_progress']++,
            default       => $a['not_started']++,
        };
        foreach (['campus_placed', 'campus_placed_prev', 'gateway_vendor', 'final_year', 'dwms_registered', 'job_seekers', 'higher_studies', 'gateway_done', 'gateway_base',
                  'assessed_total', 'services', 'services_done', 'beneficiaries', 'officers', 'dept_count'] as $k) {
            $a[$k] += $r[$k];
        }
        $a['score_sum'] += $r['score'];
    }
    $a['dwms_pct'] = pct($a['dwms_registered'], $a['final_year']);
    $a['gateway_pct'] = pct($a['gateway_done'], $a['gateway_base']);
    $a['jobseeker_pct'] = pct($a['job_seekers'], $a['final_year']);
    $a['onboarded_pct'] = pct($a['onboarded'], $a['institutions']);
    $a['avg_score'] = $a['institutions'] ? (int) round($a['score_sum'] / $a['institutions']) : 0;
    return $a;
}

/** Aggregate metric rows per district, returning one entry for each of the 14 districts. */
function district_rollup(array $rows): array
{
    $by = [];
    foreach ($rows as $r) {
        $by[(int) $r['district_id']][] = $r;
    }
    $out = [];
    foreach (districts() as $d) {
        $out[] = ['district' => $d] + aggregate_metrics($by[(int) $d['id']] ?? []);
    }
    return $out;
}

/** Statewide rank of every active institution by readiness score (ties share a rank). */
function statewide_ranks(): array
{
    $rows = institution_metrics('i.is_active = 1');
    usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
    $ranks = [];
    $rank = 0;
    $prev = null;
    foreach ($rows as $i => $r) {
        if ($r['score'] !== $prev) {
            $rank = $i + 1;
            $prev = $r['score'];
        }
        $ranks[(int) $r['id']] = $rank;
    }
    return ['ranks' => $ranks, 'total' => count($rows)];
}

/** Record a point-in-time snapshot of the institution's cohort figures (for trend tracking). */
function snapshot_cohort(int $institutionId): void
{
    $ay = academic_year();
    $m = institution_metrics('i.id = ?', [$institutionId], $ay)[0] ?? null;
    if (!$m) {
        return;
    }
    db()->prepare('INSERT INTO cohort_snapshots (institution_id, academic_year, total_final_year, dwms_registered, job_seekers, gateway_completed, recorded_by)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute([$institutionId, $ay, $m['final_year'], $m['dwms_registered'], $m['job_seekers'], $m['gateway_done'], current_user()['id'] ?? null]);
}

/** Assessment tests applicable to a university (global tests + university-specific). */
function assessment_tests_for(?int $universityId): array
{
    $st = db()->prepare('SELECT t.*, u.short_name AS university_short FROM assessment_tests t
                         LEFT JOIN universities u ON u.id = t.university_id
                         WHERE t.is_active = 1 AND (t.university_id IS NULL OR t.university_id = ?)
                         ORDER BY t.is_mandatory DESC, t.university_id IS NOT NULL, t.name');
    $st->execute([$universityId ?? 0]);
    return $st->fetchAll();
}

/** "2026-27" -> "2025-26" */
function previous_academic_year(?string $ay = null): string
{
    $ay ??= academic_year();
    if (preg_match('/^(\d{4})-\d{2}$/', $ay, $m)) {
        $start = (int) $m[1] - 1;
        return $start . '-' . str_pad((string) (($start + 1) % 100), 2, '0', STR_PAD_LEFT);
    }
    return $ay;
}
