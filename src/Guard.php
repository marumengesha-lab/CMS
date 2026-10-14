<?php
/**
 * Guard - the middleware layer. Pages and API controllers call these
 * before doing anything else, so authorisation can never be forgotten in
 * an individual controller.
 *
 * Every method returns the authenticated user array (with roles and
 * permissions already loaded) or throws HttpError.
 */
declare(strict_types=1);

final class Guard
{
    /** Require a logged-in user. Redirects browsers to the login page. */
    public static function requireAuth(): array
    {
        $user = Auth::user();
        if ($user) {
            return $user;
        }
        if (!ErrorReporter::wantsJson()) {
            Session::flash('warning', 'Please sign in to continue.');
            redirect('login.php');
        }
        throw new HttpError(401, 'Authentication required.');
    }

    /** Require a specific permission code. */
    public static function requirePermission(string $permission): array
    {
        $user = self::requireAuth();
        if (!Rbac::can($user, $permission)) {
            throw new HttpError(403, "You do not have the '{$permission}' permission.");
        }
        return $user;
    }

    /** Require at least one of the given permissions. */
    public static function requireAnyPermission(array $permissions): array
    {
        $user = self::requireAuth();
        if (!Rbac::canAny($user, $permissions)) {
            throw new HttpError(403, 'You do not have permission to open this page.');
        }
        return $user;
    }

    /** Require the Main Admin flag. */
    public static function requireSuperAdmin(): array
    {
        $user = self::requireAuth();
        if (empty($user['is_super_admin'])) {
            throw new HttpError(403, 'This action is restricted to the Main System Admin.');
        }
        return $user;
    }

    /** Only accept POST (with a valid CSRF token) for mutations. */
    public static function requirePost(): void
    {
        if (!is_post()) {
            throw new HttpError(405, 'This endpoint requires POST.');
        }
        Csrf::verifyRequest();
    }

    /** Require the given field to be present and non-empty. */
    public static function requireFields(array $data, array $rules): void
    {
        validate_required($data, $rules);
    }
}
