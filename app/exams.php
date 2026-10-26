<?php
declare(strict_types=1);

/*
 * Online Exam module (Activity menu)
 *
 *  - An administrator creates an exam and is its owner. Only the owner and the co-admins the
 *    owner adds (any active portal user) can see or change it; the super admin sees every exam.
 *  - Master question bank: single-choice, multiple-choice and descriptive questions.
 *    Question series are sets drawn from the bank; each participant is given one series
 *    (spread evenly) and, optionally, a shuffled question and option order.
 *  - Participants receive an exam password by email with an expiry. Answers are taken one
 *    question at a time with no way back; the attempt ends on completion, time-out or logout.
 *    Losing the connection or closing the browser does not end it - the participant signs in
 *    again and carries on until the deadline.
 */

const EXAM_QTYPES = [
    'single'      => 'Objective - single selection',
    'multi'       => 'Objective - multiple selection',
    'descriptive' => 'Descriptive',
];
const EXAM_STATUSES = ['draft' => 'Draft', 'active' => 'Active', 'closed' => 'Closed'];
const EXAM_FINISH_REMARKS = [
    'completed' => 'Completed normally',
    'timeout'   => 'Time out (auto-submitted)',
    'logout'    => 'Logged out by the participant',
];
/** Seconds after the deadline an in-flight answer is still accepted (slow networks). */
const EXAM_GRACE_SECONDS = 20;

function now_sql(): string
{
    return date('Y-m-d H:i:s');
}

/* ---------------------------------------------------------------------------
 * Access
 * ------------------------------------------------------------------------- */

/** IDs of the exams the current user may open (owner or co-admin; super admin: all). */
function exam_accessible_ids(): array
{
    static $ids = null;
    if ($ids === null) {
        $user = current_user();
        if (!$user) {
            return $ids = [];
        }
        if ($user['role'] === 'superadmin') {
            $ids = array_map('intval', db()->query('SELECT id FROM exams')->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $st = db()->prepare('SELECT id FROM exams WHERE created_by = ? UNION SELECT exam_id FROM exam_admins WHERE user_id = ?');
            $st->execute([$user['id'], $user['id']]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
    }
    return $ids;
}

/** Administrators can create exams; co-admins of an exam reach it through the Activity menu too. */
function can_create_exams(): bool
{
    return has_role('admin');
}

function has_activity_access(): bool
{
    return can_create_exams() || exam_accessible_ids() !== [];
}

function is_exam_owner(array $exam): bool
{
    $user = current_user();
    return $user && ($user['role'] === 'superadmin' || (int) $exam['created_by'] === (int) $user['id']);
}

/** Load the exam named by ?id= (or $id) and make sure the current user may open it. */
function load_exam(?int $id = null): array
{
    require_login();
    $id ??= (int) int_input('id', 0);
    $st = db()->prepare('SELECT x.*, u.name AS owner_name FROM exams x LEFT JOIN users u ON u.id = x.created_by WHERE x.id = ?');
    $st->execute([$id]);
    $exam = $st->fetch();
    if (!$exam) {
        http_response_code(404);
        forbidden('Exam not found.');
    }
    if (!in_array((int) $exam['id'], exam_accessible_ids(), true)) {
        forbidden('This exam is visible only to its creator and co-admins.');
    }
    return $exam;
}

/** Once any participant has started, the question bank and series are frozen (results stay comparable). */
function exam_is_locked(int $examId): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM exam_participants WHERE exam_id = ? AND started_at IS NOT NULL');
    $st->execute([$examId]);
    return (int) $st->fetchColumn() > 0;
}

/* ---------------------------------------------------------------------------
 * Questions
 * ------------------------------------------------------------------------- */

/** Question bank of an exam, each with an 'options' list. */
function exam_questions(int $examId): array
{
    $st = db()->prepare('SELECT * FROM exam_questions WHERE exam_id = ? ORDER BY sort_order, id');
    $st->execute([$examId]);
    $questions = $st->fetchAll();
    $options = exam_options_for(array_column($questions, 'id'));
    foreach ($questions as &$q) {
        $q['options'] = $options[(int) $q['id']] ?? [];
    }
    return $questions;
}

/** @return array<int, array> question id => options */
function exam_options_for(array $questionIds): array
{
    if (!$questionIds) {
        return [];
    }
    $st = db()->prepare('SELECT * FROM exam_options WHERE question_id IN (' . implode(',', array_fill(0, count($questionIds), '?')) . ') ORDER BY sort_order, id');
    $st->execute(array_values($questionIds));
    $out = [];
    foreach ($st->fetchAll() as $o) {
        $out[(int) $o['question_id']][] = $o;
    }
    return $out;
}

/** Same participant + question always gets the same option order, so a reload never reshuffles. */
function exam_option_order(array $options, int $participantId, int $questionId, bool $shuffle): array
{
    if ($shuffle) {
        usort($options, fn($a, $b) => strcmp(md5("{$participantId}-{$questionId}-{$a['id']}"), md5("{$participantId}-{$questionId}-{$b['id']}")));
    }
    return $options;
}

/* ---------------------------------------------------------------------------
 * Participant state
 * ------------------------------------------------------------------------- */

/** 'upcoming' | 'open' | 'over' for the exam's start/end window and status. */
function exam_window(array $exam): string
{
    $now = time();
    if ($exam['status'] === 'closed' || $now > strtotime($exam['end_at'])) {
        return 'over';
    }
    return $now < strtotime($exam['start_at']) ? 'upcoming' : 'open';
}

/**
 * Display status of a participant: Completed (with remark), In Progress, Absent, Invited or Not Invited.
 * @return array{status:string, remark:string}
 */
function participant_state(array $p, array $exam): array
{
    if ($p['finished_at']) {
        return ['status' => 'Completed', 'remark' => EXAM_FINISH_REMARKS[$p['finish_reason']] ?? ''];
    }
    if ($p['started_at']) {
        return ['status' => 'In Progress', 'remark' => 'Until ' . date('d M, h:i A', strtotime($p['deadline_at']))];
    }
    if (exam_window($exam) === 'over') {
        return ['status' => 'Absent', 'remark' => 'Did not sign in before the cut-off'];
    }
    if ($p['invited_at']) {
        $expired = $p['password_expires_at'] && strtotime($p['password_expires_at']) < time();
        return ['status' => $expired ? 'Expired' : 'Invited', 'remark' => ($expired ? 'Password expired ' : 'Password valid till ') . date('d M, h:i A', strtotime($p['password_expires_at']))];
    }
    return ['status' => 'Not Invited', 'remark' => 'Exam password not sent yet'];
}

/** Close attempts whose deadline has passed (participant never came back): time-out at the deadline. */
function exam_finalize_overdue(int $examId): void
{
    $st = db()->prepare('SELECT id, deadline_at FROM exam_participants WHERE exam_id = ? AND started_at IS NOT NULL AND finished_at IS NULL AND deadline_at < ?');
    $st->execute([$examId, date('Y-m-d H:i:s', time() - EXAM_GRACE_SECONDS)]);
    foreach ($st->fetchAll() as $p) {
        exam_finish((int) $p['id'], 'timeout', $p['deadline_at']);
    }
}

/** End an attempt (once) and score it. */
function exam_finish(int $participantId, string $reason, ?string $at = null): void
{
    $st = db()->prepare('UPDATE exam_participants SET finished_at = ?, finish_reason = ? WHERE id = ? AND finished_at IS NULL');
    $st->execute([$at ?? now_sql(), $reason, $participantId]);
    exam_score($participantId);
}

/**
 * (Re)compute a participant's score. Objective answers are marked automatically: a
 * single-choice answer must pick the correct option; a multiple-choice answer must pick
 * exactly the correct set. Descriptive answers keep the marks given by the grader.
 */
function exam_score(int $participantId): void
{
    $st = db()->prepare('SELECT question_order FROM exam_participants WHERE id = ?');
    $st->execute([$participantId]);
    $order = json_decode((string) $st->fetchColumn(), true) ?: [];
    if (!$order) {
        return;
    }
    $in = implode(',', array_fill(0, count($order), '?'));
    $st = db()->prepare("SELECT id, qtype, marks FROM exam_questions WHERE id IN ($in)");
    $st->execute($order);
    $questions = array_column($st->fetchAll(), null, 'id');
    $correct = [];
    foreach (exam_options_for(array_keys($questions)) as $qid => $opts) {
        $ids = array_map(fn($o) => (int) $o['id'], array_filter($opts, fn($o) => (int) $o['is_correct'] === 1));
        sort($ids);
        $correct[$qid] = $ids;
    }
    $st = db()->prepare('SELECT * FROM exam_answers WHERE participant_id = ?');
    $st->execute([$participantId]);
    $upd = db()->prepare('UPDATE exam_answers SET is_correct = ?, marks_awarded = ? WHERE participant_id = ? AND question_id = ?');
    $score = 0.0;
    foreach ($st->fetchAll() as $a) {
        $q = $questions[(int) $a['question_id']] ?? null;
        if (!$q) {
            continue;
        }
        if ($q['qtype'] === 'descriptive') {
            $score += (float) ($a['marks_awarded'] ?? 0);
            continue;
        }
        $picked = array_map('intval', json_decode((string) $a['selected'], true) ?: []);
        sort($picked);
        $ok = $picked && $picked === ($correct[(int) $q['id']] ?? []);
        $marks = $ok ? (float) $q['marks'] : 0.0;
        $upd->execute([$ok ? 1 : 0, $marks, $participantId, $a['question_id']]);
        $score += $marks;
    }
    $max = array_sum(array_map(fn($q) => (float) $q['marks'], $questions));
    db()->prepare('UPDATE exam_participants SET score = ?, max_score = ? WHERE id = ?')->execute([$score, $max, $participantId]);
}

/**
 * Pick the question set for a participant starting now: the least-used series (ties at
 * random), or the whole bank when no series exist; shuffled when the exam says so.
 * @return array{0:?int, 1:int[]} [series id, question ids]
 */
function exam_assign_questions(array $exam): array
{
    $st = db()->prepare('SELECT s.id, (SELECT COUNT(*) FROM exam_participants p WHERE p.series_id = s.id) AS used
                         FROM exam_series s WHERE s.exam_id = ? AND EXISTS (SELECT 1 FROM exam_series_questions q WHERE q.series_id = s.id)');
    $st->execute([$exam['id']]);
    $series = $st->fetchAll();
    $seriesId = null;
    if ($series) {
        $min = min(array_column($series, 'used'));
        $least = array_values(array_filter($series, fn($s) => (int) $s['used'] === (int) $min));
        $seriesId = (int) $least[random_int(0, count($least) - 1)]['id'];
        $st = db()->prepare('SELECT question_id FROM exam_series_questions WHERE series_id = ? ORDER BY sort_order, question_id');
        $st->execute([$seriesId]);
    } else {
        $st = db()->prepare('SELECT id FROM exam_questions WHERE exam_id = ? ORDER BY sort_order, id');
        $st->execute([$exam['id']]);
    }
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if ($exam['shuffle_questions']) {
        for ($i = count($ids) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        }
    }
    return [$seriesId, $ids];
}

/* ---------------------------------------------------------------------------
 * Invitations and results by email
 * ------------------------------------------------------------------------- */

/** A readable one-time exam password (no 0/O/1/I/L). */
function exam_password(): string
{
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 10; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

/** Issue a fresh exam password (replacing any earlier one) and email it. */
function exam_invite(array $p, array $exam, string $expiresAt): bool
{
    $password = exam_password();
    db()->prepare('UPDATE exam_participants SET password_hash = ?, password_expires_at = ?, invited_at = ?, session_token = NULL WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $expiresAt, now_sql(), $p['id']]);
    $fmt = fn(string $d) => date('d M Y, h:i A', strtotime($d));
    $body = '<p>Dear ' . e($p['name']) . ',</p>'
        . '<p>You are registered for the online exam <strong>' . e($exam['title']) . '</strong>.</p>'
        . '<table cellpadding="6" style="border-collapse:collapse;font-size:14px;margin:12px 0">'
        . '<tr><td style="color:#64748b">Exam window</td><td><strong>' . e($fmt($exam['start_at'])) . '</strong> to <strong>' . e($fmt($exam['end_at'])) . '</strong></td></tr>'
        . '<tr><td style="color:#64748b">Duration</td><td><strong>' . (int) $exam['duration_minutes'] . ' minutes</strong></td></tr>'
        . '<tr><td style="color:#64748b">Sign-in email</td><td><strong>' . e($p['email']) . '</strong></td></tr>'
        . '<tr><td style="color:#64748b">Exam password</td><td><strong style="font-family:monospace;font-size:18px;letter-spacing:2px">' . e($password) . '</strong></td></tr>'
        . '<tr><td style="color:#64748b">Password valid till</td><td><strong>' . e($fmt($expiresAt)) . '</strong></td></tr>'
        . '</table>'
        . email_button(absolute_url('exam-login'), 'Open the exam')
        . '<p>Please note: questions are shown one at a time and you cannot go back to a previous question. '
        . 'Copying and pasting is disabled. Signing out ends your exam. If your connection drops, sign in again to continue before the time runs out.</p>'
        . '<p>Do not share this password with anyone.</p>';
    return send_mail($p['email'], 'Online exam: ' . $exam['title'] . ' - your exam password', email_layout('Your exam password', $body), 'exam_invite');
}

function exam_mail_result(array $p, array $exam): bool
{
    $pct = (float) $p['max_score'] > 0 ? round((float) $p['score'] / (float) $p['max_score'] * 100, 1) : 0;
    $body = '<p>Dear ' . e($p['name']) . ',</p>'
        . '<p>The results of <strong>' . e($exam['title']) . '</strong> have been published.</p>'
        . '<p style="font-size:16px">Your score: <strong>' . e(exam_num($p['score'])) . ' / ' . e(exam_num($p['max_score'])) . '</strong> (' . $pct . '%)</p>';
    return send_mail($p['email'], 'Result: ' . $exam['title'], email_layout('Exam result', $body), 'exam_result');
}

/** 12.50 -> "12.5", 3.00 -> "3" */
function exam_num(mixed $n): string
{
    return $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
}

/** Validate the exam settings form shared by "create" and "exam settings". */
function exam_settings_input(): array
{
    $d = [
        'title'            => mb_substr(trim((string) input('title')), 0, 200),
        'instructions'     => nullable(mb_substr((string) input('instructions'), 0, 10000)),
        'start_at'         => datetime_input('start_at'),
        'end_at'           => datetime_input('end_at'),
        'duration_minutes' => (int) int_input('duration_minutes', 0),
    ];
    $errors = [];
    if ($d['title'] === '') $errors[] = 'Exam name is required.';
    if (!$d['start_at'] || !$d['end_at']) $errors[] = 'Enter the starting and ending date and time.';
    elseif (strtotime($d['end_at']) <= strtotime($d['start_at'])) $errors[] = 'The ending time must be after the starting time.';
    if ($d['duration_minutes'] < 1 || $d['duration_minutes'] > 1440) $errors[] = 'Duration must be between 1 and 1440 minutes.';
    return [$d, $errors];
}

/** Convert an <input type="datetime-local"> value to SQL, or null. */
function datetime_input(string $key): ?string
{
    $v = trim((string) input($key));
    $ts = $v !== '' ? strtotime($v) : false;
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/** SQL datetime -> value for <input type="datetime-local"> */
function datetime_local(?string $sql): string
{
    return $sql ? date('Y-m-d\TH:i', strtotime($sql)) : '';
}

/* ---------------------------------------------------------------------------
 * Participant (exam portal) session - separate from portal user sign-in
 * ------------------------------------------------------------------------- */

function exam_login_participant(array $p): void
{
    session_regenerate_id(true);
    $token = bin2hex(random_bytes(32));
    db()->prepare('UPDATE exam_participants SET session_token = ?, last_ip = ? WHERE id = ?')->execute([hash('sha256', $token), client_ip(), $p['id']]);
    $_SESSION['exam_pid'] = (int) $p['id'];
    $_SESSION['exam_token'] = $token;
}

function exam_logout_participant(): void
{
    unset($_SESSION['exam_pid'], $_SESSION['exam_token']);
}

/** Signed-in participant, or null. A sign-in on another device ends this one. */
function current_participant(): ?array
{
    $pid = (int) ($_SESSION['exam_pid'] ?? 0);
    if (!$pid) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM exam_participants WHERE id = ?');
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p || !hash_equals((string) $p['session_token'], hash('sha256', (string) ($_SESSION['exam_token'] ?? '')))) {
        exam_logout_participant();
        flash('error', $p ? 'You were signed out because this exam was opened in another browser or device.' : 'Please sign in.');
        return null;
    }
    return $p;
}
