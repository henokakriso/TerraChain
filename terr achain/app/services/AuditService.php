<?php
declare(strict_types=1);

final class AuditService
{
    /**
     * Every important action produces an audit record (section 26).
     * All insert/update operations on protected tables should call this.
     */
    public static function log(
        ?int $userId,
        string $action,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?array $previousState = null,
        ?array $newState = null,
        ?string $ip = null,
        bool $highRisk = false,
        ?string $reason = null,
        ?string $username = null
    ): void {
        if ($username === null && $userId !== null) {
            $u = App::db()->fetchOne('SELECT username FROM users WHERE id = ?', [$userId]);
            $username = $u['username'] ?? null;
        }
        App::db()->insert('audit_logs', [
            'user_id' => $userId,
            'username' => $username,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'previous_state' => $previousState !== null ? json_encode($previousState) : null,
            'new_state' => $newState !== null ? json_encode($newState) : null,
            'ip_address' => $ip ?? Request::ip(),
            'user_agent' => Request::userAgent(),
            'reason' => $reason,
            'is_high_risk' => $highRisk ? 1 : 0,
            'created_at' => App::now(),
        ]);
    }

    public static function logAction(array $user, string $action, ?string $type = null, ?string $id = null, ?array $prev = null, ?array $new = null, bool $highRisk = false, ?string $reason = null): void
    {
        self::log((int)$user['id'], $action, $type, $id, $prev, $new, null, $highRisk, $reason);
    }
}
