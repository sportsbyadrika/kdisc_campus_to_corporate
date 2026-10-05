<?php
declare(strict_types=1);

/*
 * Roles
 *  superadmin  - hidden system owner; never listed to other users
 *  admin       - settings, masters, users, all reports
 *  state       - Kerala state: all institutions, drill-down reports, add institutions, global masters
 *  district    - one of the 14 districts: its institutions, institution users, data updates
 *  institution - assigned institution(s): profile, officers, students, cohorts, services, dashboard
 */
const ROLES = [
    'superadmin'  => 'Super Admin',
    'admin'       => 'Administrator',
    'state'       => 'State User',
    'district'    => 'District User',
    'institution' => 'Institution User',
];

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = $_SESSION['uid'] ?? null;
    if (!$id) {
        return $user = null;
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $st->execute([$id]);
    $user = $st->fetch() ?: null;
    if (!$user) {
        unset($_SESSION['uid']);
    }
    return $user;
}

function user_role(): ?string
{
    return current_user()['role'] ?? null;
}

function has_role(string ...$roles): bool
{
    $role = user_role();
    if ($role === 'superadmin') {
        return true; // super admin can do everything
    }
    return $role !== null && in_array($role, $roles, true);
}

/** State-level visibility: super admin, administrator and state users see the whole state. */
function is_state_level(): bool
{
    return has_role('admin', 'state');
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect('login');
    }
    return $user;
}

function require_role(string ...$roles): array
{
    $user = require_login();
    if (!has_role(...$roles)) {
        forbidden();
    }
    return $user;
}

function forbidden(string $message = 'You do not have permission to access this page.'): never
{
    http_response_code(403);
    $pageTitle = 'Access denied';
    require APP_ROOT . '/app/layout/header.php';
    echo '<div class="card max-w-xl mx-auto text-center py-12">'
        . '<div class="mx-auto w-12 h-12 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center mb-4">' . icon('warning', 'w-6 h-6') . '</div>'
        . '<h1 class="text-lg font-bold text-slate-900">Access denied</h1>'
        . '<p class="text-sm text-slate-500 mt-1">' . e($message) . '</p>'
        . '<a href="' . e(url('dashboard')) . '" class="btn-primary mt-6 inline-flex">Back to dashboard</a></div>';
    require APP_ROOT . '/app/layout/footer.php';
    exit;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ---------------------------------------------------------------------------
 * Institution scope
 * ------------------------------------------------------------------------- */

/** IDs of the institutions an institution user is assigned to. */
function assigned_institution_ids(?int $userId = null): array
{
    $userId ??= (int) (current_user()['id'] ?? 0);
    $st = db()->prepare('SELECT institution_id FROM user_institutions WHERE user_id = ?');
    $st->execute([$userId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * SQL fragment + params restricting an `institutions` alias to what the current user may see.
 * @return array{0:string,1:array}
 */
function institution_scope(string $alias = 'i'): array
{
    $user = current_user();
    if (is_state_level()) {
        return ['1=1', []];
    }
    if ($user['role'] === 'district') {
        return ["{$alias}.district_id = ?", [(int) $user['district_id']]];
    }
    $ids = assigned_institution_ids();
    if (!$ids) {
        return ['1=0', []];
    }
    return ["{$alias}.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

function can_view_institution(array $inst): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    if (is_state_level()) {
        return true;
    }
    if ($user['role'] === 'district') {
        return (int) $inst['district_id'] === (int) $user['district_id'];
    }
    return in_array((int) $inst['id'], assigned_institution_ids(), true);
}

/** Who can update an institution's data (profile, officers, students, cohorts, services). */
function can_edit_institution(array $inst): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    if (has_role('admin')) {
        return true;
    }
    return match ($user['role']) {
        'district'    => (int) $inst['district_id'] === (int) $user['district_id'],
        'institution' => in_array((int) $inst['id'], assigned_institution_ids(), true),
        default       => false,
    };
}

/** Load an institution by id (with joined names) and enforce view permission. */
function load_institution(int $id): array
{
    $st = db()->prepare('SELECT i.*, d.name AS district_name, u.name AS university_name, u.short_name AS university_short,
                                c.name AS category_name
                         FROM institutions i
                         JOIN districts d ON d.id = i.district_id
                         LEFT JOIN universities u ON u.id = i.university_id
                         LEFT JOIN institution_categories c ON c.id = i.category_id
                         WHERE i.id = ?');
    $st->execute([$id]);
    $inst = $st->fetch();
    if (!$inst) {
        http_response_code(404);
        forbidden('Institution not found.');
    }
    if (!can_view_institution($inst)) {
        forbidden('This institution is outside your access scope.');
    }
    return $inst;
}

/** Users that the current user may manage (create / edit). */
function can_manage_user(array $target): bool
{
    $user = current_user();
    if (!$user || (int) $target['id'] === (int) $user['id']) {
        return false; // own account is managed from "My Account"
    }
    if ($user['role'] === 'superadmin') {
        return true;
    }
    if ($target['role'] === 'superadmin') {
        return false;
    }
    if ($user['role'] === 'admin') {
        return true;
    }
    if ($user['role'] === 'district') {
        return $target['role'] === 'institution' && (int) $target['district_id'] === (int) $user['district_id'];
    }
    return false;
}

/** Roles the current user is allowed to create. */
function creatable_roles(): array
{
    return match (user_role()) {
        'superadmin' => ['superadmin', 'admin', 'state', 'district', 'institution'],
        'admin'      => ['admin', 'state', 'district', 'institution'],
        'district'   => ['institution'],
        default      => [],
    };
}

/**
 * Resolve the institution for a workspace page from ?id= (institution users with a
 * single assignment may omit it). Returns [institution, metrics, canEdit].
 */
function institution_context(): array
{
    require_login();
    $id = int_input('id');
    if (!$id) {
        $ids = user_role() === 'institution' ? assigned_institution_ids() : [];
        if (!$ids) {
            redirect('institutions');
        }
        $id = $ids[0];
    }
    $inst = load_institution($id);
    $m = institution_metrics('i.id = ?', [$id])[0];
    return [$inst, $m, can_edit_institution($inst)];
}

/**
 * SQL expression for a user's display name that keeps super admins anonymous
 * ("System") to everyone except other super admins.
 */
function user_name_sql(string $alias = 'u'): string
{
    if (user_role() === 'superadmin') {
        return "{$alias}.name";
    }
    return "CASE WHEN {$alias}.role = 'superadmin' THEN 'System' ELSE {$alias}.name END";
}
