# ሰንበት ማደራጃ — Sabbath School Management System

A complete CMS for a Sabbath School, built around a **multi-level, dynamic RBAC**
model with real row-level **data isolation**.

| | |
|---|---|
| Stack | PHP 8.1+ · MySQL 5.7+ / MariaDB 10.2+ · PDO · vanilla HTML/CSS/JS (no build step) |
| Runs on | XAMPP / WAMP / LAMP — just drop the folder in `htdocs` |
| Deliverables | 1) database schema 2) SQL + middleware/policy isolation logic 3) CRUD API + controllers |
| Verified | 91 database integration assertions + 33 HTTP end-to-end assertions, all green |

---

## 1. The organisational hierarchy and how it maps to code

```
┌─────────────────────────────────────────────────────────────────────────┐
│  LEVEL 1 — MAIN ADMIN                     scope = GLOBAL                │
│  • creates institutions                                                 │
│  • appoints the Institution Admin of each institution                   │
│  • sees everything                                                      │
└───────────────────────────────┬─────────────────────────────────────────┘
                                │ creates institutions + appoints
                                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  LEVEL 2 — INSTITUTION ADMIN              scope = INSTITUTION (#id)     │
│  • creates 2+ departments / divisions under the institution             │
│  • appoints a Department Admin for each of them                         │
│  • sees the AGGREGATED report of all its departments                    │
│  • can never see a sibling institution                                  │
└───────────────────────────────┬─────────────────────────────────────────┘
                                │ creates departments + appoints
                                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  LEVEL 3 — DEPARTMENT ADMIN               scope = DEPARTMENT (#id)      │
│  • creates sub-departments / teams under its own department             │
│  • grants roles + permissions to the members below it                   │
│  • sees ONLY its own department sub-tree                                │
└─────────────────────────────────────────────────────────────────────────┘
```

| Concept | Where it lives |
|---|---|
| Who the user is | `users`, `user_roles` |
| What they may **do** | `roles` + `permissions` + `role_permissions` → `Rbac::can()` |
| Which **rows** they may touch | `Scope` class (`src/Scope.php`) |
| Middleware / policy layer | `Guard` class (`src/Guard.php`) |
| The delegation rules | `InstitutionController::assignAdmin()`, `DepartmentController::assignAdmin()`, `UserController::assignRole()` |

---

## 2. Quick start (XAMPP)

```bash
# 1. copy the project into the web root
#    C:\xampp\htdocs\senbet_maderaja\

# 2. create the database + load the schema (either way works)
php database/install.php
#   ... or in phpMyAdmin: import database/integration_schema.sql

# 3. open the app
#    http://localhost/senbet_maderaja/login.php
```

If your MySQL is not `root` with an empty password, either edit `config/config.php`
or set environment variables (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`,
`APP_DEBUG`).

### Demo accounts

| Username | Password | Role | Sees |
|---|---|---|---|
| `main_admin` | `Admin@123` | Main System Admin | every institution |
| `bole_admin` | `Admin@123` | Institution Admin | all 3 departments of Bole |
| `hawassa_admin` | `Admin@123` | Institution Admin | only Hawassa |
| `ss_admin` | `Senbet@123` | Department Admin | Sabbath School + its sub-department |
| `youth_admin` | `Senbet@123` | Department Admin | Youth Ministry only |
| `primary_teacher` | `Senbet@123` | Teacher | Primary Division only |

> Change these before going live — see `database/integration_schema.sql` section 14.

---

## 3. Project structure

```
senbet_maderaja/
├── index.php                     dashboard (scope-aware stats + reports)
├── login.php  logout.php
├── config/
│   ├── config.php                DB + app settings (env-overridable)
│   ├── bootstrap.php             autoloader, session, error handler
│   └── resources.php             declarative definition of every module entity
├── src/
│   ├── Database.php              PDO wrapper (query/insert/update/transaction)
│   ├── Session.php  Csrf.php  HttpError.php  ErrorReporter.php
│   ├── Auth.php                  login, identity + roles + permissions loading
│   ├── Rbac.php                  permission checks, assignable-role rules
│   ├── Scope.php                 *** the data-isolation engine ***
│   ├── Guard.php                 middleware: requireAuth / requirePermission
│   ├── Audit.php                 audit trail (scope-filtered)
│   ├── Controller.php            base controller
│   ├── InstitutionController.php  DepartmentController.php
│   ├── UserController.php         RoleController.php
│   ├── PermissionController.php   ReportController.php
│   ├── ResourceRegistry.php       ResourceController.php   generic scope-aware CRUD
│   └── helpers.php               e(), url(), view(), layout helpers …
├── admin/
│   ├── institutions.php  departments.php  users.php
│   └── roles.php  permissions.php  audit.php
├── modules/
│   ├── _module.php               shared CRUD engine for every module page
│   ├── members.php  attendance.php  finance.php  library.php
│   ├── property.php  book_sales.php  reports.php
│   └── acadamics/
│       ├── academic.php  academic_setup.php  academic_reports.php
│       ├── academic_years.php  levels.php  classrooms.php  courses.php
│       ├── academic_departments.php  teacher_assignments.php
│       ├── enrollment.php  students.php  assessments.php  grades.php
│       ├── certificates.php  print_certificate.php  report_cards.php
├── api/index.php                 JSON REST API (same controllers)
├── views/_header.php  _footer.php
├── assets/css/style.css  assets/js/app.js
├── database/
│   ├── integration_schema.sql    schema + views + seed + demo data
│   └── install.php               CLI installer
└── tests/
    ├── run_tests.php             91 assertions against a real database
    └── http_smoke.sh             33 end-to-end HTTP assertions
```

---

## 4. Database schema

`database/integration_schema.sql` creates **28 tables**, 3 reporting views and the
full seed (67 permissions, 9 roles, 257 role→permission links).

```
institutions ──1:N──► departments ──1:N──► departments   (self-referencing tree)
      │                    │                 (parent_id + depth + path)
      │                    │
      │                    ├──1:N──► members, attendance_records, finance_entries,
      │                    │        library_items, property_assets, book_sales,
      │                    │        academic_years, levels, classrooms, courses,
      │                    │        academic_departments, students, assessments,
      │                    │        grades, certificates, teacher_assignments
      │                    │
      └──1:N──► users ─────┴──1:N──► user_roles ──N:1──► roles ──N:M──► permissions
                                                                 (role_permissions)

login_attempts      audit_logs      (both scoped by institution_id / department_id)
```

### Core tables

| Table | Purpose |
|---|---|
| `institutions` | Level 1 entity. Self-referencing `parent_id` allows conference → church → school. |
| `departments` | Level 2/3 tree. Carries `parent_id`, `depth` and a **materialized `path`** (`/1/5/9/`) so a whole sub-tree is one indexed `LIKE` — no recursive CTE needed. |
| `users` | Identity. `is_super_admin` marks the Main Admin; `institution_id` / `department_id` are the user's home scope. |
| `roles` | Templates. `scope_level ∈ (global, institution, department)` caps how widely the role may be granted. `institution_id IS NULL` = shared template. |
| `permissions` | Atomic capability codes (`department.create`, `finance.approve`, …). |
| `role_permissions` | Role → permission matrix. |
| `user_roles` | **The heart of the model**: binds a user to a role *at a scope* (`scope_type` + `scope_id`). |
| `audit_logs` | Every write, with the scope it happened in. |

### Two details worth pointing out

**a) Generated keys close the NULL-uniqueness loophole.** In MySQL a `UNIQUE`
index treats NULLs as distinct, so `UNIQUE(code, institution_id)` would happily
accept two global roles with the same code. Both tables therefore add a stored
generated column:

```sql
`institution_key` INT UNSIGNED AS (IFNULL(`institution_id`,0)) STORED,
UNIQUE KEY `uq_roles_code_inst` (`code`,`institution_key`)
```

**b) Reporting views** — `v_institution_reports`, `v_department_reports`,
`v_user_access`. They are deliberately scope-agnostic; the application still
appends its own isolation predicate, but the `COUNT()`/join logic is written once.

---

## 5. Data isolation logic (deliverable #2)

RBAC answers *what may a user do*. **`Scope` answers *on which rows*.**

### 5.1 Resolving the scope

`Scope::forUser($user)` looks at the user's `user_roles` rows and picks the
broadest granted scope:

```php
if ($user['is_super_admin'])                     return GLOBAL;        // Main Admin
foreach ($roles as $r) if ($r->scope_type === 'global')      return GLOBAL;
foreach ($roles as $r) if ($r->scope_type === 'institution') return INSTITUTION($r->scope_id);
foreach ($roles as $r) if ($r->scope_type === 'department')  return DEPARTMENT($r->scope_id);
                                                                        // + sub-tree ids
```

For a department scope the sub-tree is resolved from the materialized path:

```sql
SELECT id FROM departments WHERE institution_id = ? AND path LIKE '/1/%';
--  → [1, 3]   (Sabbath School + Primary Division, never the sibling Youth Ministry)
```

### 5.2 One predicate, used by every query

`Scope::where($alias, $map)` returns the SQL fragment and its bindings:

| Scope | Generated predicate | Bindings |
|---|---|---|
| global | `1 = 1` | — |
| institution | `t.institution_id = ?` | `[1]` |
| department | `t.institution_id = ? AND t.department_id IN (?,?)` | `[1, 1, 3]` |
| none / anonymous | `1 = 0` | — |

Controllers never hand-write an isolation `WHERE`; they call
`Scope::applyTo()` and the correct predicate is appended:

```php
$params = [];
$sql = 'SELECT r.* FROM v_department_reports r';
$sql = $this->scope->applyTo($sql, 'r', [
    'institution' => 'institution_id',
    'department'  => 'department_id',
], $params);
```

### 5.3 Institution Admin → aggregated report

An Institution Admin has `institution` scope, so the predicate keeps **every**
department of the institution and the report aggregates all of them:

```sql
SELECT r.name, r.member_count, r.income_total
  FROM v_department_reports r
 WHERE (r.institution_id = 1)          -- ← generated by Scope
 ORDER BY r.institution_id, r.path;
-- bole_admin  →  3 rows  (Sabbath School, Youth Ministry, Primary Division)
```

### 5.4 Department Admin → own sub-tree only

The Department Admin runs **the identical SQL**. Only the predicate differs:

```sql
 WHERE (r.institution_id = 1 AND r.department_id IN (1, 3))   -- ← generated by Scope
-- ss_admin    →  2 rows  (Sabbath School, Primary Division)
-- youth_admin →  1 row   (Youth Ministry)
```

That is the whole trick: *same query, different predicate*. There is no code path
in which a Department Admin can widen it, because the predicate is derived from
`user_roles` on the server side, never from request input.

### 5.5 Writes are anchored, not trusted

On create, the institution/department is taken **from the scope**, not from the
posted form:

```php
// DepartmentController::resolveTargetInstitution()
if ($this->scope->isGlobal()) { /* the Main Admin may choose */ }
else { $institutionId = $this->scope->institutionId(); }   // everyone else is pinned
```

A Department Admin who posts `institution_id=2` gets a department inside **their
own** institution. A Department Admin who posts `department_id=2` (a sibling)
gets `403`. Both cases are covered by the test suite.

### 5.6 Where the rules are enforced

| Layer | File | Responsibility |
|---|---|---|
| Middleware | `src/Guard.php` | authentication, permission codes, POST+CSRF |
| Policy | `src/Scope.php` | which rows; `assertDepartment()` / `assertInstitution()` before writes |
| Controller | `src/*Controller.php` | builds every query through `Scope::applyTo()` |
| Row check | `ResourceController::assertRowVisible()` | re-checks a single fetched row |
| API | `api/index.php` | reuses the same controllers — no bypass |

---

## 6. RBAC and the escalation rules

Permissions are attached to roles; roles are attached to users **at a scope**.

```
main_admin          global        everything
institution_admin   institution   everything except institution.create/delete
  department_admin      department    department.*, user.*, role.view/assign_permission, all modules
  department_supervisor department    read-mostly + attendance.create
  teacher               department    academics.view/assessment/grade/reportcard, attendance.view/create
  secretary             department    members.*, attendance.*
  treasurer             department    finance.*, book_sales.*
  librarian             department    library.*
  member                department    read-only
```

### Escalation guards (server-side, `UserController::assertCanGrant()`)

| Rule | Effect |
|---|---|
| `main_admin` is never grantable from the UI | nobody can mint a Main Admin |
| `institution_admin` requires global scope | **only the Main Admin appoints Institution Admins** |
| a caller may not grant a scope wider than its own | a Department Admin cannot grant institution roles |
| the target scope object must be inside the caller's scope | `assertDepartment()` / `assertInstitution()` |
| role permissions: a caller may only grant permissions it holds itself | an Institution Admin cannot escalate by editing a role |

`Rbac::assignableRoles()` also *filters the dropdowns*, so the UI never even
offers an illegal option — but the server re-checks everything regardless.

---

## 7. CRUD API + controllers (deliverable #3)

### 7.1 Controllers

| Controller | Key methods |
|---|---|
| `InstitutionController` | `index`, `find`, `store`, `update`, `destroy`, `assignAdmin` |
| `DepartmentController` | `index`, `tree`, `find`, `store`, `update` (incl. re-parenting), `destroy`, `assignAdmin` |
| `UserController` | `index`, `find`, `store`, `update`, `destroy`, `assignRole`, `revokeRole`, `assignments`, `availableRoles` |
| `RoleController` | `index`, `find`, `store`, `update`, `destroy`, `catalogue`, `permissionIds`, `setPermissions` |
| `PermissionController` | `index`, `catalogue`, `modules` |
| `ReportController` | `overview`, `departmentAggregate`, `institutionAggregate`, `moduleTotals` |
| `ResourceController` | generic scope-aware CRUD for all 16 module entities |

### 7.2 REST endpoints

Session-authenticated; mutations also need a CSRF token
(`X-CSRF-Token` header or `_token` field).

| Method & URL | Action |
|---|---|
| `GET /api/index.php?resource=me` | identity + roles + permissions + scope |
| `GET /api/index.php?resource=meta` | resource + permission catalogue |
| `GET /api/index.php?resource=institutions` | list (scope-filtered) |
| `GET /api/index.php?resource=institutions&id=1` | read one |
| `POST /api/index.php?resource=institutions` | create |
| `PUT /api/index.php?resource=institutions&id=1` | update |
| `DELETE /api/index.php?resource=institutions&id=1` | delete |
| `POST /api/index.php?resource=institutions&id=1&action=assign_admin` | appoint Institution Admin |
| `GET /api/index.php?resource=departments&action=tree` | nested department tree |
| `POST /api/index.php?resource=departments&id=5&action=assign_admin` | appoint Department Admin |
| `GET /api/index.php?resource=users&id=6&action=roles` | assignments + assignable roles |
| `POST /api/index.php?resource=users&action=assign_role` | grant a scoped role |
| `POST /api/index.php?resource=users&action=revoke_role` | revoke an assignment |
| `POST /api/index.php?resource=roles&id=3&action=permissions` | replace a role's permission set |
| `GET /api/index.php?resource=audit` | scope-filtered audit trail |
| `GET /api/index.php?resource=reports&action=departments` | aggregated report |
| `GET /api/index.php?resource=reports&action=institutions` | per-institution roll-up |
| `GET /api/index.php?resource=reports&action=totals&table=members` | module totals by department |
| `GET/POST/PUT/DELETE /api/index.php?resource=members` | any registry resource (`members`, `finance`, `students`, …) |

`PUT`/`DELETE` can be tunnelled from an HTML form with `_method=PUT`.

### 7.3 Example session

```bash
# sign in and keep the cookie
curl -c jar -s http://localhost/senbet_maderaja/login.php \
  | grep -oE 'value="[a-f0-9]{64}"' | head -1
curl -b jar -c jar -d '_token=<TOKEN>' -d 'username=ss_admin' -d 'password=Senbet@123' \
  http://localhost/senbet_maderaja/login.php

# who am I, and what exactly can I see?
curl -b jar 'http://localhost/senbet_maderaja/api/index.php?resource=me'
# → scope: department #1, department_ids: [1,3]

# the aggregated report, already narrowed to that scope
curl -b jar 'http://localhost/senbet_maderaja/api/index.php?resource=reports&action=departments'
# → 2 rows, never the sibling department

# a row outside the scope is refused
curl -b jar 'http://localhost/senbet_maderaja/api/index.php?resource=members&id=4'
# → 403 {"ok":false,"error":"That department is outside your scope."}
```

---

## 8. Running the tests

The suites exercise the **real** code against a **real** database — no mocks.

```bash
# 91 assertions: scope resolution, isolation, escalation, tree maintenance, writes
DB_HOST=127.0.0.1 DB_NAME=senbet_maderaja DB_USER=root DB_PASS= \
  php tests/run_tests.php

# 33 end-to-end HTTP assertions against a running server
php -S 127.0.0.1:18080 -t . &
BASE_URL=http://127.0.0.1:18080 bash tests/http_smoke.sh
```

What the suites prove, among others:

* `main_admin` sees 2 institutions / 4 departments, `bole_admin` 1 / 3, `ss_admin` 2 (sub-tree).
* The generated predicate is exactly `1 = 1` / `institution_id = ?` / `... IN (?,?)`.
* `ss_admin` gets `403` reading member #4 (sibling department) and member #5 (other institution).
* `youth_admin` creating a member writes it into department 2, and posting
  `department_id=1` is refused with `403`.
* A Department Admin cannot appoint an Institution Admin, and cannot grant a role
  in a sibling department or another institution.
* Creating a sub-department sets `depth`/`path` correctly; re-parenting rewrites the
  whole branch; a circular move is refused with `422`; deleting a parent with
  children is refused with `409`.

---

## 9. Extending the system

### Add a business module (5 minutes)

1. Add the table to `database/integration_schema.sql` with
   `institution_id` + `department_id` columns and the matching indexes/FKs.
2. Declare it in `config/resources.php`:

```php
'visitors' => [
    'table'      => 'visitors',
    'singular'   => 'Visitor',
    'plural'     => 'Visitors',
    'permission' => 'visitors',
    'group'      => 'modules',
    'title'      => 'full_name',
    'search'     => ['full_name', 'phone'],
    'order'      => 'full_name',
    'order_dir'  => 'ASC',
    'fields'     => [
        'full_name' => ['label' => 'Full name', 'type' => 'text', 'required' => true],
        'phone'     => ['label' => 'Phone',     'type' => 'tel'],
        'status'    => ['label' => 'Status',    'type' => 'select', 'options' => ['active','inactive']],
    ],
],
```

3. Add the four permissions (`visitors.view/create/update/delete`) to the seed.
4. Create `modules/visitors.php`:

```php
<?php
declare(strict_types=1);
$RESOURCE_KEY = 'visitors';
require __DIR__ . '/_module.php';
```

That is it — you get a scope-isolated list, search, pagination, create/edit form,
delete, navigation entry and audit logging for free. Field types available:
`text`, `textarea`, `number`, `decimal`, `date`, `select`, `ref`, `checkbox`,
`email`, `tel`.

### Add a role

Insert it into `roles` (with a `scope_level`), then map permissions via
`role_permissions` — or do it through **Admin → Roles → Permissions** in the UI.
Institution-specific custom roles set `roles.institution_id`.

---

## 10. Security notes

* Passwords: `password_hash()` / bcrypt; login is throttled by `login_attempts`
  (5 failures / 5 minutes by default — `config/config.php`).
* All queries are parameterised; table/column names coming from configuration are
  validated against `^[A-Za-z0-9_]+$` before interpolation.
* CSRF token on every state-changing form and API mutation; the session cookie is
  `HttpOnly` + `SameSite=Lax` (+ `Secure` automatically on HTTPS).
* Session id is regenerated on login.
* XSS: all output goes through `e()` (`htmlspecialchars`, UTF-8, Amharic-safe).
* The audit trail records every write together with its scope, and the audit screen
  is itself scope-filtered.

### Known limitations

* Sign-out is a `GET` link for simplicity; switch to a POST form if your policy
  requires it.
* `login_attempts` is keyed on username only (not IP); add an IP dimension if you
  need per-source throttling.
* Single-institution membership per user (`users.institution_id`); multi-institution
  staff would need an extra join table.
* Money values are `DECIMAL` and printed as-is (no currency formatting/localisation).
