<?php
declare(strict_types=1);

final class NotificationService
{
    public static function notify(int $userId, string $type, string $title, string $message, ?string $link = null): void
    {
        App::db()->insert('notifications', [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link,
        ]);
    }

    public static function unreadCount(int $userId): int
    {
        $row = App::db()->fetchOne(
            'SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
        return (int)$row['c'];
    }
}
