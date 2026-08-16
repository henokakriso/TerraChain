<?php
declare(strict_types=1);

/**
 * Land workflow (section 18):
 * Application → Identity Verification → Document Verification →
 * Administrative Review → Technical Review → Approval →
 * Digital Record Update → Document Generation → Digital Signature → Audit Entry
 */
final class LandService
{
    public const STEPS = [
        1 => 'submitted',
        2 => 'identity_verification',
        3 => 'document_verification',
        4 => 'administrative_review',
        5 => 'technical_review',
        6 => 'approval',
        7 => 'digital_record_update',
        8 => 'document_generation',
        9 => 'digital_signature',
        10 => 'audit_entry',
    ];

    public static function workflowStatus(string $status): array
    {
        $map = [
            'submitted' => 1,
            'identity_verification' => 2,
            'document_verification' => 3,
            'administrative_review' => 4,
            'technical_review' => 5,
            'approved' => 6,
            'rejected' => -1,
            'cancelled' => -2,
        ];
        return [
            'step' => $map[$status] ?? 0,
            'status' => $status,
            'step_name' => self::STEPS[$map[$status] ?? 0] ?? 'unknown',
            'total_steps' => 10,
            'progress' => $map[$status] > 0 ? $map[$status] / 10 : 0,
        ];
    }

    /** Advances an application through the workflow with audit + approval records. */
    public static function advance(array $user, int $applicationId, string $action, ?string $comment = null): array
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $user, $applicationId, $action, $comment) {
            $app = $db->fetchOne('SELECT * FROM applications WHERE id = ?', [$applicationId]);
            if ($app === null) {
                throw new ApiException('Application not found.', 404);
            }
            $appNo = $app['application_no'];
            $current = $app['status'];
            $newStatus = null;

            switch ($action) {
                case 'approve':
                    if ($current === 'submitted') {
                        $newStatus = 'identity_verification';
                    } elseif ($current === 'identity_verification') {
                        $newStatus = 'document_verification';
                    } elseif ($current === 'document_verification') {
                        $newStatus = 'administrative_review';
                    } elseif ($current === 'administrative_review') {
                        $newStatus = 'technical_review';
                    } elseif ($current === 'technical_review') {
                        $newStatus = 'approved';
                    } else {
                        throw new ApiException("Cannot approve an application in status '$current'.", 409);
                    }
                    break;
                case 'reject':
                    $newStatus = 'rejected';
                    break;
                case 'cancel':
                    $newStatus = 'cancelled';
                    break;
                default:
                    throw new ApiException('Unknown workflow action.', 400);
            }

            $stepMap = ['submitted' => 1, 'identity_verification' => 2, 'document_verification' => 3, 'administrative_review' => 4, 'technical_review' => 5, 'approved' => 7, 'rejected' => 0, 'cancelled' => 0];
            $db->update('applications', [
                'status' => $newStatus,
                'current_step' => $stepMap[$newStatus] ?? 0,
                'decision_reason' => $comment,
                'decided_by' => $user['id'],
                'decided_at' => App::now(),
            ], 'id = ?', [$applicationId]);

            $db->insert('approvals', [
                'approval_no' => 'APV-' . $appNo . '-' . $current,
                'approvable_type' => 'application',
                'approvable_id' => $applicationId,
                'step_name' => $current,
                'approver_id' => $user['id'],
                'status' => $newStatus === 'rejected' ? 'rejected' : 'approved',
                'comment' => $comment,
                'decided_at' => App::now(),
            ]);

            AuditService::log((int)$user['id'], strtoupper($action === 'cancel' ? 'DELETE_REQUEST' : $action), 'application', (string)$applicationId, ['status' => $current], ['status' => $newStatus], null, $action === 'reject' || $action === 'cancel', $comment);

            if ($newStatus === 'approved' && !empty($app['parcel_id'])) {
                $record = self::finalizeApproval($user, $app, $comment);
                NotificationService::notify((int)$app['created_by'] ?: 0, 'approval', "Application $appNo approved", "Your application $appNo has been approved.", '/applications.html?no=' . $appNo);
                return ['status' => $newStatus, 'record' => $record, 'app' => $appNo];
            }

            return ['status' => $newStatus, 'app' => $appNo];
        });
    }

    /** Steps 7-10 of the workflow: record update, document generation, signature, audit. */
    private static function finalizeApproval(array $user, array $app, ?string $reason): array
    {
        $db = App::db();
        $parcel = $db->fetchOne('SELECT * FROM parcels WHERE id = ?', [(int)$app['parcel_id']]);
        if ($parcel === null) {
            return [];
        }
        $nextVersion = (int)$parcel['current_version'] + 1;
        $payload = json_encode([
            'application' => $app['application_no'],
            'status' => 'approved',
            'reason' => $reason,
            'approved_by' => $user['username'],
        ], JSON_UNESCAPED_SLASHES);

        $recordId = $db->insert('land_records', [
            'parcel_id' => $parcel['id'],
            'version' => $nextVersion,
            'status' => 'approved',
            'title' => 'Approved via ' . $app['application_no'],
            'record_type' => 'other',
            'data_json' => $payload,
            'previous_record_id' => $parcel['id'] . '.' . $parcel['current_version'],
            'created_by' => $user['id'],
            'reason' => $reason ?? 'Workflow approval',
        ]);

        $db->update('parcels', ['status' => 'registered', 'current_version' => $nextVersion], 'id = ?', [$parcel['id']]);

        IntegrityService::append('land_records', 'land_record', (string)$recordId, $payload);

        $doc = DocumentService::createDocument($user, [
            'document_type' => 'land_certificate',
            'title' => 'Land certificate for ' . $parcel['parcel_no'],
            'owner_type' => 'parcel',
            'owner_id' => $parcel['id'],
        ]);

        AuditService::log((int)$user['id'], 'SIGN', 'land_record', (string)$recordId, null, ['version' => $nextVersion, 'document' => $doc['document_no']], null, false, 'Workflow finalized: record + document + signature + audit');

        return ['land_record_id' => $recordId, 'version' => $nextVersion, 'document_no' => $doc['document_no']];
    }
}
