<?php
declare(strict_types=1);

/** Registers all v1 API routes. Middleware enforces auth/permission/scope (section 35). */
function registerApiRoutes(Router $router, string $prefix): void
{
    $auth = Middleware::auth();
    $perm = Middleware::permission(...);

    // ---------- AUTH ----------
    $router->post($prefix . '/auth/login', [new AuthController(), 'login']);
    $router->post($prefix . '/auth/logout', [new AuthController(), 'logout'], [$auth]);
    $router->get($prefix . '/auth/me', [new AuthController(), 'me'], [$auth]);
    $router->get($prefix . '/auth/csrf', [new AuthController(), 'csrfToken']);
    $router->get($prefix . '/auth/notifications', [new AuthController(), 'notifications'], [$auth]);
    $router->post($prefix . '/auth/notifications/read', [new AuthController(), 'markNotificationRead'], [$auth]);

    // ---------- USERS / ROLES / ADMIN UNITS ----------
    $router->get($prefix . '/users', [new AdminController(), 'listUsers'], [$auth, $perm('users.view')]);
    $router->post($prefix . '/users', [new AdminController(), 'createUser'], [$auth, $perm('users.create')]);
    $router->put($prefix . '/users/{id}', [new AdminController(), 'updateUser'], [$auth, $perm('users.update')]);
    $router->post($prefix . '/users/{id}/deactivate', [new AdminController(), 'deactivateUser'], [$auth, $perm('users.delete')]);

    $router->get($prefix . '/roles', [new AdminController(), 'listRoles'], [$auth, $perm('roles.view')]);
    $router->post($prefix . '/roles', [new AdminController(), 'createRole'], [$auth, $perm('roles.create')]);
    $router->delete($prefix . '/roles/{id}', [new AdminController(), 'deleteRole'], [$auth, $perm('roles.delete')]);
    $router->get($prefix . '/roles/{id}/permissions', [new AdminController(), 'rolePermissions'], [$auth, $perm('roles.view')]);
    $router->put($prefix . '/roles/{id}/permissions', [new AdminController(), 'updateRolePermissions'], [$auth, $perm('roles.manage')]);

    $router->get($prefix . '/admin-units', [new AdminController(), 'listUnits'], [$auth, $perm('admin_units.view')]);
    $router->get($prefix . '/admin-units/tree', [new AdminController(), 'unitTree'], [$auth, $perm('admin_units.view')]);
    $router->post($prefix . '/admin-units', [new AdminController(), 'createUnit'], [$auth, $perm('admin_units.create')]);
    $router->put($prefix . '/admin-units/{id}', [new AdminController(), 'updateUnit'], [$auth, $perm('admin_units.update')]);

    // ---------- LAND / PARCELS ----------
    $router->get($prefix . '/parcels', [new LandController(), 'list'], [$auth, $perm('parcels.view')]);
    $router->get($prefix . '/parcels/{id}', [new LandController(), 'detail'], [$auth, $perm('parcels.view')]);
    $router->post($prefix . '/parcels', [new LandController(), 'create'], [$auth, $perm('parcels.create')]);
    $router->put($prefix . '/parcels/{id}', [new LandController(), 'update'], [$auth, $perm('parcels.update')]);
    $router->get($prefix . '/parcels/{id}/versions', [new LandController(), 'versions'], [$auth, $perm('land_records.view')]);

    // ---------- APPLICATIONS (land workflow) ----------
    $router->get($prefix . '/applications', [new ApplicationController(), 'list'], [$auth, $perm('applications.view')]);
    $router->get($prefix . '/applications/{id}', [new ApplicationController(), 'detail'], [$auth, $perm('applications.view')]);
    $router->post($prefix . '/applications', [new ApplicationController(), 'create'], [$auth, $perm('applications.create')]);
    $router->post($prefix . '/applications/{id}/workflow', [new ApplicationController(), 'workflow'], [$auth, $perm('applications.process')]);

    // ---------- PROCUREMENT: TENDERS ----------
    $router->get($prefix . '/tenders', [new ProcurementController(), 'tenders'], [$auth, $perm('tenders.view')]);
    $router->get($prefix . '/tenders/{id}', [new ProcurementController(), 'tenderDetail'], [$auth, $perm('tenders.view')]);
    $router->post($prefix . '/tenders', [new ProcurementController(), 'createTender'], [$auth, $perm('tenders.create')]);
    $router->post($prefix . '/tenders/{id}/publish', [new ProcurementController(), 'publishTender'], [$auth, $perm('tenders.publish')]);
    $router->put($prefix . '/tenders/{id}', [new ProcurementController(), 'amendTender'], [$auth, $perm('tenders.create')]);
    $router->post($prefix . '/tenders/{id}/cancel', [new ProcurementController(), 'cancelTender'], [$auth, $perm('tenders.cancel')]);
    $router->get($prefix . '/tenders/{id}/versions', [new ProcurementController(), 'tenderVersions'], [$auth, $perm('tenders.view')]);

    // ---------- PROCUREMENT: BIDS ----------
    $router->get($prefix . '/bids', [new ProcurementController(), 'bids'], [$auth, $perm('bids.view_sealed')]);
    $router->post($prefix . '/bids', [new ProcurementController(), 'submitBid'], [$auth, $perm('bids.submit')]);
    $router->post($prefix . '/tenders/{id}/open-bids', [new ProcurementController(), 'openBids'], [$auth, $perm('bids.open')]);
    $router->post($prefix . '/bids/{id}/evaluate', [new ProcurementController(), 'evaluateBid'], [$auth, $perm('bids.evaluate')]);

    // ---------- CONTRACTS & PAYMENTS ----------
    $router->get($prefix . '/contracts', [new ProcurementController(), 'contracts'], [$auth, $perm('contracts.view')]);
    $router->post($prefix . '/contracts', [new ProcurementController(), 'createContract'], [$auth, $perm('contracts.create')]);
    $router->post($prefix . '/contracts/{id}/approve', [new ProcurementController(), 'approveContract'], [$auth, $perm('contracts.approve')]);
    $router->post($prefix . '/contracts/{id}/terminate', [new ProcurementController(), 'terminateContract'], [$auth, $perm('contracts.terminate')]);
    $router->get($prefix . '/payments', [new ProcurementController(), 'payments'], [$auth, $perm('payments.view')]);
    $router->post($prefix . '/payments', [new ProcurementController(), 'createPayment'], [$auth, $perm('payments.create')]);

    // ---------- DOCUMENTS (sections 24-25) ----------
    $router->get($prefix . '/documents', [new DocumentController(), 'list'], [$auth, $perm('documents.view')]);
    $router->get($prefix . '/documents/{id}', [new DocumentController(), 'detail'], [$auth, $perm('documents.view')]);
    $router->post($prefix . '/documents/upload', [new DocumentController(), 'upload'], [$auth, $perm('documents.upload')]);
    $router->post($prefix . '/documents/{id}/versions', [new DocumentController(), 'addVersion'], [$auth, $perm('documents.upload')]);
    $router->post($prefix . '/documents/{id}/sign', [new DocumentController(), 'sign'], [$auth, $perm('documents.sign')]);
    $router->post($prefix . '/documents/{id}/revoke', [new DocumentController(), 'revoke'], [$auth, $perm('documents.revoke')]);

    // ---------- ORGANIZATIONS ----------
    $router->get($prefix . '/organizations', [new OrganizationController(), 'list'], [$auth, $perm('organizations.view')]);
    $router->get($prefix . '/organizations/{id}', [new OrganizationController(), 'detail'], [$auth, $perm('organizations.view')]);
    $router->post($prefix . '/organizations', [new OrganizationController(), 'create'], [$auth, $perm('organizations.manage')]);
    $router->put($prefix . '/organizations/{id}', [new OrganizationController(), 'update'], [$auth, $perm('organizations.manage')]);
    $router->post($prefix . '/organizations/{id}/status', [new OrganizationController(), 'setStatus'], [$auth, $perm('organizations.manage')]);

    // ---------- INTERNAL CHAT ----------
    $router->get($prefix . '/chat/conversations', [new ChatController(), 'conversations'], [$auth, $perm('chat.view')]);
    $router->post($prefix . '/chat/conversations', [new ChatController(), 'create'], [$auth, $perm('chat.view')]);
    $router->get($prefix . '/chat/conversations/{id}/messages', [new ChatController(), 'messages'], [$auth, $perm('chat.view')]);
    $router->post($prefix . '/chat/conversations/{id}/messages', [new ChatController(), 'send'], [$auth, $perm('chat.send')]);
    $router->post($prefix . '/chat/conversations/{id}/read', [new ChatController(), 'read'], [$auth, $perm('chat.view')]);

    // ---------- INSTITUTION INTEGRATION (sections 32, 41) ----------
    $machineAuth = Middleware::machine();
    $router->get($prefix . '/integrations/parcels/{parcel_no}', [new IntegrationController(), 'verifyParcel'], [$machineAuth]);
    $router->get($prefix . '/integrations/applications/{application_no}', [new IntegrationController(), 'verifyApplication'], [$machineAuth]);
    $router->get($prefix . '/integrations/documents/{document_no}', [new IntegrationController(), 'verifyDocument'], [$machineAuth]);
    $router->post($prefix . '/integrations/payments/{payment_no}/confirm', [new IntegrationController(), 'confirmPayment'], [$machineAuth]);

    $router->get($prefix . '/integrations/keys', [new IntegrationController(), 'keys'], [$auth, $perm('integrations.view')]);
    $router->post($prefix . '/integrations/keys', [new IntegrationController(), 'createKey'], [$auth, $perm('integrations.manage')]);
    $router->post($prefix . '/integrations/keys/{id}/revoke', [new IntegrationController(), 'revokeKey'], [$auth, $perm('integrations.manage')]);
    $router->get($prefix . '/integrations/logs', [new IntegrationController(), 'logs'], [$auth, $perm('integrations.view')]);
    $router->get($prefix . '/system/security/hmac-c', [new IntegrationController(), 'hmacCTest'], [$auth, $perm('integrations.view')]);

    // ---------- AUDIT / INTEGRITY / REPORTS / SETTINGS ----------
    $router->get($prefix . '/audit', [new AuditController(), 'list'], [$auth, $perm('audit.view')]);
    $router->get($prefix . '/audit/{id}', [new AuditController(), 'detail'], [$auth, $perm('audit.view')]);
    $router->get($prefix . '/reports/dashboard', [new ReportController(), 'dashboard'], [$auth, $perm('reports.view')]);
    $router->get($prefix . '/system/settings', [new SystemController(), 'settings'], [$auth]);
    $router->put($prefix . '/system/settings', [new AdminController(), 'updateSettings'], [$auth, $perm('settings.manage')]);
    $router->get($prefix . '/system/integrity', [new SystemController(), 'integrityStatus'], [$auth, $perm('audit.view')]);
    $router->get($prefix . '/system/integrity/{chain}/export', [new SystemController(), 'integrityExport'], [$auth, $perm('audit.view')]);
    $router->get($prefix . '/system/integrity/{chain}/verify-c', [new SystemController(), 'integrityVerifyC'], [$auth, $perm('audit.view')]);

    // ---------- PUBLIC VERIFICATION (section 25) ----------
    $router->get('/api/v1/verification/{token}', [new VerificationController(), 'verify']);
    $router->get('/api/v1/verification/{token}/qr', [new VerificationController(), 'qr']);
}