<?php
/**
 * Any error that should be translated into an HTTP response.
 * Guards and controllers throw it; ErrorReporter turns it into
 * either a JSON body (API) or an HTML page.
 */
declare(strict_types=1);

class HttpError extends RuntimeException
{
    public function __construct(
        public int $status = 400,
        string $message = '',
        public array $context = []
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status));
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            401 => 'Authentication required',
            403 => 'You do not have permission to perform this action',
            404 => 'Resource not found',
            405 => 'Method not allowed',
            409 => 'Conflict',
            419 => 'Page expired',
            422 => 'Validation failed',
            429 => 'Too many requests',
            500 => 'Internal server error',
            default => 'Error',
        };
    }

    public function isAuth(): bool
    {
        return $this->status === 401;
    }
}
