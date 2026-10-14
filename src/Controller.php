<?php
/**
 * Base controller. Holds the authenticated user + effective scope and
 * provides request/response helpers shared by every controller.
 */
declare(strict_types=1);

abstract class Controller
{
    protected ?array $user;
    protected Scope $scope;

    public function __construct(?array $user = null)
    {
        $this->user  = $user ?? Auth::user();
        $this->scope = Scope::forUser($this->user);
    }

    public function user(): ?array
    {
        return $this->user;
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    protected function userId(): ?int
    {
        return $this->user['id'] ?? null;
    }

    protected function can(string $permission): bool
    {
        return Rbac::can($this->user, $permission);
    }

    protected function authorize(string $permission): void
    {
        Rbac::assert($this->user, $permission);
    }

    /**
     * Request body merged from the JSON payload (when present), POST and GET.
     */
    protected function payload(): array
    {
        $data = array_merge($_GET, $_POST);

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains(strtolower($contentType), 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = array_merge($data, $decoded);
                }
            }
        }
        return $data;
    }

    protected function intParam(array $data, string $key): ?int
    {
        if (!isset($data[$key]) || $data[$key] === '' || $data[$key] === null) {
            return null;
        }
        return (int) $data[$key];
    }

    protected function json(array $payload, int $status = 200): never
    {
        json_response($payload, $status);
    }

    /** Convenience: log an audit entry attributed to the current user. */
    protected function audit(string $action, array $opts = []): void
    {
        Audit::log($action, $opts);
    }

    /** Normalise a "code" value, falling back to the name. */
    protected function codeFrom(array $data, string $nameKey = 'name'): string
    {
        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $code = slug_code((string) ($data[$nameKey] ?? ''));
        }
        return $code;
    }
}
