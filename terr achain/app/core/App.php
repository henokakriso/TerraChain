<?php
declare(strict_types=1);

define('TERRACHAIN_ROOT', dirname(__DIR__, 2));
define('TERRACHAIN_APP', TERRACHAIN_ROOT . '/app');
define('TERRACHAIN_STORAGE', TERRACHAIN_ROOT . '/storage');
define('TERRACHAIN_LANG', TERRACHAIN_ROOT . '/lang');

final class App
{
    private static ?array $config = null;

    public static function config(?string $key = null, mixed $default = null): mixed
    {
        if (self::$config === null) {
            self::$config = require TERRACHAIN_ROOT . '/config/config.php';
        }
        if ($key === null) {
            return self::$config;
        }
        $parts = explode('.', $key);
        $value = self::$config;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function db(): Database
    {
        return Database::instance();
    }

    public static function baseUrl(): string
    {
        return rtrim((string)self::config('app.base_url'), '/');
    }

    public static function root(): string
    {
        return TERRACHAIN_ROOT;
    }

    public static function storagePath(string $rel = ''): string
    {
        return $rel === '' ? TERRACHAIN_STORAGE : TERRACHAIN_STORAGE . '/' . ltrim($rel, '/');
    }

    public static function now(): string
    {
        return (new DateTime('now', new DateTimeZone((string)self::config('app.timezone', 'UTC'))))->format('Y-m-d H:i:s');
    }
}
