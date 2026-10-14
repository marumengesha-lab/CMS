<?php
/**
 * CSRF protection for state-changing form posts.
 */
declare(strict_types=1);

final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (!Session::has(self::KEY)) {
            Session::set(self::KEY, bin2hex(random_bytes(32)));
        }
        return (string) Session::get(self::KEY);
    }

    /** Hidden input ready to drop into a <form>. */
    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function check(?string $token): void
    {
        $expected = (string) Session::get(self::KEY, '');
        if ($expected === '' || $token === null || !hash_equals($expected, $token)) {
            throw new HttpError(419, 'Your session expired. Please reload the page and try again.');
        }
    }

    /** Validate the token of the current request. */
    public static function verifyRequest(): void
    {
        $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        self::check(is_string($token) ? $token : null);
    }
}
