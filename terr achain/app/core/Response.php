<?php
declare(strict_types=1);

class ApiException extends Exception
{
    public function __construct(string $message, int $statusCode = 400, public readonly array $errors = [])
    {
        parent::__construct($message, $statusCode);
    }
}

final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): never
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        self::json(['success' => false, 'message' => $message, 'errors' => $errors], $status);
    }

    public static function unauthorized(string $message = 'Authentication required'): never
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Insufficient permissions'): never
    {
        self::error($message, 403);
    }

    public static function notFound(string $message = 'Resource not found'): never
    {
        self::error($message, 404);
    }
}