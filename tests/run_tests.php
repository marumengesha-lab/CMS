<?php
/**
 * Integration test suite for the multi-level RBAC / data isolation.
 *
 * Run it against a database that already has database/integration_schema.sql
 * loaded:
 *
 *   DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=senbet_maderaja DB_USER=root DB_PASS= \
 *     php tests/run_tests.php
 *
 * It exercises the real Scope / Rbac / Controller code against real SQL, so
 * it is a genuine verification of the isolation rules rather than a mock.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("This test runner must be executed from the command line.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/config/bootstrap.php';

$GLOBALS['__passed']   = 0;
$GLOBALS['__failed']   = 0;
$GLOBALS['__failures'] = [];

function section(string $title): void
{
    echo "\n" . str_repeat('-', 72) . "\n{$title}\n" . str_repeat('-', 72) . "\n";
}

function check(string $name, bool $condition, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['__passed']++;
        echo "  [PASS] {$name}\n";
    } else {
        $GLOBALS['__failed']++;
        $GLOBALS['__failures'][] = $name . ($detail !== '' ? " -- {$detail}" : '');
        echo "  [FAIL] {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

function checkEquals(string $name, mixed $expected, mixed $actual): void
{
    check(
        $name,
        $expected === $actual,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
    );
}

/** Log in as a given username for the rest of the request. */
function actingAs(string $username): array
{
    $row = Database::one('SELECT `id` FROM `users` WHERE `username` = ?', [$username]);
    if (!$row) {
        throw new RuntimeException("Test user {$username} not found. Load the schema seed first.");
    }
    Session::set('_uid', (int) $row['id']);
    Auth::refresh();
    return Auth::user() ?? [];
}

function scopeOf(string $username): Scope
{
    actingAs($username);
    return Auth::scope();
}

function expectHttpError(string $name, int $status, callable $fn): void
{
    try {
        $fn();
        check($name, false, 'no exception was thrown');
    } catch (HttpError $e) {
        check($name, $e->status === $status, "expected HTTP {$status}, got {$e->status}: {$e->getMessage()}");
    } catch (Throwable $e) {
        check($name, false, get_class($e) . ': ' . $e->getMessage());
    }
}

function departmentIds(array $rows, string $column = 'id'): array
{
    $ids = array_map(static fn ($r) => (int) $r[$column], $rows);
    sort($ids);
    return $ids;
}

echo "Senbet Maderaja - RBAC / Scope integration tests\n";
echo 'Database: ' . Database::config()['name'] . ' @ ' . Database::config()['host'] . ':' . Database::config()['port'] . "\n";

// ---------------------------------------------------------------------
section('1. Scope resolution');
// ---------------------------------------------------------------------
$main    = scopeOf('main_admin');
$bole    = scopeOf('bole_admin');
$hawassa = scopeOf('hawassa_admin');
$ss      = scopeOf('ss_admin');
$youth   = scopeOf('youth_admin');
$teacher = scopeOf('primary_teacher');

checkEquals('main_admin resolves to global scope', Scope::GLOBAL, $main->type());
checkEquals('bole_admin resolves to institution scope', Scope::INSTITUTION, $bole->type());
checkEquals('bole_admin institution id', 1, $bole->institutionId());
checkEquals('hawassa_admin institution id', 2, $hawassa->institutionId());
checkEquals('ss_admin resolves to department scope', Scope::DEPARTMENT, $ss->type());
checkEquals('ss_admin anchor department', 1, $ss->departmentId());
checkEquals('primary_teacher anchor department', 3, $teacher->departmentId());

// ---------------------------------------------------------------------
section('2. Institution visibility (isolation at level 2)');
// ---------------------------------------------------------------------
checkEquals('main_admin sees both institutions', 2, count($main->visibleInstitutions()));
checkEquals('bole_admin sees only its own institution', 1, count($bole->visibleInstitutions()));
checkEquals('bole_admin cannot see Hawassa', false, $bole->canAccessInstitution(2));
checkEquals('bole_admin can see its own institution', true, $bole->canAccessInstitution(1));
checkEquals('hawassa_admin cannot see Bole', false, $hawassa->canAccessInstitution(1));

// ---------------------------------------------------------------------
section('3. Department sub-tree resolution (isolation at level 3)');
// ---------------------------------------------------------------------
checkEquals('main_admin sees all 4 departments', 4, count($main->visibleDepartments()));
checkEquals('bole_admin sees the 3 departments of institution 1', 3, count($bole->visibleDepartments()));
checkEquals('ss_admin sub-tree is [1, 3]', [1, 3], departmentIds($ss->visibleDepartments(), 'id'));
checkEquals('youth_admin sub-tree is [2]', [2], departmentIds($youth->visibleDepartments(), 'id'));
checkEquals('teacher sub-tree is [3]', [3], departmentIds($teacher->visibleDepartments(), 'id'));
checkEquals('ss_admin can reach its sub-department 3', true, $ss->canAccessDepartment(3));
checkEquals('ss_admin cannot reach sibling department 2', false, $ss->canAccessDepartment(2));
checkEquals('ss_admin cannot reach department 4 (other institution)', false, $ss->canAccessDepartment(4));
checkEquals('youth_admin cannot reach department 1', false, $youth->canAccessDepartment(1));
checkEquals('teacher cannot reach its parent department 1', false, $teacher->canAccessDepartment(1));
checkEquals('main_admin can reach any department', true, $main->canAccessDepartment(4));

// ---------------------------------------------------------------------
section('4. The generated SQL predicate');
// ---------------------------------------------------------------------
[$sqlGlobal, $pGlobal] = $main->where('t', ['institution' => 'institution_id', 'department' => 'department_id']);
checkEquals('global predicate is unrestricted', '1 = 1', $sqlGlobal);
checkEquals('global predicate has no bindings', 0, count($pGlobal));

[$sqlInst, $pInst] = $bole->where('t', ['institution' => 'institution_id', 'department' => 'department_id']);
checkEquals('institution predicate filters on institution only', 't.institution_id = ?', $sqlInst);
checkEquals('institution predicate binds the institution id', [1], $pInst);

[$sqlDept, $pDept] = $ss->where('t', ['institution' => 'institution_id', 'department' => 'department_id']);
check('department predicate uses an IN list', str_contains($sqlDept, 'IN (?,?)'), $sqlDept);
checkEquals('department predicate binds institution + sub-tree', [1, 1, 3], $pDept);

$anon = Scope::forUser(null);
checkEquals('unauthenticated scope matches nothing', '1 = 0', $anon->where()[0]);

// ---------------------------------------------------------------------
section('5. Module data isolation (members table)');
// ---------------------------------------------------------------------
actingAs('main_admin');
$resource = new ResourceController();

$mainMembers = $resource->index('members', [], 1, 100);
checkEquals('main_admin sees all 5 members', 5, $mainMembers['total']);

actingAs('bole_admin');
$boleMembers = (new ResourceController())->index('members', [], 1, 100);
checkEquals('bole_admin sees the 4 members of institution 1', 4, $boleMembers['total']);
checkEquals('  ...and none of them is the Hawassa member', false, in_array(5, departmentIds($boleMembers['rows']), true));

actingAs('ss_admin');
$ssMembers = (new ResourceController())->index('members', [], 1, 100);
checkEquals('ss_admin sees members of departments 1 and 3 only', 3, $ssMembers['total']);
checkEquals('  ...which are members 1, 2 and 3', [1, 2, 3], departmentIds($ssMembers['rows']));

actingAs('youth_admin');
$youthMembers = (new ResourceController())->index('members', [], 1, 100);
checkEquals('youth_admin sees only its own member', 1, $youthMembers['total']);
checkEquals('  ...which is member 4', [4], departmentIds($youthMembers['rows']));

actingAs('hawassa_admin');
$hawassaMembers = (new ResourceController())->index('members', [], 1, 100);
checkEquals('hawassa_admin sees only the Hawassa member', 1, $hawassaMembers['total']);
checkEquals('  ...which is member 5', [5], departmentIds($hawassaMembers['rows']));

// Reading a single row outside the scope must fail.
actingAs('ss_admin');
expectHttpError('ss_admin cannot read member 4 (sibling department)', 403, function () {
    (new ResourceController())->find('members', 4);
});
expectHttpError('ss_admin cannot read member 5 (other institution)', 403, function () {
    (new ResourceController())->find('members', 5);
});

actingAs('ss_admin');
$own = (new ResourceController())->find('members', 1);
checkEquals('ss_admin can read its own member', 'Abebe Kebede', $own['full_name']);

// ---------------------------------------------------------------------
section('6. Aggregated vs restricted reports');
// ---------------------------------------------------------------------
actingAs('bole_admin');
$boleReport = (new ReportController())->departmentAggregate();
checkEquals('Institution Admin report covers all 3 departments of the institution', 3, count($boleReport));
checkEquals(
    '  ...and aggregates both departments (SS + Youth)',
    true,
    count(array_filter($boleReport, static fn ($r) => in_array((int) $r['department_id'], [1, 2], true))) === 2
);

actingAs('ss_admin');
$ssReport = (new ReportController())->departmentAggregate();
checkEquals('Department Admin report covers only its sub-tree (2 rows)', 2, count($ssReport));
checkEquals(
    '  ...and never leaks the sibling department',
    false,
    in_array(2, departmentIds($ssReport, 'department_id'), true)
);

actingAs('youth_admin');
$youthReport = (new ReportController())->departmentAggregate();
checkEquals('Youth Admin report covers exactly 1 department', 1, count($youthReport));
checkEquals('  ...which is department 2', 2, (int) $youthReport[0]['department_id']);

// ---------------------------------------------------------------------
section('7. Permission checks (RBAC)');
// ---------------------------------------------------------------------
$boleUser = actingAs('bole_admin');
checkEquals('Institution Admin may create departments', true, Rbac::can($boleUser, 'department.create'));
checkEquals('Institution Admin may assign department admins', true, Rbac::can($boleUser, 'department.assign_admin'));
checkEquals('Institution Admin may NOT create institutions', false, Rbac::can($boleUser, 'institution.create'));
checkEquals('Institution Admin may see aggregated reports', true, Rbac::can($boleUser, 'report.view_aggregate'));

$ssUser = actingAs('ss_admin');
checkEquals('Department Admin may create sub-departments', true, Rbac::can($ssUser, 'department.create'));
checkEquals('Department Admin may assign roles', true, Rbac::can($ssUser, 'user.assign_role'));
checkEquals('Department Admin may NOT create institutions', false, Rbac::can($ssUser, 'institution.create'));
checkEquals('Department Admin may read finance', true, Rbac::can($ssUser, 'finance.view'));

$teacherUser = actingAs('primary_teacher');
checkEquals('Teacher may manage grades', true, Rbac::can($teacherUser, 'academics.grade.manage'));
checkEquals('Teacher may NOT read finance', false, Rbac::can($teacherUser, 'finance.view'));
checkEquals('Teacher may NOT create departments', false, Rbac::can($teacherUser, 'department.create'));

$mainUser = actingAs('main_admin');
checkEquals('Main Admin holds every permission (bypass)', true, Rbac::can($mainUser, 'institution.delete'));
checkEquals('Main Admin holds an arbitrary future code', true, Rbac::can($mainUser, 'some.future.permission'));

// ---------------------------------------------------------------------
section('8. Privilege-escalation guards');
// ---------------------------------------------------------------------
$instAdminRole = Database::one("SELECT `id` FROM `roles` WHERE `code` = 'institution_admin' AND `institution_id` IS NULL");
$deptAdminRole = Database::one("SELECT `id` FROM `roles` WHERE `code` = 'department_admin' AND `institution_id` IS NULL");
$teacherRole   = Database::one("SELECT `id` FROM `roles` WHERE `code` = 'teacher' AND `institution_id` IS NULL");

actingAs('ss_admin');
expectHttpError('Department Admin cannot appoint an Institution Admin', 403, function () use ($instAdminRole) {
    (new UserController())->assignRole(6, (int) $instAdminRole['id'], Scope::INSTITUTION, 1);
});
expectHttpError('Department Admin cannot grant a role in a sibling department', 403, function () use ($teacherRole) {
    (new UserController())->assignRole(5, (int) $teacherRole['id'], Scope::DEPARTMENT, 2);
});
expectHttpError('Department Admin cannot grant a role in another institution', 403, function () use ($teacherRole) {
    (new UserController())->assignRole(3, (int) $teacherRole['id'], Scope::DEPARTMENT, 4);
});

actingAs('bole_admin');
expectHttpError('Institution Admin cannot appoint an Institution Admin', 403, function () use ($instAdminRole) {
    (new UserController())->assignRole(6, (int) $instAdminRole['id'], Scope::INSTITUTION, 1);
});
expectHttpError('Institution Admin cannot grant a role in another institution', 403, function () use ($teacherRole) {
    (new UserController())->assignRole(3, (int) $teacherRole['id'], Scope::DEPARTMENT, 4);
});

// The legitimate delegation must succeed: Institution Admin -> Department Admin.
actingAs('bole_admin');
$grant = (new UserController())->assignRole(6, (int) $deptAdminRole['id'], Scope::DEPARTMENT, 3);
check('Institution Admin CAN appoint a Department Admin', isset($grant['assignment_id']));
checkEquals('  ...and the user now has 2 role assignments', 2, count(Auth::rolesFor(6)));

actingAs('bole_admin');
(new UserController())->revokeRole((int) $grant['assignment_id']);
checkEquals('The extra assignment was revoked again', 1, count(Auth::rolesFor(6)));

// ---------------------------------------------------------------------
section('9. Department creation, scope anchoring and path maintenance');
// ---------------------------------------------------------------------
$suffix = (string) time();

actingAs('ss_admin');
// A Department Admin posts a forged institution_id - it must be ignored.
$forged = (new DepartmentController())->store([
    'name'           => 'Forged Dept ' . $suffix,
    'code'           => 'FORGED-' . $suffix,
    'institution_id' => 2,
]);
checkEquals('Forged institution_id is ignored (pinned to own institution)', 1, (int) $forged['institution_id']);
checkEquals('A Department Admin nests under its own department', 1, (int) $forged['parent_id']);

actingAs('bole_admin');
$parent = (new DepartmentController())->store([
    'name' => 'Choir ' . $suffix,
    'code' => 'CHOIR-' . $suffix,
]);
checkEquals('New root department depth is 0', 0, (int) $parent['depth']);
checkEquals('New root department path', '/' . $parent['id'] . '/', $parent['path']);

$child = (new DepartmentController())->store([
    'name'      => 'Junior Choir ' . $suffix,
    'code'      => 'JCHOIR-' . $suffix,
    'parent_id' => (int) $parent['id'],
]);
checkEquals('Child department depth is 1', 1, (int) $child['depth']);
checkEquals('Child department path is nested', '/' . $parent['id'] . '/' . $child['id'] . '/', $child['path']);
checkEquals('Child defaults to the sub_department type', 'sub_department', $child['type']);

expectHttpError('Cannot move a department into its own descendant', 422, function () use ($parent, $child) {
    (new DepartmentController())->update((int) $parent['id'], ['parent_id' => (int) $child['id']]);
});

$moved = (new DepartmentController())->update((int) $child['id'], ['parent_id' => null]);
checkEquals('Moved department depth resets to 0', 0, (int) $moved['depth']);
checkEquals('Moved department path is rewritten', '/' . $child['id'] . '/', $moved['path']);

$grandchild = (new DepartmentController())->store([
    'name'      => 'Tiny Choir ' . $suffix,
    'code'      => 'TCHOIR-' . $suffix,
    'parent_id' => (int) $child['id'],
]);
expectHttpError('Cannot delete a department that still has sub-departments', 409, function () use ($child) {
    (new DepartmentController())->destroy((int) $child['id']);
});

actingAs('ss_admin');
expectHttpError('Department Admin cannot open a sibling department', 403, function () {
    (new DepartmentController())->find(2);
});
expectHttpError('Department Admin cannot update a sibling department', 403, function () {
    (new DepartmentController())->update(2, ['name' => 'Hijacked']);
});
expectHttpError('Department Admin cannot delete a sibling department', 403, function () {
    (new DepartmentController())->destroy(2);
});
expectHttpError('Department Admin cannot open a department of another institution', 403, function () {
    (new DepartmentController())->find(4);
});

foreach ([(int) $grandchild['id'], (int) $child['id'], (int) $parent['id'], (int) $forged['id']] as $deptId) {
    Database::delete('departments', ['id' => $deptId]);
}
Scope::flushCache();
checkEquals('Cleanup removed the temporary departments', 0, (int) Database::value(
    'SELECT COUNT(*) FROM `departments` WHERE `code` LIKE ?',
    ['%' . $suffix],
    0
));

// ---------------------------------------------------------------------
section('10. Write isolation on module data');
// ---------------------------------------------------------------------
actingAs('youth_admin');
$newMember = (new ResourceController())->store('members', [
    'member_code' => 'YT-TEST-' . $suffix,
    'full_name'   => 'Scope Test Member',
]);
checkEquals('A member created by a Department Admin lands in its own department', 2, (int) $newMember['department_id']);
checkEquals('  ...and in the correct institution', 1, (int) $newMember['institution_id']);

expectHttpError('Cannot create a member in a sibling department', 403, function () use ($suffix) {
    (new ResourceController())->store('members', [
        'member_code'   => 'BAD-' . $suffix,
        'full_name'     => 'Wrong Department',
        'department_id' => 1,
    ]);
});
expectHttpError('Cannot update a member of another department', 403, function () {
    (new ResourceController())->update('members', 1, ['full_name' => 'Hijacked']);
});
expectHttpError('Cannot delete a member of another department', 403, function () {
    (new ResourceController())->destroy('members', 1);
});

Database::delete('members', ['id' => (int) $newMember['id']]);
checkEquals('Cleanup removed the temporary member', 0, (int) Database::value(
    'SELECT COUNT(*) FROM `members` WHERE `member_code` LIKE ?',
    ['%' . $suffix],
    0
));

// ---------------------------------------------------------------------
echo "\n" . str_repeat('=', 72) . "\n";
echo sprintf("RESULT: %d passed, %d failed\n", $GLOBALS['__passed'], $GLOBALS['__failed']);
if ($GLOBALS['__failed'] > 0) {
    echo "\nFailures:\n";
    foreach ($GLOBALS['__failures'] as $failure) {
        echo "  - {$failure}\n";
    }
}
echo str_repeat('=', 72) . "\n";

exit($GLOBALS['__failed'] === 0 ? 0 : 1);
