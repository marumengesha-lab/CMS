<?php
/**
 * Global helper functions used by pages, the API and CLI scripts.
 */
declare(strict_types=1);

if (!function_exists('config')) {
    /** Dot-notation config access: config('db.host', 'localhost'). */
    function config(string $key, mixed $default = null): mixed
    {
        $value = $GLOBALS['app_config'] ?? [];
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}

if (!function_exists('e')) {
    /** HTML-escape a value (Amharic-safe). */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /**
     * Build an absolute-path URL. Pages pass the path relative to the web
     * root, e.g. url('admin/roles.php').
     */
    function url(string $path = ''): string
    {
        $base = rtrim((string) config('app.base_url', ''), '/');
        $path = ltrim($path, '/');
        return $base . '/' . $path;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return url('assets/' . ltrim($path, '/'));
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path, int $status = 302): never
    {
        if (!headers_sent()) {
            header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)), true, $status);
        }
        exit;
    }
}

if (!function_exists('current_path')) {
    /** Path of the current script relative to the web root, e.g. admin/roles.php. */
    function current_path(): string
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $base   = rtrim((string) config('app.base_url', ''), '/');
        if ($base !== '' && str_starts_with($script, $base)) {
            $script = substr($script, strlen($base));
        }
        return ltrim($script, '/');
    }
}

if (!function_exists('request_method')) {
    function request_method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }
}

if (!function_exists('is_post')) {
    function is_post(): bool
    {
        return request_method() === 'POST';
    }
}

if (!function_exists('input')) {
    /** Read a request value from POST, then GET. */
    function input(string $key, mixed $default = null): mixed
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

if (!function_exists('user_agent')) {
    function user_agent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}

if (!function_exists('view')) {
    /**
     * Render a template file from /views with the given data extracted
     * into local variables. Returns the buffered HTML.
     */
    function view(string $template, array $data = []): string
    {
        $file = BASE_PATH . '/views/' . ltrim($template, '/');
        if (!str_ends_with($file, '.php')) {
            $file .= '.php';
        }
        if (!is_file($file)) {
            throw new HttpError(500, "View not found: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}

if (!function_exists('render_page')) {
    /**
     * Render a page inside the shared layout.
     *
     * @param array $data Content variables; special keys: title, breadcrumbs, page_actions, active_nav.
     */
    function render_page(string $template, array $data = []): void
    {
        $content = view($template, $data);
        echo view('layout', [
            'title'        => $data['title'] ?? (string) config('app.name'),
            'content'      => $content,
            'breadcrumbs'  => $data['breadcrumbs'] ?? [],
            'page_actions' => $data['page_actions'] ?? '',
            'active_nav'   => $data['active_nav'] ?? '',
        ]);
    }
}

if (!function_exists('json_response')) {
    function json_response(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('slug_code')) {
    /** Turn a human name into a safe CODE (uppercase alphanumeric + _). */
    function slug_code(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9]+/', '_', trim($value)) ?? '';
        return strtoupper(trim($value, '_'));
    }
}

if (!function_exists('old_input')) {
    function old_input(string $key, mixed $default = ''): mixed
    {
        $old = Session::get('_old', []);
        return $old[$key] ?? $default;
    }
}

if (!function_exists('remember_old')) {
    function remember_old(array $data): void
    {
        Session::set('_old', $data);
    }
}

if (!function_exists('clear_old')) {
    function clear_old(): void
    {
        Session::forget('_old');
    }
}

if (!function_exists('validate_required')) {
    /**
     * Tiny validation helper. Throws HttpError(422) carrying a field map.
     *
     * @param array $rules ['field' => 'Label', ...]
     */
    function validate_required(array $data, array $rules): void
    {
        $errors = [];
        foreach ($rules as $field => $label) {
            if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
                $errors[$field] = "{$label} is required.";
            }
        }
        if ($errors) {
            throw new HttpError(422, 'Please correct the highlighted fields.', $errors);
        }
    }
}

if (!function_exists('layout_header')) {
    /**
     * Open the page shell (doctype, top bar, sidebar, breadcrumbs, flashes).
     *
     * @param array $opts title, breadcrumbs, actions, active
     */
    function layout_header(array $opts = []): void
    {
        echo view('_header', $opts);
    }
}

if (!function_exists('layout_footer')) {
    function layout_footer(): void
    {
        echo view('_footer', []);
    }
}

if (!function_exists('flash_html')) {
    /** Render queued flash messages. */
    function flash_html(): string
    {
        $html = '';
        foreach (Session::takeFlashes() as $flash) {
            $type = preg_replace('/[^a-z]/', '', strtolower((string) $flash['type'])) ?: 'info';
            $html .= '<div class="flash flash--' . e($type) . '">' . e($flash['message']) . '</div>';
        }
        return $html;
    }
}

if (!function_exists('badge')) {
    function badge(string $text, string $tone = 'neutral'): string
    {
        return '<span class="badge badge--' . e($tone) . '">' . e($text) . '</span>';
    }
}

if (!function_exists('status_tone')) {
    function status_tone(?string $status): string
    {
        return match ($status) {
            'active', 'issued', 'approved', 'open', 'in_use' => 'ok',
            'draft', 'pending', 'stored'                     => 'warn',
            'inactive', 'suspended', 'closed', 'revoked',
            'archived', 'disposed', 'ended', 'withdrawn'    => 'muted',
            default                                           => 'neutral',
        };
    }
}

if (!function_exists('money')) {
    function money(float|int|string|null $value): string
    {
        return number_format((float) $value, 2);
    }
}

