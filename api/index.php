<?php
/**
 * JSON REST API.
 *
 * Session-authenticated and CSRF-protected, and it reuses exactly the same
 * controllers as the HTML pages - so the scope isolation rules cannot be
 * bypassed by talking to the API instead of the UI.
 *
 * Routing (no rewrite rules needed, works on plain XAMPP):
 *   GET    api/index.php?resource=departments            list (scope-filtered)
 *   GET    api/index.php?resource=departments&id=1       read one
 *   POST   api/index.php?resource=departments            create
 *   PUT    api/index.php?resource=departments&id=1       update
 *   DELETE api/index.php?resource=departments&id=1       delete
 *   POST   api/index.php?resource=departments&id=1&action=assign_admin
 *
 * Send the CSRF token as the `X-CSRF-Token` header (or `_token` in the body).
 * PUT / DELETE can be tunnelled with `_method` when using an HTML form.
 */
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/**
 * @return never
 */
function apiOk(mixed $data, int $status = 200, array $meta = []): void
{
    json_response(array_filter([
        'ok'   => true,
        'data' => $data,
        'meta' => $meta ?: null,
    ], static fn ($v) => $v !== null), $status);
}

$user   = Guard::requireAuth();
$scope  = Scope::forUser($user);
$method = request_method();

// Method override for HTML forms.
if ($method === 'POST' && ($override = strtoupper((string) input('_method', '')))) {
    if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
        $method = $override;
    }
}

$resource = (string) input('resource', '');
$action   = (string) input('action', '');
$id       = (int) input('id', 0);
$body     = $_POST;

// JSON request bodies are merged into the payload.
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (str_contains($contentType, 'application/json')) {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $body = array_merge($body, $decoded);
    }
}

// Mutations require a valid CSRF token.
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $body['_token'] ?? null;
    Csrf::check(is_string($token) ? $token : null);
}

$resources = new ResourceController($user);

switch ($resource) {
    case 'me':
        apiOk([
            'user' => [
                'id'             => (int) $user['id'],
                'username'       => $user['username'],
                'full_name'      => $user['full_name'],
                'is_super_admin' => (bool) $user['is_super_admin'],
            ],
            'roles'       => $user['roles'],
            'permissions' => $user['permissions'],
            'scope'       => $scope->toArray() + ['display_name' => $scope->displayName()],
        ]);

    case 'meta':
        $definitions = [];
        foreach (ResourceRegistry::all() as $key => $def) {
            $definitions[$key] = [
                'table'      => $def['table'],
                'plural'     => $def['plural'] ?? $key,
                'group'      => $def['group'] ?? 'modules',
                'permissions' => [
                    'view'   => ResourceRegistry::permissionFor(ResourceRegistry::get($key), 'view'),
                    'create' => ResourceRegistry::permissionFor(ResourceRegistry::get($key), 'create'),
                    'update' => ResourceRegistry::permissionFor(ResourceRegistry::get($key), 'update'),
                    'delete' => ResourceRegistry::permissionFor(ResourceRegistry::get($key), 'delete'),
                ],
                'page'       => ResourceRegistry::pageUrl($key),
            ];
        }
        apiOk(['resources' => $definitions, 'permissions' => $user['permissions']]);

    case 'institutions':
        $controller = new InstitutionController($user);
        if ($action === 'assign_admin' && $method === 'POST') {
            apiOk($controller->assignAdmin($id, (int) ($body['user_id'] ?? 0)), 201);
        }
        match ($method) {
            'GET'    => apiOk($id ? $controller->find($id) : $controller->index($body)),
            'POST'   => apiOk($controller->store($body), 201),
            'PUT'    => apiOk($controller->update($id, $body)),
            'DELETE' => (static function () use ($controller, $id) { $controller->destroy($id); apiOk(['deleted' => true]); })(),
            default  => throw new HttpError(405, 'Method not allowed.'),
        };

    case 'departments':
        $controller = new DepartmentController($user);
        if ($action === 'assign_admin' && $method === 'POST') {
            apiOk($controller->assignAdmin($id, (int) ($body['user_id'] ?? 0), $body['role_code'] ?? null), 201);
        }
        if ($action === 'tree' && $method === 'GET') {
            apiOk($controller->tree($body));
        }
        match ($method) {
            'GET'    => apiOk($id ? $controller->find($id) : $controller->index($body)),
            'POST'   => apiOk($controller->store($body), 201),
            'PUT'    => apiOk($controller->update($id, $body)),
            'DELETE' => (static function () use ($controller, $id) { $controller->destroy($id, true); apiOk(['deleted' => true]); })(),
            default  => throw new HttpError(405, 'Method not allowed.'),
        };

    case 'users':
        $controller = new UserController($user);
        if ($method === 'GET' && $action === 'roles') {
            apiOk([
                'assignments' => $controller->assignments($id),
                'available'   => $controller->availableRoles($id),
            ]);
        }
        if ($action === 'assign_role' && $method === 'POST') {
            apiOk($controller->assignRole(
                (int) ($body['user_id'] ?? $id),
                (int) ($body['role_id'] ?? 0),
                $body['scope_type'] ?? null,
                isset($body['scope_id']) ? (int) $body['scope_id'] : null,
                $body['expires_at'] ?? null
            ), 201);
        }
        if ($action === 'revoke_role' && $method === 'POST') {
            $controller->revokeRole((int) ($body['assignment_id'] ?? 0));
            apiOk(['revoked' => true]);
        }
        match ($method) {
            'GET'    => apiOk($id ? $controller->find($id) : $controller->index($body)),
            'POST'   => apiOk($controller->store($body), 201),
            'PUT'    => apiOk($controller->update($id, $body)),
            'DELETE' => (static function () use ($controller, $id) { $controller->destroy($id); apiOk(['deleted' => true]); })(),
            default  => throw new HttpError(405, 'Method not allowed.'),
        };

    case 'roles':
        $controller = new RoleController($user);
        if ($action === 'permissions' && $method === 'POST') {
            apiOk($controller->setPermissions($id, (array) ($body['permissions'] ?? [])), 201);
        }
        if ($action === 'catalogue' && $method === 'GET') {
            apiOk($controller->catalogue());
        }
        match ($method) {
            'GET'    => apiOk($id ? $controller->find($id) : $controller->index($body)),
            'POST'   => apiOk($controller->store($body), 201),
            'PUT'    => apiOk($controller->update($id, $body)),
            'DELETE' => (static function () use ($controller, $id) { $controller->destroy($id); apiOk(['deleted' => true]); })(),
            default  => throw new HttpError(405, 'Method not allowed.'),
        };

    case 'permissions':
        $controller = new PermissionController($user);
        apiOk($action === 'catalogue' ? $controller->catalogue() : $controller->index($body));

    case 'audit':
        Guard::requirePermission('audit.view');
        apiOk(Audit::listing($scope, array_filter([
            'action'  => (string) ($body['action'] ?? ''),
            'user_id' => (int) ($body['user_id'] ?? 0),
            'from'    => (string) ($body['from'] ?? ''),
            'to'      => (string) ($body['to'] ?? ''),
        ]), (int) ($body['limit'] ?? 200)));

    case 'reports':
        $controller = new ReportController($user);
        apiOk(match ($action) {
            'overview'     => $controller->overview(),
            'institutions' => $controller->institutionAggregate(),
            'totals'       => $controller->moduleTotals((string) ($body['table'] ?? 'members')),
            default        => $controller->departmentAggregate($body),
        });

    case 'resources':
        apiOk(ResourceRegistry::grouped());


    default:
        if (ResourceRegistry::has($resource)) {
            match ($method) {
                'GET'    => apiOk($resources->find($resource, $id)),
                'POST'   => apiOk($resources->store($resource, $body), 201),
                'PUT'    => apiOk($resources->update($resource, $id, $body)),
                'DELETE' => (static function () use ($resources, $resource, $id) { $resources->destroy($resource, $id); apiOk(['deleted' => true]); })(),
                default  => throw new HttpError(405, 'Method not allowed.'),
            };
        }
        throw new HttpError(404, 'Unknown resource: ' . $resource);
}
