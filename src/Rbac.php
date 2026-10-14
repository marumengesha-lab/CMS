<?php
/**
 * Rbac - "what may this user DO".
 *
 * Permissions are atomic codes ('department.create'). They are attached to
 * roles, roles are attached to users through user_roles. RBAC itself is
 * scope-agnostic: the WHERE restriction is added by Scope.
 */
declare(strict_types=1);

final class Rbac
{
    /** Does the user hold the given permission code? */
    public static function can(?array $user, string $permission): bool
    {
        if (!$user) {
            return false;
        }
        if (!empty($user['is_super_admin'])) {
            return true; // Main Admin bypasses the matrix on purpose.
        }

        $granted = $user['permissions'] ?? [];

        if (in_array($permission, $granted, true)) {
            return true;
        }

        // Wildcard support: a role may carry 'academics.*'.
        $module = str_contains($permission, '.') ? explode('.', $permission)[0] : null;
        return $module !== null && in_array($module . '.*', $granted, true);
    }

    public static function canAny(?array $user, array $permissions): bool
    {
        foreach ($permissions as $p) {
            if (self::can($user, $p)) {
                return true;
            }
        }
        return false;
    }

    public static function canAll(?array $user, array $permissions): bool
    {
        foreach ($permissions as $p) {
            if (!self::can($user, $p)) {
                return false;
            }
        }
        return true;
    }

    /** Throws HttpError(403) when the permission is missing. */
    public static function assert(?array $user, string $permission): void
    {
        if (!self::can($user, $permission)) {
            throw new HttpError(403, "Missing permission: {$permission}");
        }
    }

    /** @return string[] */
    public static function permissions(?array $user): array
    {
        return $user['permissions'] ?? [];
    }

    /**
     * Roles the given scope is allowed to grant.
     *
     *  - Main Admin        may grant anything (global + institution + department)
     *  - Institution Admin may grant institution and department roles
     *  - Department Admin  may grant department roles only
     */
    public static function assignableRoles(Scope $scope, ?int $institutionId = null): array
    {
        $levels = match ($scope->type()) {
            Scope::GLOBAL      => ['global', 'institution', 'department'],
            Scope::INSTITUTION => ['institution', 'department'],
            Scope::DEPARTMENT  => ['department'],
            default            => [],
        };

        if (!$levels) {
            return [];
        }

        $ph     = implode(',', array_fill(0, count($levels), '?'));
        $params = $levels;

        // Global templates (institution_id IS NULL) plus the caller's own
        // institution-specific roles.
        $sql = "SELECT `id`,`code`,`name`,`name_am`,`scope_level`,`institution_id`,`is_system`
                  FROM `roles`
                 WHERE `status` = 'active'
                   AND `scope_level` IN ({$ph})
                   AND `code` <> 'main_admin'
                   AND (`institution_id` IS NULL";

        if ($institutionId !== null) {
            $sql .= ' OR `institution_id` = ?';
            $params[] = $institutionId;
        }
        $sql .= ')';

        // Only the Main Admin may hand out the institution_admin role.
        if ($scope->type() !== Scope::GLOBAL) {
            $sql .= " AND `code` <> 'institution_admin'";
        }

        $sql .= ' ORDER BY `scope_level`, `name`';

        return Database::all($sql, $params);
    }

    /** The scope_type a role must be granted at, derived from its scope_level. */
    public static function scopeTypeForRole(array $role): string
    {
        return match ($role['scope_level']) {
            'global'      => Scope::GLOBAL,
            'institution' => Scope::INSTITUTION,
            default       => Scope::DEPARTMENT,
        };
    }
}
