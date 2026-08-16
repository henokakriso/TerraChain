<?php
declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        Auth::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . App::config('security.csrf_token_name') . '" value="' . htmlspecialchars(self::token()) . '">';
    }

    /** Validates the CSRF token from JSON body or POST form for state-changing requests. */
    public static function validate(): void
    {
        if (in_array(Request::method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $expected = self::token();
        $provided = Request::input((string)App::config('security.csrf_token_name'));
        if (empty($expected) || !is_string($provided) || !hash_equals($expected, $provided)) {
            Response::error('Invalid CSRF token.', 419);
        }
    }
}
