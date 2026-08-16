<?php
declare(strict_types=1);

final class Bootstrap
{
    public static function init(): void
    {
        date_default_timezone_set((string)App::config('app.timezone', 'Africa/Addis_Ababa'));

        spl_autoload_register(static function (string $class): void {
            $paths = [
                TERRACHAIN_APP . '/core',
                TERRACHAIN_APP . '/controllers',
                TERRACHAIN_APP . '/services',
                TERRACHAIN_APP . '/models',
                TERRACHAIN_APP . '/repositories',
                TERRACHAIN_APP . '/middleware',
                TERRACHAIN_APP . '/validators',
                TERRACHAIN_APP . '/security',
            ];
            foreach ($paths as $path) {
                $file = $path . '/' . $class . '.php';
                if (is_file($file)) {
                    require_once $file;
                    return;
                }
            }
        });

        if (App::config('app.debug')) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(0);
            ini_set('display_errors', '0');
            set_exception_handler(static fn(Throwable $e) => Response::error('Internal server error.', 500));
        }
    }

    public static function handle(Throwable $e): never
    {
        if ($e instanceof ApiException) {
            Response::error($e->getMessage(), $e->getCode(), $e->errors);
        }
        error_log('[TerraChain] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        Response::error('Internal server error.', 500);
    }
}
