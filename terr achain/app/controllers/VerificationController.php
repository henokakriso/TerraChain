<?php
declare(strict_types=1);

final class VerificationController
{
    /** Public endpoint (section 25): no authentication, minimal info. */
    public function verify(array $params): never
    {
        $token = (string)$params['token'];
        if (!preg_match('/^[A-Za-z0-9]+$/', $token)) {
            Response::error('Invalid verification token.', 422);
        }
        try {
            $result = DocumentService::verifyPublic($token);
            Response::success($result, 'Document verification completed');
        } catch (ApiException $e) {
            Response::error($e->getMessage(), 404);
        }
    }

    public function qr(array $params): never
    {
        try {
            $doc = App::db()->fetchOne(
                'SELECT document_no FROM documents WHERE verification_token = ?',
                [strtoupper((string)$params['token'])]
            );
        } catch (Throwable) {
            $doc = null;
        }
        $payload = $doc !== null
            ? json_encode(['v' => 1, 'doc' => $doc['document_no'], 'url' => App::baseUrl() . '/verify.html?token=' . $params['token']])
            : json_encode(['v' => 1, 'error' => 'not_found']);
        $hash = hash('sha256', $payload);
        Response::success([
            'payload' => $payload,
            'payload_hash' => $hash,
            'note' => 'QR payload is encoded client-side for display; integrity carried by hash.',
        ]);
    }
}

