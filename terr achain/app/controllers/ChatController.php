<?php
declare(strict_types=1);

final class ChatController
{
    public function conversations(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'chat.view')) {
            Response::forbidden();
        }
        Response::success(['conversations' => ChatService::conversations((int)$user['id'])]);
    }

    public function create(array $params = []): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'chat.view') || !Auth::hasPermission($user, 'chat.send')) {
            Response::forbidden();
        }
        $data = Request::body();
        $participants = is_array($data['participant_ids'] ?? null) ? $data['participant_ids'] : [];
        if (count($participants) === 0) {
            Response::error('participant_ids (user ids) are required.', 422);
        }
        try {
            $conversation = ChatService::createConversation($user, $data['title'] ?? null, $participants);
        } catch (ApiException $e) {
            Response::error($e->getMessage(), $e->getCode());
        }
        Response::success($conversation, 'Conversation created', 201);
    }

    public function messages(array $params): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'chat.view')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        $after = max(0, (int)Request::query('after', 0));
        try {
            $messages = ChatService::messages($user, $id, $after);
        } catch (ApiException $e) {
            Response::error($e->getMessage(), $e->getCode());
        }
        Response::success(['conversation_id' => $id, 'messages' => $messages]);
    }

    public function send(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'chat.send')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        $body = (string)Request::input('body', '');
        try {
            $message = ChatService::sendMessage($user, $id, $body);
        } catch (ApiException $e) {
            Response::error($e->getMessage(), $e->getCode());
        }
        Response::success($message, 'Message sent', 201);
    }

    public function read(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'chat.view')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        try {
            ChatService::markRead($user, $id);
        } catch (ApiException $e) {
            Response::error($e->getMessage(), $e->getCode());
        }
        Response::success(null, 'Marked as read');
    }
}