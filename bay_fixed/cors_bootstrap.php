<?php

function get_allowed_cors_origins(): array
{
    $configured = trim((string)(getenv('CORS_ALLOWED_ORIGINS') ?: ''));

    if ($configured === '') {
        return [
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'https://finale-web.vercel.app',
            'https://*.vercel.app',
        ];
    }

    $origins = array_map('trim', explode(',', $configured));
    $origins = array_filter($origins, static function ($origin) {
        return $origin !== '';
    });

    return array_values(array_unique($origins));
}

function cors_origin_is_allowed(string $origin, array $allowedOrigins): bool
{
    foreach ($allowedOrigins as $allowedOrigin) {
        if ($allowedOrigin === $origin) {
            return true;
        }

        if (strpos($allowedOrigin, '*') === false) {
            continue;
        }

        $pattern = '#^' . str_replace('\\*', '.*', preg_quote($allowedOrigin, '#')) . '$#i';
        if (preg_match($pattern, $origin) === 1) {
            return true;
        }
    }

    return false;
}

function apply_api_cors_headers(): void
{
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));

    if ($origin === '') {
        return;
    }

    $allowedOrigins = get_allowed_cors_origins();

    if (!cors_origin_is_allowed($origin, $allowedOrigins)) {
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
}

function handle_api_preflight_request(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'OPTIONS') {
        return;
    }

    apply_api_cors_headers();
    http_response_code(204);
    exit;
}
