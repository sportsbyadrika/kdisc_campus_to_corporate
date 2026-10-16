<?php
declare(strict_types=1);

/**
 * Public "Enroll my Institution" workflow.
 *
 * pending  -> a district user approves (login created for the placement officer) or rejects (with comment).
 * Only one open (pending / approved) request per institution, enforced by a unique key on
 * enrollment_requests (institution_id, open_flag). A rejected institution may apply again.
 */

const SETUP_LINK_HOURS = 72;

/**
 * Active institutions of a district with their enrollment availability:
 *   available | pending (a request is awaiting approval) | enrolled (approved request or an existing portal login)
 */
function enrollment_institutions(int $districtId): array
{
    $st = db()->prepare("SELECT i.id, i.name, i.type_id, i.category_id,
            (SELECT er.status FROM enrollment_requests er WHERE er.institution_id = i.id AND er.open_flag = 1 LIMIT 1) AS open_status,
            (SELECT COUNT(*) FROM user_institutions ui JOIN users u ON u.id = ui.user_id
              WHERE ui.institution_id = i.id AND u.is_active = 1 AND u.role = 'institution') AS logins
        FROM institutions i WHERE i.district_id = ? AND i.is_active = 1 ORDER BY i.name");
    $st->execute([$districtId]);
    return array_map(fn($r) => [
        'id'    => (int) $r['id'],
        'name'  => $r['name'],
        'type'  => $r['type_id'] !== null ? (int) $r['type_id'] : null,
        'category' => $r['category_id'] !== null ? (int) $r['category_id'] : null,
        'state' => $r['open_status'] === 'pending' ? 'pending' : (($r['open_status'] === 'approved' || (int) $r['logins'] > 0) ? 'enrolled' : 'available'),
    ], $st->fetchAll());
}

function enrollment_ref(int $id): string
{
    return 'ENR-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

/** A unique, valid username derived from an email address (e.g. "rajesh.k" or "rajesh.k2"). */
function username_from_email(string $email): string
{
    $base = strtolower(preg_replace('/[^a-z0-9._-]/i', '', strstr($email, '@', true) ?: 'user'));
    $base = trim(substr($base, 0, 50), '._-') ?: 'user';
    if (strlen($base) < 3) {
        $base .= 'c2c';
    }
    $candidate = $base;
    $n = 1;
    $st = db()->prepare('SELECT 1 FROM users WHERE username = ?');
    while (true) {
        $st->execute([$candidate]);
        if (!$st->fetchColumn()) {
            return $candidate;
        }
        $candidate = $base . (++$n);
    }
}

/**
 * Approve a pending request: create the placement officer's login, map it to the institution,
 * store head / officer details on the institution. Returns [user row, raw setup token].
 * Must be called inside a transaction; throws RuntimeException with a user-facing message.
 */
function approve_enrollment(array $req, int $reviewerId, ?string $comment): array
{
    $st = db()->prepare('SELECT id, username FROM users WHERE email = ? LIMIT 1');
    $st->execute([$req['po_email']]);
    if ($existing = $st->fetch()) {
        throw new RuntimeException("A portal user ({$existing['username']}) already uses the placement officer's email {$req['po_email']}. Reject this request or change that user's email first.");
    }

    $username = username_from_email($req['po_email']);
    // Unusable random password: the officer sets their own through the emailed one-time link.
    db()->prepare("INSERT INTO users (name, username, email, phone, role, district_id, is_active, password_hash, created_by)
                   VALUES (?, ?, ?, ?, 'institution', ?, 1, ?, ?)")
        ->execute([$req['po_name'], $username, $req['po_email'], $req['po_phone'], $req['district_id'],
            password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $reviewerId]);
    $userId = (int) db()->lastInsertId();

    db()->prepare('INSERT INTO user_institutions (user_id, institution_id, assigned_by) VALUES (?,?,?)')
        ->execute([$userId, $req['institution_id'], $reviewerId]);

    // Head of the institution -> institution profile columns
    db()->prepare('UPDATE institutions SET head_name = ?, head_designation = ?, head_email = ?, head_phone = ? WHERE id = ?')
        ->execute([$req['head_name'], $req['head_designation'], $req['head_email'], $req['head_phone'], $req['institution_id']]);

    // Placement officer -> the institution's officer list (Step 2), as the main contact.
    // An existing officer with the same email is updated rather than duplicated.
    $instId = (int) $req['institution_id'];
    db()->prepare('UPDATE institution_officers SET is_primary = 0 WHERE institution_id = ?')->execute([$instId]);
    $st = db()->prepare('SELECT id FROM institution_officers WHERE institution_id = ? AND email = ? LIMIT 1');
    $st->execute([$instId, $req['po_email']]);
    if ($officerId = $st->fetchColumn()) {
        db()->prepare('UPDATE institution_officers SET name = ?, phone = ?, is_primary = 1 WHERE id = ?')
            ->execute([$req['po_name'], $req['po_phone'], $officerId]);
    } else {
        db()->prepare("INSERT INTO institution_officers (institution_id, name, designation, email, phone, is_primary) VALUES (?, ?, 'Placement Officer', ?, ?, 1)")
            ->execute([$instId, $req['po_name'], $req['po_email'], $req['po_phone']]);
    }

    db()->prepare("UPDATE enrollment_requests SET status = 'approved', review_comment = ?, reviewed_by = ?, reviewed_at = NOW(), user_id = ? WHERE id = ?")
        ->execute([$comment, $reviewerId, $userId, $req['id']]);

    $token = create_password_token($userId, 'setup', SETUP_LINK_HOURS);
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$userId]);
    return [$st->fetch(), $token];
}

/* ---------------------------------------------------------------------------
 * Emails
 * ------------------------------------------------------------------------- */

/** Placement officer: login created + one-time set-password link. */
function mail_enrollment_login(array $req, array $user, string $token): bool
{
    $link = absolute_url('set-password', ['token' => $token]);
    $body = '<p>Dear ' . e($req['po_name']) . ',</p>'
        . '<p><strong>' . e($req['institution_name']) . '</strong> has been enrolled in the Campus to Corporate programme, and a portal login has been created for you as the Placement Officer.</p>'
        . '<table cellpadding="6" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;font-size:14px">'
        . '<tr><td style="color:#64748b">Username</td><td><strong>' . e($user['username']) . '</strong> (or your email address)</td></tr>'
        . '<tr><td style="color:#64748b">Institution</td><td>' . e($req['institution_name']) . '</td></tr></table>'
        . '<p>Set your password using the button below. The link can be used once and expires in ' . SETUP_LINK_HOURS . ' hours.</p>'
        . email_button($link, 'Set my password')
        . '<p>Sign in afterwards at ' . e(absolute_url('login')) . '</p>';
    return send_mail($req['po_email'], 'Your Campus to Corporate login has been created', email_layout('Your login has been created', $body), 'enroll_login');
}

/** Institution head: confirmation only - deliberately no login or password link. */
function mail_enrollment_head(array $req): bool
{
    $body = '<p>Dear ' . e($req['head_name']) . ',</p>'
        . '<p>The enrollment of <strong>' . e($req['institution_name']) . '</strong> in the Campus to Corporate programme has been approved by the '
        . e($req['district_name']) . ' district office.</p>'
        . '<p>A portal login has been created for your Placement Officer, <strong>' . e($req['po_name']) . '</strong> (' . e($req['po_email']) . '), '
        . 'who will update the institution\'s details on the portal. The sign-in details have been sent directly to the Placement Officer.</p>'
        . '<p>Reference: ' . e(enrollment_ref((int) $req['id'])) . '</p>';
    return send_mail($req['head_email'], 'Institution enrolled in Campus to Corporate', email_layout('Enrollment approved', $body), 'enroll_head');
}

/** Both contacts: request rejected, with the district's comment. */
function mail_enrollment_rejected(array $req, string $comment): void
{
    foreach ([[$req['po_email'], $req['po_name']], [$req['head_email'], $req['head_name']]] as [$to, $name]) {
        $body = '<p>Dear ' . e($name) . ',</p>'
            . '<p>The enrollment request ' . e(enrollment_ref((int) $req['id'])) . ' for <strong>' . e($req['institution_name']) . '</strong> was not approved by the '
            . e($req['district_name']) . ' district office.</p>'
            . '<p style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px"><strong>Comment:</strong> ' . nl2br(e($comment)) . '</p>'
            . '<p>You may submit a new request after addressing the comment, or contact the district office for clarification.</p>';
        send_mail($to, 'Enrollment request not approved', email_layout('Enrollment request not approved', $body), 'enroll_rejected');
    }
}

/** Acknowledgement to both contacts when a request is submitted (no links). */
function mail_enrollment_received(array $req): void
{
    foreach ([[$req['po_email'], $req['po_name']], [$req['head_email'], $req['head_name']]] as [$to, $name]) {
        $body = '<p>Dear ' . e($name) . ',</p>'
            . '<p>We have received the request to enroll <strong>' . e($req['institution_name']) . '</strong> in the Campus to Corporate programme '
            . '(reference <strong>' . e(enrollment_ref((int) $req['id'])) . '</strong>).</p>'
            . '<p>The ' . e($req['district_name']) . ' district office will review it. You will receive another email once it has been reviewed.</p>'
            . '<p style="font-size:12px;color:#64748b">If you did not expect this message, please contact the district office.</p>';
        send_mail($to, 'Enrollment request received - ' . enrollment_ref((int) $req['id']), email_layout('Enrollment request received', $body), 'enroll_received');
    }
}

/** Notify the district's users that a request is waiting for review. */
function mail_enrollment_district(array $req): void
{
    $st = db()->prepare("SELECT name, email FROM users WHERE role = 'district' AND district_id = ? AND is_active = 1 AND email IS NOT NULL AND email <> ''");
    $st->execute([$req['district_id']]);
    foreach ($st->fetchAll() as $u) {
        $body = '<p>Dear ' . e($u['name']) . ',</p>'
            . '<p>A new enrollment request (' . e(enrollment_ref((int) $req['id'])) . ') has been submitted for <strong>' . e($req['institution_name']) . '</strong>.</p>'
            . email_button(absolute_url('enrollments'), 'Review enrollment requests');
        send_mail($u['email'], 'New enrollment request - ' . $req['institution_name'], email_layout('New enrollment request', $body), 'enroll_district');
    }
}

/** Load a request with institution / district names. */
function load_enrollment(int $id, bool $forUpdate = false): ?array
{
    $st = db()->prepare('SELECT er.*, i.name AS institution_name, d.name AS district_name FROM enrollment_requests er
                         JOIN institutions i ON i.id = er.institution_id JOIN districts d ON d.id = er.district_id
                         WHERE er.id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
