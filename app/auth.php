<?php
declare(strict_types=1);

/*
 * Roles
 *  superadmin  - hidden system owner; never listed to other users
 *  admin       - settings, masters, users, all reports
 *  state       - Kerala state: all institutions, drill-down reports, add institutions, global masters
 *  district    - one of the 14 districts: its institutions, institution users, data updates
 *  institution - assigned institution(s): profile, officers, students, cohorts, services, dashboard
 *  state_entity    - view-only body (e.g. a university) scoped by a group filter:
 *                    districts (or all), universities, institution types, management types
 *  district_entity - the same, limited to one district
 *  Entities only see the Dashboard, Reports and their own account.
 */
const ROLES = [
    'superadmin'  => 'Super Admin',
    'admin'       => 'Administrator',
    'state'       => 'State User',
    'district'    => 'District User',
    'institution' => 'Institution User',
    'state_entity'    => 'State-level Entity',
    'district_entity' => 'District-level Entity',
];

/** Roles whose institution access is a group filter (user_scopes) instead of individual institutions. */
const ENTITY_ROLES = ['state_entity', 'district_entity'];

/** Dimensions of an entity's group scope => institutions column. */
const SCOPE_DIMENSIONS = [
    'district'   => 'district_id',
    'university' => 'university_id',
    'type'       => 'type_id',
    'category'   => 'category_id',
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

/** Group-scoped, view-only entity users (State-level / District-level Entities). */
function is_entity(?string $role = null): bool
{
    return in_array($role ?? user_role(), ENTITY_ROLES, true);
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
    static $cache = [];
    $userId ??= (int) (current_user()['id'] ?? 0);
    if (!isset($cache[$userId])) {
        $st = db()->prepare('SELECT institution_id FROM user_institutions WHERE user_id = ?');
        $st->execute([$userId]);
        $cache[$userId] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    return $cache[$userId];
}

/**
 * Group scope of an entity user: {district:int[], university:int[], type:int[], category:int[]}.
 * An empty list means "all". A District-level Entity is always held to its own district.
 */
function user_scope(?array $user = null): array
{
    static $cache = [];
    $user ??= current_user();
    $id = (int) ($user['id'] ?? 0);
    if (!isset($cache[$id])) {
        $scope = array_fill_keys(array_keys(SCOPE_DIMENSIONS), []);
        $st = db()->prepare('SELECT dimension, ref_id FROM user_scopes WHERE user_id = ? ORDER BY ref_id');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $r) {
            $scope[$r['dimension']][] = (int) $r['ref_id'];
        }
        $cache[$id] = $scope;
    }
    $scope = $cache[$id];
    if (($user['role'] ?? '') === 'district_entity') {
        $scope['district'] = [(int) $user['district_id']];
    }
    return $scope;
}

/** Does an institution row (district_id, university_id, type_id, category_id) fall inside a group scope? */
function in_user_scope(array $inst, array $scope): bool
{
    foreach (SCOPE_DIMENSIONS as $dim => $col) {
        if ($scope[$dim] && !in_array((int) ($inst[$col] ?? 0), $scope[$dim], true)) {
            return false;
        }
    }
    return true;
}

/** Human-readable description of a group scope, e.g. "KU · All districts · All institution types · All management types". */
function scope_summary(array $scope): string
{
    return implode(' · ', scope_parts($scope));
}

/** Group scope as {university, district, type, category} => readable list ("All …" when unrestricted). */
function scope_parts(array $scope): array
{
    $names = [
        'district'   => array_column(districts(), 'name', 'id'),
        'university' => array_map(fn($u) => $u['short_name'] ?: $u['name'], array_column(lookup('universities', false), null, 'id')),
        'type'       => array_column(lookup('institution_types', false), 'name', 'id'),
        'category'   => array_column(lookup('institution_categories', false), 'name', 'id'),
    ];
    $all = ['district' => 'All districts', 'university' => 'All universities', 'type' => 'All institution types', 'category' => 'All management types'];
    $parts = [];
    foreach (['university', 'district', 'type', 'category'] as $dim) {
        $parts[$dim] = $scope[$dim]
            ? implode(', ', array_map(fn($id) => $names[$dim][$id] ?? "#{$id}", $scope[$dim]))
            : $all[$dim];
    }
    return $parts;
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
    if (is_entity($user['role'])) {
        $parts = [];
        $params = [];
        foreach (user_scope($user) as $dim => $ids) {
            if ($ids) {
                $parts[] = "{$alias}." . SCOPE_DIMENSIONS[$dim] . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($params, ...$ids);
            }
        }
        return [$parts ? implode(' AND ', $parts) : '1=1', $params];
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
    if (is_entity($user['role'])) {
        return in_user_scope($inst, user_scope($user));
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
                                c.name AS category_name, t.name AS type_name
                         FROM institutions i
                         JOIN districts d ON d.id = i.district_id
                         LEFT JOIN universities u ON u.id = i.university_id
                         LEFT JOIN institution_categories c ON c.id = i.category_id
                         LEFT JOIN institution_types t ON t.id = i.type_id
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
        'superadmin' => ['superadmin', 'admin', 'state', 'district', 'institution', 'state_entity', 'district_entity'],
        'admin'      => ['admin', 'state', 'district', 'institution', 'state_entity', 'district_entity'],
        'district'   => ['institution'],
        default      => [],
    };
}

/**
 * Resolve the institution for a workspace page from ?id= (institution users with a
 * single assignment may omit it). Returns [institution, metrics, canEdit].
 * Entities may only open the (read-only) institution dashboard: $dashboard = true.
 */
function institution_context(bool $dashboard = false): array
{
    require_login();
    if (is_entity() && !$dashboard) {
        forbidden('Your account can view institution dashboards and reports only.');
    }
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
