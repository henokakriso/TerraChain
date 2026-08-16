<?php
declare(strict_types=1);

/** Middleware registry: factories returning middleware callables. */
final class Middleware
{
    public static function auth(): callable
    {
        return static function (): void {
            Auth::requireLogin();
        };
    }

    public static function permission(string $code): callable
    {
        return static function () use ($code): void {
            $user = Auth::requireLogin();
            if (!Auth::hasPermission($user, $code)) {
                Response::forbidden("Missing permission: $code");
            }
        };
    }

    /** Restricts a {unit_id} path parameter to the user's administrative scope. */
    public static function scopeUnit(): callable
    {
        return static function (string $path, array $params): void {
            $user = Auth::requireLogin();
            if (empty($params['unit_id']) || Auth::isSystemAdmin($user)) {
                return;
            }
            if (!Auth::inScope($user['admin_unit_id'] ?? null, (int)$params['unit_id'])) {
                Response::forbidden('Unit outside your administrative scope.');
            }
        };
    }

    /**
     * Stateless institution authentication (section 32): API key + HMAC
     * headers. Used instead of session auth on /integrations/* routes.
     */
    public static function machine(): callable
    {
        return static function (): void {
            IntegrationService::authenticate();
        };
    }
}
