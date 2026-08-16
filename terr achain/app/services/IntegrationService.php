<?php
declare(strict_types=1);

/**
 * Institution integration (sections 32, 35).
 * Stateless machine-to-machine authentication: each partner institution
 * holds an API key and signs every request with an HMAC-SHA256 over a
 * canonical string (METHOD\nPATH\nTIMESTAMP\nBODY). Rate limiting and a
 * full request log are kept in the database, and the same HMAC operation
 * is independently verifiable with the C utility (c/bin/hmac).
 */
final class IntegrationService
{
    private static ?array $currentKey = null;

    public const TIMESTAMP_WINDOW_MS = 300000;

    /** Canonical request string that is signed. */
    public static function canonicalString(string $method, string $path, string $timestamp, string $rawBody): string
    {
        return strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $rawBody;
    }

    public static function hmac(string $secret, string $data): string
    {
        return hash_hmac('sha256', $data, $secret);
    }

    /** Authenticates an incoming request from an institution. */
    public static function authenticate(): array
    {
        $key = Request::header('X-TC-Key');
        $signature = Request::header('X-TC-Signature');
        $timestamp = Request::header('X-TC-Timestamp');

        if ($key === null || $signature === null || $timestamp === null) {
            Response::error('Missing X-TC-Key, X-TC-Signature or X-TC-Timestamp header.', 401);
        }
        if (!preg_match('/^\d{10,13}$/', (string)$timestamp)) {
            Response::error('Malformed X-TC-Timestamp.', 401);
        }
        $nowMs = (int)floor(microtime(true) * 1000);
        if (abs($nowMs - (int)$timestamp) > self::TIMESTAMP_WINDOW_MS) {
            Response::error('Timestamp outside the allowed window.', 401);
        }

        $db = App::db();
        $keyRow = $db->fetchOne('SELECT * FROM integration_keys WHERE api_key = ?', [$key]);
        if ($keyRow === null || $keyRow['status'] !== 'active') {
            self::logAttempt($keyRow ?? null, Request::path(), Request::method(), null, 'denied', 401);
            Response::error('Unknown or revoked API key.', 401);
        }

        $path = Request::path();
        $rawBody = (string)file_get_contents('php://input');
        $canonical = self::canonicalString(Request::method(), $path, (string)$timestamp, $rawBody);
        $expected = self::hmac((string)$keyRow['api_key'], $canonical);
        if (!hash_equals($expected, strtolower((string)$signature))) {
            self::logAttempt($keyRow, $path, Request::method(), hash('sha256', $rawBody), 'denied', 401);
            Response::error('Invalid HMAC signature.', 401);
        }

        $used = (int)$db->fetchOne(
            'SELECT COUNT(*) AS c FROM integration_logs
             WHERE organization_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
               AND response_status IN ("success", "denied")',
            [(int)$keyRow['organization_id']]
        )['c'];
        if ($used >= (int)$keyRow['rate_limit_per_minute']) {
            self::logAttempt($keyRow, $path, Request::method(), null, 'error', 429);
            Response::error('Rate limit exceeded.', 429);
        }

        self::$currentKey = $keyRow;
        $db->update('integration_keys', ['last_used_at' => App::now()], 'id = ?', [(int)$keyRow['id']]);
        return $keyRow;
    }

    public static function current(): array
    {
        if (self::$currentKey === null) {
            throw new ApiException('Not authenticated as an institution.', 401);
        }
        return self::$currentKey;
    }

    /** Checks the institution key's allowed permission scopes. */
    public static function requirePermission(string $permission): void
    {
        $key = self::current();
        $perms = json_decode((string)$key['permissions'], true);
        if (!is_array($perms) || !in_array($permission, $perms, true)) {
            self::logAttempt($key, Request::path(), Request::method(), null, 'denied', 403);
            Response::forbidden("Institution permission missing: $permission");
        }
    }

    /** Records every machine request in the integration log (section 32). */
    public static function logAttempt(?array $key, string $endpoint, string $method, ?string $payloadHash, string $status, int $code): void
    {
        App::db()->insert('integration_logs', [
            'organization_id' => $key !== null ? (int)$key['organization_id'] : null,
            'direction' => 'in',
            'endpoint' => mb_substr($endpoint, 0, 250),
            'method' => strtoupper($method),
            'payload_hash' => $payloadHash,
            'response_status' => $status,
            'status_code' => $code,
        ]);
    }

    /** Independent C verification of an HMAC via c/bin/hmac (section 41). */
    public static function verifyHmacWithC(string $data, string $signature, string $key): array
    {
        $bin = (string)App::config('integrity.c_bin') . '/hmac';
        if (!is_file($bin) || !is_executable($bin)) {
            throw new ApiException('C hmac tool not built. Run: make -C c', 500);
        }
        $output = [];
        $code = 0;
        $cmd = escapeshellarg($bin)
            . ' v '
            . escapeshellarg($key)
            . ' ' . escapeshellarg($signature)
            . ' -- '
            . escapeshellarg($data);
        exec($cmd . ' 2>&1', $output, $code);
        return ['ok' => $code === 0, 'c_exit' => $code, 'output' => implode("\n", $output)];
    }
}