<?php
declare(strict_types=1);

/**
 * Internal chat between system users. Messages are hashed at rest and
 * appended to the integrity chain, so chat history is tamper-evident.
 */
final class ChatService
{
    /** Matches the SHA2(CONCAT(...)) formula used for seed messages. */
    public static function contentHash(int $conversationId, int $senderId, string $body): string
    {
        return hash('sha256', $conversationId . ':' . $senderId . ':' . $body);
    }

    public static function createConversation(array $user, ?string $title, array $participantIds): array
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $user, $title, $participantIds): array {
            $members = array_values(array_unique(array_map('intval', $participantIds)));
            $members[] = (int)$user['id'];
            $members = array_values(array_unique($members));
            $places = implode(',', array_fill(0, count($members), '?'));
            $valid = $db->fetchAll(
                "SELECT id FROM users WHERE id IN ($places) AND is_active = 1",
                $members
            );
            if (count($valid) < 2) {
                throw new ApiException('At least two active participants are required.', 422);
            }
            $validIds = array_map(fn(array $r): int => (int)$r['id'], $valid);

            $count = (int)$db->fetchOne('SELECT COUNT(*) AS c FROM chat_conversations')['c'];
            $conversationNo = 'CHT-' . date('Y') . '-' . str_pad((string)($count + 1), 6, '0', STR_PAD_LEFT);
            $id = $db->insert('chat_conversations', [
                'conversation_no' => $conversationNo,
                'title' => $title !== null && $title !== '' ? $title : null,
                'created_by' => (int)$user['id'],
            ]);
            foreach ($validIds as $memberId) {
                $db->insert('chat_participants', ['conversation_id' => $id, 'user_id' => $memberId]);
            }
            AuditService::logAction($user, 'CREATE_RECORD', 'chat_conversation', (string)$id, null, ['participants' => $validIds], false, 'Conversation created');
            return [
                'id' => $id,
                'conversation_no' => $conversationNo,
                'title' => $title,
                'participant_ids' => $validIds,
            ];
        });
    }

    public static function conversations(int $userId): array
    {
        $rows = App::db()->fetchAll(
            'SELECT c.id, c.conversation_no, c.title, c.created_at,
                    (SELECT COUNT(*) FROM chat_messages m
                      WHERE m.conversation_id = c.id
                        AND m.sender_id <> ?
                        AND (p.last_read_at IS NULL OR m.created_at > p.last_read_at)) AS unread,
                    (SELECT m.body FROM chat_messages m
                      WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                    (SELECT u.full_name FROM chat_messages m
                      JOIN users u ON u.id = m.sender_id
                      WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_sender,
                    (SELECT m.created_at FROM chat_messages m
                      WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_at,
                    (SELECT GROUP_CONCAT(u2.id) FROM chat_participants p2 JOIN users u2 ON u2.id = p2.user_id
                      WHERE p2.conversation_id = c.id) AS member_ids
             FROM chat_participants p
             JOIN chat_conversations c ON c.id = p.conversation_id
             WHERE p.user_id = ?
             ORDER BY COALESCE((SELECT MAX(m.id) FROM chat_messages m WHERE m.conversation_id = c.id), 0) DESC',
            [$userId, $userId]
        );
        foreach ($rows as &$row) {
            $row['unread'] = (int)$row['unread'];
        }
        return $rows;
    }

    public static function assertParticipant(int $userId, int $conversationId): void
    {
        $db = App::db();
        $conv = $db->fetchOne('SELECT * FROM chat_conversations WHERE id = ?', [$conversationId]);
        if ($conv === null) {
            throw new ApiException('Conversation not found.', 404);
        }
        $member = $db->fetchOne(
            'SELECT id FROM chat_participants WHERE conversation_id = ? AND user_id = ?',
            [$conversationId, $userId]
        );
        if ($member === null) {
            throw new ApiException('You are not a participant of this conversation.', 403);
        }
    }

    public static function messages(array $user, int $conversationId, int $after = 0): array
    {
        self::assertParticipant((int)$user['id'], $conversationId);
        $rows = App::db()->fetchAll(
            'SELECT m.id, m.conversation_id, m.sender_id, u.full_name AS sender_name, u.username, m.body, m.content_hash, m.created_at
             FROM chat_messages m JOIN users u ON u.id = m.sender_id
             WHERE m.conversation_id = ? AND m.id > ?
             ORDER BY m.id ASC',
            [$conversationId, $after]
        );
        AuditService::logAction($user, 'VIEW', 'chat_conversation', (string)$conversationId, null, null, false, 'Messages viewed');
        return $rows;
    }

    public static function sendMessage(array $user, int $conversationId, string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            throw new ApiException('Message cannot be empty.', 422);
        }
        if (mb_strlen($body) > 4000) {
            throw new ApiException('Message is too long (max 4000 characters).', 422);
        }
        self::assertParticipant((int)$user['id'], $conversationId);
        $db = App::db();
        $hash = self::contentHash($conversationId, (int)$user['id'], $body);
        $messageId = $db->insert('chat_messages', [
            'conversation_id' => $conversationId,
            'sender_id' => (int)$user['id'],
            'body' => $body,
            'content_hash' => $hash,
        ]);
        IntegrityService::append('chats', 'message', (string)$messageId, $hash);

        $members = $db->fetchAll(
            'SELECT user_id FROM chat_participants WHERE conversation_id = ? AND user_id <> ?',
            [$conversationId, (int)$user['id']]
        );
        $convTitle = $db->fetchOne('SELECT conversation_no, title FROM chat_conversations WHERE id = ?', [$conversationId]);
        foreach ($members as $member) {
            NotificationService::notify(
                (int)$member['user_id'],
                'chat',
                'New chat message',
                mb_substr($body, 0, 120),
                '/app.html#chat/' . $conversationId
            );
        }
        AuditService::logAction($user, 'CREATE_RECORD', 'chat_message', (string)$messageId, null, ['conversation_id' => $conversationId], false, 'Message sent');

        return $db->fetchOne(
            'SELECT m.id, m.conversation_id, m.sender_id, u.full_name AS sender_name, u.username, m.body, m.content_hash, m.created_at
             FROM chat_messages m JOIN users u ON u.id = m.sender_id WHERE m.id = ?',
            [$messageId]
        );
    }

    public static function markRead(array $user, int $conversationId): void
    {
        self::assertParticipant((int)$user['id'], $conversationId);
        App::db()->update(
            'chat_participants',
            ['last_read_at' => App::now()],
            'conversation_id = ? AND user_id = ?',
            [$conversationId, (int)$user['id']]
        );
    }
}