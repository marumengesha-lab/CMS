<?php
/**
 * Single place that decides how a Throwable becomes an HTTP response.
 * JSON for /api/* requests, HTML for regular pages.
 */
declare(strict_types=1);

class ErrorReporter
{
    public static function wantsJson(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_contains($uri, '/api/')) {
            return true;
        }
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
            return true;
        }
        $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return strtolower($xrw) === 'xmlhttprequest';
    }

    public static function handle(Throwable $e): void
    {
        $debug  = (bool) ($GLOBALS['app_config']['app']['debug'] ?? false);
        $status = $e instanceof HttpError ? $e->status : 500;

        // Log every server-side failure to the PHP error log.
        error_log(sprintf('[%s] %s: %s in %s:%d', $_SERVER['REQUEST_URI'] ?? '-', $status, $e->getMessage(), $e->getFile(), $e->getLine()));

        if (self::wantsJson()) {
            if (!headers_sent()) {
                http_response_code($status);
                header('Content-Type: application/json; charset=utf-8');
            }
            $payload = [
                'ok'      => false,
                'error'   => $e->getMessage(),
                'status'  => $status,
            ];
            if ($e instanceof HttpError && $e->context) {
                $payload['context'] = $e->context;
            }
            if ($debug) {
                $payload['trace'] = explode("\n", $e->getTraceAsString());
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        if (!headers_sent()) {
            http_response_code($status);
        }
        $title = HttpError::defaultMessage($status);
        $msg   = e($e->getMessage());

        // asset() uses the auto-detected base path, so error pages render
        // correctly even when the app lives in a sub-folder.
        $GLOBALS['__asset_css'] = asset('css/style.css');
        $link  = $status === 401 ? '<p><a class="btn" href="' . e(url('login.php')) . '">Go to login</a></p>' : '';
        $trace = $debug ? '<pre class="trace">' . e($e->getTraceAsString()) . '</pre>' : '';
        echo <<<HTML
<!doctype html>
<html lang="am"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$status} {$title}</title>
<link rel="stylesheet" href="{$GLOBALS['__asset_css']}">
</head><body class="error-page">
<div class="error-card">
  <div class="error-code">{$status}</div>
  <h1>{$title}</h1>
  <p>{$msg}</p>
  {$link}
  {$trace}
</div>
</body></html>
HTML;
    }
}
