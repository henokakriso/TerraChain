<?php
declare(strict_types=1);

/**
 * Secure file handling (section 36).
 * All uploaded documents are untrusted: size, extension, MIME, content,
 * filename and storage path are validated. Files are stored outside the
 * web root and never become executable.
 */
final class DocumentService
{
    public static function storeUpload(array $file, string $subDir = 'documents'): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ApiException('File upload failed.', 400);
        }
        $maxBytes = (int)App::config('security.upload_max_bytes', 10485760);
        if ((int)$file['size'] > $maxBytes) {
            throw new ApiException('File exceeds the maximum allowed size.', 413);
        }
        if ((int)$file['size'] === 0) {
            throw new ApiException('Empty file rejected.', 400);
        }

        $originalName = (string)$file['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt = App::config('security.allowed_upload_extensions');
        if (!in_array($extension, $allowedExt, true)) {
            throw new ApiException("File extension .$extension is not allowed.", 415);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        $allowedMimes = App::config('security.allowed_upload_mimes');
        if (!in_array($detectedMime, $allowedMimes, true)) {
            throw new ApiException("File content type ($detectedMime) is not allowed.", 415);
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
        $safeName = mb_substr($safeName, 0, 200);
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $relative = $subDir . '/' . $storedName;
        $destination = App::storagePath($relative);

        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0750, true);
        }
        if (is_uploaded_file($file['tmp_name'])) {
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new ApiException('Could not store the uploaded file.', 500);
            }
        } else {
            // Inline (base64) payloads arrive as plain temp files
            if (!copy($file['tmp_name'], $destination)) {
                throw new ApiException('Could not store the uploaded file.', 500);
            }
            @unlink($file['tmp_name']);
        }
        @chmod($destination, 0640);

        $contentHash = hash_file('sha256', $destination);

        return [
            'storage_path' => $relative,
            'file_name' => $safeName,
            'mime_type' => $detectedMime,
            'file_size' => (int)$file['size'],
            'content_hash' => $contentHash,
        ];
    }

    public static function createDocument(array $user, array $data, ?array $upload = null): array
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $user, $data, $upload) {
            $count = (int)$db->fetchOne('SELECT COUNT(*) AS c FROM documents')['c'];
            $documentNo = 'DOC-' . date('Y') . '-' . str_pad((string)($count + 1), 6, '0', STR_PAD_LEFT);

            $stored = null;
            if ($upload !== null) {
                $stored = self::storeUpload($upload);
            }

            $token = strtoupper(bin2hex(random_bytes(8)));
            $docId = $db->insert('documents', [
                'document_no' => $documentNo,
                'document_type' => $data['document_type'],
                'title' => $data['title'],
                'owner_type' => $data['owner_type'] ?? null,
                'owner_id' => $data['owner_id'] ?? null,
                'issuer_id' => $user['id'],
                'issued_by_unit' => $user['admin_unit_id'] ?? null,
                'status' => $data['status'] ?? 'active',
                'current_version' => $stored !== null ? 1 : 0,
                'content_hash' => $stored['content_hash'] ?? null,
                'verification_token' => $token,
                'created_by' => $user['id'],
            ]);

            if ($stored !== null) {
                $db->insert('document_versions', [
                    'document_id' => $docId,
                    'version' => 1,
                    'storage_path' => $stored['storage_path'],
                    'file_name' => $stored['file_name'],
                    'mime_type' => $stored['mime_type'],
                    'file_size' => $stored['file_size'],
                    'content_hash' => $stored['content_hash'],
                    'uploaded_by' => $user['id'],
                ]);
                IntegrityService::append('documents', 'document', (string)$docId, $stored['content_hash']);
            }

            AuditService::log((int)$user['id'], 'CREATE_RECORD', 'document', (string)$docId, null, [
                'document_no' => $documentNo,
                'type' => $data['document_type'],
            ], null, false, 'Document created');

            return $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$docId]);
        });
    }

    public static function addVersion(array $user, int $documentId, array $upload, ?string $reason = null): array
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $user, $documentId, $upload, $reason) {
            $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$documentId]);
            if ($doc === null) {
                throw new ApiException('Document not found.', 404);
            }
            $stored = self::storeUpload($upload);
            $newVersion = (int)$doc['current_version'] + 1;
            $db->insert('document_versions', [
                'document_id' => $documentId,
                'version' => $newVersion,
                'storage_path' => $stored['storage_path'],
                'file_name' => $stored['file_name'],
                'mime_type' => $stored['mime_type'],
                'file_size' => $stored['file_size'],
                'content_hash' => $stored['content_hash'],
                'reason' => $reason,
                'uploaded_by' => $user['id'],
            ]);
            $db->update('documents', [
                'current_version' => $newVersion,
                'content_hash' => $stored['content_hash'],
                'status' => 'active',
            ], 'id = ?', [$documentId]);
            IntegrityService::append('documents', 'document', (string)$documentId, $stored['content_hash']);
            AuditService::log((int)$user['id'], 'CREATE_RECORD', 'document_version', (string)$documentId, null, [
                'version' => $newVersion,
            ], null, false, $reason ?? 'New version uploaded');
            return $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$documentId]);
        });
    }

    public static function revoke(array $user, int $documentId, ?string $reason = null): void
    {
        $db = App::db();
        $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$documentId]);
        if ($doc === null) {
            throw new ApiException('Document not found.', 404);
        }
        $db->update('documents', ['status' => 'revoked'], 'id = ?', [$documentId]);
        AuditService::log((int)$user['id'], 'REVOKE', 'document', (string)$documentId, ['status' => $doc['status']], ['status' => 'revoked'], null, true, $reason);
    }

    /** Public verification (section 25): returns minimal information only. */
    public static function verifyPublic(string $token): array
    {
        $db = App::db();
        $doc = $db->fetchOne(
            'SELECT document_no, document_type, status, created_at, content_hash
             FROM documents WHERE verification_token = ?',
            [strtoupper($token)]
        );
        if ($doc === null) {
            $db->insert('verification_requests', ['document_no' => strtoupper($token), 'result' => 'not_found', 'ip_address' => Request::ip()]);
            throw new ApiException('Document not found.', 404);
        }
        $result = $doc['status'] === 'active' ? 'valid' : ($doc['status'] === 'revoked' ? 'revoked' : 'invalid');
        $db->insert('verification_requests', ['document_no' => $doc['document_no'], 'result' => $result, 'ip_address' => Request::ip()]);

        // Minimal information — no citizen data exposed
        return [
            'document_no' => $doc['document_no'],
            'result' => $result,
            'document_type' => $doc['document_type'],
            'issued_by' => 'Authorized Government Office',
            'issue_date' => substr((string)$doc['created_at'], 0, 10),
            'status' => $doc['status'],
        ];
    }
}
