<?php
declare(strict_types=1);

final class DocumentController
{
    public function list(array $params = []): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'documents.view')) {
            Response::forbidden();
        }
        $wheres = ['1=1'];
        $args = [];
        if (Request::query('document_type') !== null && Request::query('document_type') !== '') {
            $wheres[] = 'd.document_type = ?';
            $args[] = Request::query('document_type');
        }
        if (Request::query('status') !== null && Request::query('status') !== '') {
            $wheres[] = 'd.status = ?';
            $args[] = Request::query('status');
        }
        if (Request::query('q') !== null && Request::query('q') !== '') {
            $wheres[] = '(d.title LIKE ? OR d.document_no LIKE ?)';
            $q = '%' . Request::query('q') . '%';
            $args[] = $q;
            $args[] = $q;
        }
        $rows = App::db()->fetchAll(
            'SELECT d.id, d.document_no, d.document_type, d.title, d.status, d.current_version,
                    d.content_hash, d.verification_token, d.created_at,
                    dv.file_name, dv.mime_type, dv.file_size, dv.signature,
                    u.full_name AS issuer_name
             FROM documents d
             LEFT JOIN document_versions dv ON dv.document_id = d.id AND dv.version = d.current_version
             LEFT JOIN users u ON u.id = d.issuer_id
             WHERE ' . implode(' AND ', $wheres) . '
             ORDER BY d.id DESC LIMIT 200',
            $args
        );
        if (!Auth::isSystemAdmin($user)) {
            $rows = array_values(array_filter(
                $rows,
                fn(array $r): bool => $r['issuer_name'] === $user['full_name'] || Auth::inScope($user['admin_unit_id'] ?? null, (int)($r['issued_by_unit'] ?? 0))
            ));
        }
        Response::success(['documents' => $rows, 'total' => count($rows)]);
    }

    public function detail(array $params): never
    {
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'documents.view')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        $db = App::db();
        $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$id]);
        if ($doc === null) {
            Response::notFound('Document not found.');
        }
        if (!Auth::isSystemAdmin($user) && !Auth::inScope($user['admin_unit_id'] ?? null, (int)($doc['issued_by_unit'] ?? 0))) {
            Response::forbidden('Document outside your administrative scope.');
        }
        $versions = $db->fetchAll(
            'SELECT dv.*, u.full_name AS uploader_name, s.full_name AS signer_name
             FROM document_versions dv
             LEFT JOIN users u ON u.id = dv.uploaded_by
             LEFT JOIN users s ON s.id = dv.signed_by
             WHERE dv.document_id = ? ORDER BY dv.version DESC',
            [$id]
        );
        $doc['versions'] = $versions;
        Response::success($doc);
    }

    public function upload(array $params = []): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'documents.upload')) {
            Response::forbidden();
        }
        $data = Request::body();
        Validator::make()
            ->required('document_type', $data['document_type'] ?? null)
            ->required('title', $data['title'] ?? null)
            ->in('document_type', $data['document_type'] ?? null, ['land_certificate','ownership_document','survey_document','tender_document','bid_document','evaluation_document','contract','approval','correspondence','other'])
            ->throwIfFails();

        $upload = $this->resolveFileUpload();
        if ($upload === null) {
            Response::error('A file is required (multipart "file" or base64 "file_contents").', 422);
        }
        try {
            $doc = DocumentService::createDocument($user, $data, $upload);
        } catch (ApiException $e) {
            Response::error($e->getMessage(), $e->getCode());
        }
        Response::success($doc, 'Document uploaded', 201);
    }

    public function addVersion(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'documents.upload')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        $upload = $this->resolveFileUpload();
        if ($upload === null) {
            Response::error('A file is required (multipart "file" or base64 "file_contents").', 422);
        }
        try {
            $doc = DocumentService::addVersion($user, $id, $upload, Request::input('reason'));
        } catch (ApiException $e) {
            Response::error($e->getMessage(), $e->getCode());
        }
        Response::success($doc, 'New version uploaded', 201);
    }

    public function sign(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'documents.sign')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        $db = App::db();
        $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$id]);
        if ($doc === null) {
            Response::notFound('Document not found.');
        }
        if ((int)$doc['current_version'] === 0) {
            Response::error('Document has no file version to sign.', 422);
        }
        $sig = 'TC-SIG:' . (int)$user['id'] . ':' . bin2hex(random_bytes(8)) . ':' . $doc['content_hash'];
        $db->update('document_versions',
            ['signature' => $sig, 'signed_by' => (int)$user['id']],
            'document_id = ? AND version = ?',
            [$id, (int)$doc['current_version']]
        );
        IntegrityService::append('documents', 'document', (string)$id, 'signed:' . ($doc['content_hash'] ?? ''));
        AuditService::logAction($user, 'SIGN', 'document', (string)$id, null, ['signature' => $sig], true, 'Document signed');
        Response::success(['id' => $id, 'signature' => $sig, 'content_hash' => $doc['content_hash']], 'Document signed');
    }

    public function revoke(array $params): never
    {
        Csrf::validate();
        $user = Auth::requireLogin();
        if (!Auth::hasPermission($user, 'documents.revoke')) {
            Response::forbidden();
        }
        $id = (int)$params['id'];
        try {
            DocumentService::revoke($user, $id, Request::input('reason'));
        } catch (ApiException $e) {
            Response::error($e->getMessage(), $e->getCode());
        }
        Response::success(null, 'Document revoked');
    }

    /**
     * Accepts either a multipart "file" upload or an inline base64 payload
     * (file_contents + file_name) so the API is usable from plain clients.
     */
    private function resolveFileUpload(): ?array
    {
        $files = Request::files();
        if (isset($files['file']) && ($files['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            return $files['file'];
        }
        $contents = Request::input('file_contents', '');
        $fileName = (string)Request::input('file_name', '');
        if ($contents === '' || $fileName === '') {
            return null;
        }
        $decoded = base64_decode((string)$contents, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'tcfile');
        if ($path === false) {
            return null;
        }
        file_put_contents($path, $decoded);
        return [
            'error' => UPLOAD_ERR_OK,
            'name' => $fileName,
            'tmp_name' => $path,
            'size' => strlen($decoded),
            'type' => Request::input('file_mime', 'application/octet-stream'),
        ];
    }
}