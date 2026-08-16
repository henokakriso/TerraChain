<?php
declare(strict_types=1);

final class IntegrationController
{
    // ---------------- INSTITUTION (machine) ENDPOINTS ----------------

    public function verifyParcel(array $params): never
    {
        IntegrationService::requirePermission('parcels.verify');
        $parcelNo = (string)$params['parcel_no'];
        $row = App::db()->fetchOne(
            'SELECT p.parcel_no, p.status, p.land_use, p.area, p.area_unit,
                    p.current_version, u.name_en AS kebele, p.created_at
             FROM parcels p LEFT JOIN admin_units u ON u.id = p.kebele_id
             WHERE p.parcel_no = ?',
            [$parcelNo]
        );
        if ($row === null) {
            IntegrationService::logAttempt(IntegrationService::current(), $parcelNo, Request::method(), null, 'error', 404);
            Response::notFound('Parcel not found.');
        }
        IntegrationService::logAttempt(IntegrationService::current(), Request::path(), Request::method(), null, 'success', 200);
        Response::success(['institution' => true, 'parcel' => $row]);
    }

    public function verifyApplication(array $params): never
    {
        IntegrationService::requirePermission('applications.verify');
        $applicationNo = (string)$params['application_no'];
        $row = App::db()->fetchOne(
            'SELECT a.application_no, a.application_type, a.status, a.parcel_id, a.created_at, a.decided_at
             FROM applications a WHERE a.application_no = ?',
            [$applicationNo]
        );
        if ($row === null) {
            IntegrationService::logAttempt(IntegrationService::current(), $applicationNo, Request::method(), null, 'error', 404);
            Response::notFound('Application not found.');
        }
        IntegrationService::logAttempt(IntegrationService::current(), Request::path(), Request::method(), null, 'success', 200);
        Response::success(['institution' => true, 'application' => $row]);
    }

    public function verifyDocument(array $params): never
    {
        IntegrationService::requirePermission('documents.verify');
        $documentNo = (string)$params['document_no'];
        $token = (string)Request::query('token', '');
        $row = App::db()->fetchOne(
            'SELECT document_no, document_type, status, created_at, content_hash
             FROM documents WHERE document_no = ? AND (verification_token = ? OR ? = "")',
            [$documentNo, strtoupper($token), $token]
        );
        if ($row === null) {
            IntegrationService::logAttempt(IntegrationService::current(), $documentNo, Request::method(), null, 'error', 404);
            Response::notFound('Document not found or token mismatch.');
        }
        IntegrationService::logAttempt(IntegrationService::current(), Request::path(), Request::method(), null, 'success', 200);
        // Minimal information only (section 25)
        Response::success([
            'institution' => true,
            'document' => [
                'document_no' => $row['document_no'],
                'result' => $row['status'] === 'active' ? 'valid' : ($row['status'] === 'revoked' ? 'revoked' : 'invalid'),
                'document_type' => $row['document_type'],
                'issue_date' => substr((string)$row['created_at'], 0, 10),
                'status' => $row['status'],
            ],
        ]);
    }

    public function confirmPayment(array $params): never
    {
        IntegrationService::requirePermission('payments.confirm');
        // Note: no browser CSRF here — the HMAC signature over method+path+
        // timestamp+body is the replay/cross-site protection for machines.
        $paymentNo = (string)$params['payment_no'];
        $reference = trim((string)Request::input('reference', ''));
        $db = App::db();
        $row = $db->fetchOne(
            'SELECT id, payment_no, amount, currency, reference FROM payments WHERE payment_no = ?',
            [$paymentNo]
        );
        if ($row === null) {
            IntegrationService::logAttempt(IntegrationService::current(), $paymentNo, Request::method(), null, 'error', 404);
            Response::notFound('Payment not found.');
        }
        $updates = ['reference' => $reference !== '' ? $reference : $row['reference']];
        $db->update('payments', $updates, 'id = ?', [(int)$row['id']]);
        IntegrationService::logAttempt(IntegrationService::current(), Request::path(), Request::method(), hash('sha256', json_encode($updates)), 'success', 200);
        AuditService::log((int)IntegrationService::current()['organization_id'], 'INTEGRATION_CONFIRM', 'payment', (string)$row['id'], null, $updates, null, true, 'Payment confirmed by institution');
        Response::success(['payment_no' => $paymentNo, 'status' => 'confirmed', 'reference' => $updates['reference']]);
    }

    // ---------------- ADMINISTRATION ----------------

    public function keys(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'integrations.view')) {
            Response::forbidden();
        }
        $rows = App::db()->fetchAll(
            'SELECT ik.id, ik.label, ik.organization_id, o.name AS organization_name, ik.api_key, ik.permissions,
                    ik.status, ik.rate_limit_per_minute, ik.created_at, ik.last_used_at
             FROM integration_keys ik JOIN organizations o ON o.id = ik.organization_id
             ORDER BY ik.id DESC'
        );
        Response::success(['keys' => $rows]);
    }

    public function createKey(array $params = []): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'integrations.manage')) {
            Response::forbidden();
        }
        $data = Request::body();
        Validator::make()
            ->required('organization_id', $data['organization_id'] ?? null)
            ->required('label', $data['label'] ?? null)
            ->required('permissions', $data['permissions'] ?? null)
            ->throwIfFails();
        $db = App::db();
        $org = $db->fetchOne('SELECT id FROM organizations WHERE id = ?', [(int)$data['organization_id']]);
        if ($org === null) {
            Response::error('Organization not found.', 404);
        }
        $perms = is_array($data['permissions']) ? array_values(array_unique(array_map('strval', $data['permissions']))) : [];
        if (count($perms) === 0) {
            Response::error('At least one permission is required.', 422);
        }
        $keyId = $db->insert('integration_keys', [
            'organization_id' => (int)$data['organization_id'],
            'label' => $data['label'],
            'api_key' => bin2hex(random_bytes(32)),
            'permissions' => json_encode($perms, JSON_UNESCAPED_SLASHES),
            'rate_limit_per_minute' => max(1, min(10000, (int)($data['rate_limit_per_minute'] ?? 60))),
            'created_by' => (int)$user['id'],
        ]);
        AuditService::logAction($user, 'CREATE_RECORD', 'integration_key', (string)$keyId, null, ['organization_id' => (int)$data['organization_id']], true, 'Integration key created');
        $row = $db->fetchOne(
            'SELECT ik.id, ik.label, ik.organization_id, o.name AS organization_name, ik.api_key, ik.permissions, ik.status, ik.rate_limit_per_minute
             FROM integration_keys ik JOIN organizations o ON o.id = ik.organization_id WHERE ik.id = ?',
            [$keyId]
        );
        Response::success($row, 'Integration key created — share it securely with the institution.', 201);
    }

    public function revokeKey(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'integrations.manage')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        $db = App::db();
        $row = $db->fetchOne('SELECT * FROM integration_keys WHERE id = ?', [$id]);
        if ($row === null) {
            Response::notFound('Integration key not found.');
        }
        $db->update('integration_keys', ['status' => 'revoked'], 'id = ?', [$id]);
        AuditService::logAction($user, 'REVOKE', 'integration_key', (string)$id, null, ['label' => $row['label']], true, 'Integration key revoked');
        Response::success(null, 'Integration key revoked.');
    }

    public function logs(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'integrations.view')) {
            Response::forbidden();
        }
        $rows = App::db()->fetchAll(
            'SELECT il.id, il.organization_id, o.name AS organization_name, il.direction, il.endpoint, il.method,
                    il.payload_hash, il.response_status, il.status_code, il.created_at
             FROM integration_logs il JOIN organizations o ON o.id = il.organization_id
             ORDER BY il.id DESC LIMIT 200'
        );
        Response::success(['logs' => $rows]);
    }

    /** Runs the independent C HMAC verifier against a sample (section 41). */
    public function hmacCTest(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'integrations.view')) {
            Response::forbidden();
        }
        $data = (string)Request::query('data', '');
        $signature = (string)Request::query('signature', '');
        $key = (string)Request::query('key', '');
        if ($data === '' || $signature === '' || $key === '') {
            Response::error('data, signature and key query parameters are required.', 422);
        }
        try {
            $result = IntegrationService::verifyHmacWithC($data, $signature, $key);
        } catch (ApiException $e) {
            Response::error($e->getMessage(), $e->getCode());
        }
        Response::success($result);
    }
}