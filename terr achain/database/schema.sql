-- ============================================================
-- TERRACHAIN — Database Schema
-- Land Administration & Procurement Transparency Infrastructure
-- MySQL/MariaDB | utf8mb4
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Administrative units (Federal > Region > Zone > Woreda > Kebele)
-- ------------------------------------------------------------
CREATE TABLE admin_units (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    unit_type ENUM('federal','region','zone','woreda','kebele') NOT NULL,
    name_en VARCHAR(190) NOT NULL,
    name_am VARCHAR(190) NULL,
    code VARCHAR(30) NOT NULL UNIQUE,
    parent_id INT UNSIGNED NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_unit_type (unit_type),
    KEY idx_unit_parent (parent_id),
    CONSTRAINT fk_unit_parent FOREIGN KEY (parent_id) REFERENCES admin_units(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Roles & permissions (RBAC)
-- ------------------------------------------------------------
CREATE TABLE roles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(120) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Users
-- ------------------------------------------------------------
CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(190) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    role_id INT UNSIGNED NULL,
    admin_unit_id INT UNSIGNED NULL,
    language VARCHAR(10) NOT NULL DEFAULT 'en',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    failed_login_count SMALLINT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_role (role_id),
    KEY idx_user_unit (admin_unit_id),
    CONSTRAINT fk_user_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL,
    CONSTRAINT fk_user_unit FOREIGN KEY (admin_unit_id) REFERENCES admin_units(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Sessions (server-side, session attacks defense)
-- ------------------------------------------------------------
CREATE TABLE sessions (
    id CHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    user_agent VARCHAR(255) NULL,
    ip_address VARCHAR(64) NULL,
    expires_at DATETIME NOT NULL,
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sess_user (user_id),
    CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Citizens / Persons
-- ------------------------------------------------------------
CREATE TABLE citizens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    national_id VARCHAR(40) NULL UNIQUE,
    first_name VARCHAR(120) NOT NULL,
    father_name VARCHAR(120) NULL,
    grand_father_name VARCHAR(120) NULL,
    gender ENUM('male','female') NULL,
    birth_date DATE NULL,
    phone VARCHAR(40) NULL,
    address VARCHAR(255) NULL,
    kebele_id INT UNSIGNED NULL,
    status ENUM('active','deceased','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_citizen_kebele (kebele_id),
    CONSTRAINT fk_cit_kebele FOREIGN KEY (kebele_id) REFERENCES admin_units(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Organizations (suppliers, government bodies)
-- ------------------------------------------------------------
CREATE TABLE organizations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    tin_number VARCHAR(40) NULL UNIQUE,
    org_type ENUM('government','private','ngo','supplier') NOT NULL DEFAULT 'private',
    contact_person VARCHAR(120) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    address VARCHAR(255) NULL,
    admin_unit_id INT UNSIGNED NULL,
    status ENUM('active','inactive','blacklisted') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_org_unit (admin_unit_id),
    CONSTRAINT fk_org_unit FOREIGN KEY (admin_unit_id) REFERENCES admin_units(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Parcels (property/person separation per section 15-16)
-- ------------------------------------------------------------
CREATE TABLE parcels (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parcel_no VARCHAR(40) NOT NULL UNIQUE,
    kebele_id INT UNSIGNED NOT NULL,
    location_description VARCHAR(255) NULL,
    geographic_ref VARCHAR(120) NULL,
    area DECIMAL(12,3) NULL,
    area_unit ENUM('sqm','hectare') NOT NULL DEFAULT 'sqm',
    land_use ENUM('residential','agricultural','commercial','industrial','institutional','public','mixed') NULL,
    status ENUM('registered','pending','disputed','transferred','archived') NOT NULL DEFAULT 'pending',
    current_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_parcel_kebele (kebele_id),
    KEY idx_parcel_status (status),
    CONSTRAINT fk_parcel_kebele FOREIGN KEY (kebele_id) REFERENCES admin_units(id) ON DELETE RESTRICT,
    CONSTRAINT fk_parcel_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Land records (versioned — section 17)
-- ------------------------------------------------------------
CREATE TABLE land_records (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parcel_id INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    status ENUM('draft','submitted','verified','approved','rejected','superseded','cancelled') NOT NULL DEFAULT 'draft',
    title VARCHAR(190) NOT NULL,
    record_type ENUM('initial_registration','transfer','inheritance','lease','correction','sale','other') NOT NULL,
    data_json JSON NULL,
    previous_record_id INT UNSIGNED NULL,
    content_hash CHAR(64) NULL,
    created_by INT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lr_parcel (parcel_id),
    KEY idx_lr_version (parcel_id, version),
    KEY idx_lr_hash (content_hash),
    CONSTRAINT fk_lr_parcel FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
    CONSTRAINT fk_lr_prev FOREIGN KEY (previous_record_id) REFERENCES land_records(id) ON DELETE SET NULL,
    CONSTRAINT fk_lr_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_lr_version (parcel_id, version)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Ownership relationships (citizen <-> parcel)
-- ------------------------------------------------------------
CREATE TABLE ownership_records (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parcel_id INT UNSIGNED NOT NULL,
    citizen_id INT UNSIGNED NOT NULL,
    share_pct DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    land_record_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_own_parcel (parcel_id),
    KEY idx_own_citizen (citizen_id),
    KEY idx_own_record (land_record_id),
    CONSTRAINT fk_own_parcel FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
    CONSTRAINT fk_own_citizen FOREIGN KEY (citizen_id) REFERENCES citizens(id) ON DELETE RESTRICT,
    CONSTRAINT fk_own_record FOREIGN KEY (land_record_id) REFERENCES land_records(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Applications (land workflow — section 18)
-- ------------------------------------------------------------
CREATE TABLE applications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_no VARCHAR(40) NOT NULL UNIQUE,
    applicant_id INT UNSIGNED NOT NULL,
    application_type ENUM('land_registration','land_transfer','land_lease','land_correction','parcel_search','other') NOT NULL,
    parcel_id INT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    status ENUM('submitted','identity_verification','document_verification','administrative_review','technical_review','approved','rejected','cancelled') NOT NULL DEFAULT 'submitted',
    current_step INT UNSIGNED NOT NULL DEFAULT 1,
    assigned_to INT UNSIGNED NULL,
    decision_reason VARCHAR(255) NULL,
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    language VARCHAR(10) NOT NULL DEFAULT 'en',
    applied_date DATE NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_app_parcel (parcel_id),
    KEY idx_app_status (status),
    KEY idx_app_applicant (applicant_id),
    CONSTRAINT fk_app_parcel FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE SET NULL,
    CONSTRAINT fk_app_citizen FOREIGN KEY (applicant_id) REFERENCES citizens(id) ON DELETE RESTRICT,
    CONSTRAINT fk_app_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_app_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_app_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Documents (section 24)
-- ------------------------------------------------------------
CREATE TABLE documents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_no VARCHAR(40) NOT NULL UNIQUE,
    document_type ENUM('land_certificate','ownership_document','survey_document','tender_document','bid_document','evaluation_document','contract','approval','correspondence','other') NOT NULL,
    title VARCHAR(190) NOT NULL,
    owner_type ENUM('citizen','organization','parcel','application','tender','bid','contract','other') NULL,
    owner_id INT UNSIGNED NULL,
    issuer_id INT UNSIGNED NULL,
    issued_by_unit INT UNSIGNED NULL,
    status ENUM('draft','active','superseded','revoked','expired') NOT NULL DEFAULT 'draft',
    current_version INT UNSIGNED NOT NULL DEFAULT 0,
    content_hash CHAR(64) NULL,
    verification_token CHAR(16) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_doc_type (document_type),
    KEY idx_doc_owner (owner_type, owner_id),
    KEY idx_doc_verification (verification_token),
    CONSTRAINT fk_doc_issuer FOREIGN KEY (issuer_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_doc_unit FOREIGN KEY (issued_by_unit) REFERENCES admin_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_doc_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE document_versions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    storage_path VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT NOT NULL,
    content_hash CHAR(64) NOT NULL,
    signature VARCHAR(255) NULL,
    signed_by INT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dv_version (document_id, version),
    CONSTRAINT fk_dv_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_dv_signer FOREIGN KEY (signed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_dv_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Approvals (workflow)
-- ------------------------------------------------------------
CREATE TABLE approvals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    approval_no VARCHAR(40) NOT NULL UNIQUE,
    approvable_type ENUM('application','tender','contract','land_record','other') NOT NULL,
    approvable_id INT UNSIGNED NOT NULL,
    step_name VARCHAR(120) NOT NULL,
    approver_id INT UNSIGNED NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    comment VARCHAR(255) NULL,
    decided_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_approval_target (approvable_type, approvable_id),
    KEY idx_approval_approver (approver_id),
    CONSTRAINT fk_appr_approver FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Transactions (land)
-- ------------------------------------------------------------
CREATE TABLE transactions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_no VARCHAR(40) NOT NULL UNIQUE,
    parcel_id INT UNSIGNED NOT NULL,
    transaction_type ENUM('registration','transfer','inheritance','lease','correction','revocation') NOT NULL,
    amount DECIMAL(18,2) NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'ETB',
    transaction_date DATE NOT NULL,
    land_record_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tx_parcel (parcel_id),
    KEY idx_tx_record (land_record_id),
    CONSTRAINT fk_tx_parcel FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE RESTRICT,
    CONSTRAINT fk_tx_record FOREIGN KEY (land_record_id) REFERENCES land_records(id) ON DELETE SET NULL,
    CONSTRAINT fk_tx_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Procurement — tenders (section 19-21)
-- ------------------------------------------------------------
CREATE TABLE tenders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tender_no VARCHAR(40) NOT NULL UNIQUE,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    issuing_org_id INT UNSIGNED NOT NULL,
    admin_unit_id INT UNSIGNED NULL,
    category VARCHAR(120) NULL,
    budget_estimate DECIMAL(18,2) NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'ETB',
    publication_date DATE NULL,
    closing_date DATE NULL,
    evaluation_criteria TEXT NULL,
    status ENUM('draft','pending_approval','published','closed','awarded','cancelled') NOT NULL DEFAULT 'draft',
    current_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tender_status (status),
    KEY idx_tender_org (issuing_org_id),
    KEY idx_tender_unit (admin_unit_id),
    CONSTRAINT fk_tender_org FOREIGN KEY (issuing_org_id) REFERENCES organizations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_tender_unit FOREIGN KEY (admin_unit_id) REFERENCES admin_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_tender_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE tender_versions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tender_id INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    snapshot JSON NOT NULL,
    changed_by INT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tv_version (tender_id, version),
    CONSTRAINT fk_tv_tender FOREIGN KEY (tender_id) REFERENCES tenders(id) ON DELETE CASCADE,
    CONSTRAINT fk_tv_changer FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Bids (confidential until opening — section 22)
-- ------------------------------------------------------------
CREATE TABLE bids (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bid_no VARCHAR(40) NOT NULL UNIQUE,
    tender_id INT UNSIGNED NOT NULL,
    supplier_org_id INT UNSIGNED NOT NULL,
    amount DECIMAL(18,2) NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'ETB',
    price_encrypted VARBINARY(512) NULL,
    status ENUM('submitted','opened','evaluated','qualified','disqualified','awarded','rejected') NOT NULL DEFAULT 'submitted',
    opening_status ENUM('sealed','opened') NOT NULL DEFAULT 'sealed',
    opened_at DATETIME NULL,
    opened_by INT UNSIGNED NULL,
    evaluation_score DECIMAL(5,2) NULL,
    evaluation_notes VARCHAR(255) NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bid_tender (tender_id),
    KEY idx_bid_supplier (supplier_org_id),
    KEY idx_bid_status (status),
    CONSTRAINT fk_bid_tender FOREIGN KEY (tender_id) REFERENCES tenders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_bid_org FOREIGN KEY (supplier_org_id) REFERENCES organizations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_bid_opener FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE bid_documents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bid_id INT UNSIGNED NOT NULL,
    document_id INT UNSIGNED NOT NULL,
    is_confidential TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bd (bid_id, document_id),
    CONSTRAINT fk_bd_bid FOREIGN KEY (bid_id) REFERENCES bids(id) ON DELETE CASCADE,
    CONSTRAINT fk_bd_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE evaluations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bid_id INT UNSIGNED NOT NULL,
    evaluator_id INT UNSIGNED NOT NULL,
    score DECIMAL(5,2) NOT NULL,
    criteria JSON NULL,
    comments VARCHAR(255) NULL,
    status ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ev_bid (bid_id),
    KEY idx_ev_evaluator (evaluator_id),
    CONSTRAINT fk_ev_bid FOREIGN KEY (bid_id) REFERENCES bids(id) ON DELETE CASCADE,
    CONSTRAINT fk_ev_evaluator FOREIGN KEY (evaluator_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Contracts & payments
-- ------------------------------------------------------------
CREATE TABLE contracts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    contract_no VARCHAR(40) NOT NULL UNIQUE,
    tender_id INT UNSIGNED NULL,
    bid_id INT UNSIGNED NULL,
    supplier_org_id INT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    value_amount DECIMAL(18,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'ETB',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('draft','active','completed','terminated','cancelled') NOT NULL DEFAULT 'draft',
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ct_tender (tender_id),
    KEY idx_ct_bid (bid_id),
    KEY idx_ct_supplier (supplier_org_id),
    CONSTRAINT fk_ct_tender FOREIGN KEY (tender_id) REFERENCES tenders(id) ON DELETE SET NULL,
    CONSTRAINT fk_ct_bid FOREIGN KEY (bid_id) REFERENCES bids(id) ON DELETE SET NULL,
    CONSTRAINT fk_ct_org FOREIGN KEY (supplier_org_id) REFERENCES organizations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ct_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ct_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_no VARCHAR(40) NOT NULL UNIQUE,
    contract_id INT UNSIGNED NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'ETB',
    payment_type ENUM('advance','interim','final','other') NOT NULL,
    payment_date DATE NOT NULL,
    reference VARCHAR(120) NULL,
    paid_to_org_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pay_contract (contract_id),
    CONSTRAINT fk_pay_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pay_org FOREIGN KEY (paid_to_org_id) REFERENCES organizations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pay_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Notifications
-- ------------------------------------------------------------
CREATE TABLE notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(60) NOT NULL,
    title VARCHAR(190) NOT NULL,
    message TEXT NULL,
    link VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_user (user_id, is_read),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Audit log (section 26-27)
-- ------------------------------------------------------------
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    username VARCHAR(80) NULL,
    action VARCHAR(80) NOT NULL,
    resource_type VARCHAR(80) NULL,
    resource_id VARCHAR(80) NULL,
    previous_state JSON NULL,
    new_state JSON NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    reason VARCHAR(255) NULL,
    is_high_risk TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_action (action),
    KEY idx_audit_resource (resource_type, resource_id),
    KEY idx_audit_time (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Integrity chain (section 6) — hash-linked records
-- ------------------------------------------------------------
CREATE TABLE integrity_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    chain_name VARCHAR(80) NOT NULL,
    record_type VARCHAR(80) NOT NULL,
    record_id BIGINT UNSIGNED NOT NULL,
    record_hash CHAR(64) NOT NULL,
    previous_hash CHAR(64) NULL,
    anchor_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_chain (chain_name, record_type, record_id),
    KEY idx_prev (previous_hash)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- System settings
-- ------------------------------------------------------------
CREATE TABLE settings (
    setting_key VARCHAR(120) NOT NULL,
    setting_value TEXT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Integrity verification log (public document checks)
-- ------------------------------------------------------------
CREATE TABLE verification_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_no VARCHAR(40) NOT NULL,
    result ENUM('valid','invalid','revoked','not_found') NOT NULL,
    ip_address VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vr_doc (document_no),
    KEY idx_vr_time (created_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Institution integration (section 32) — API keys for external systems
-- ------------------------------------------------------------
CREATE TABLE integration_keys (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id INT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    api_key CHAR(64) NOT NULL UNIQUE,
    permissions JSON NOT NULL,
    status ENUM('active','revoked') NOT NULL DEFAULT 'active',
    rate_limit_per_minute INT NOT NULL DEFAULT 60,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_ik_org (organization_id),
    CONSTRAINT fk_ik_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ik_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE integration_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id INT UNSIGNED NULL,
    direction ENUM('in','out') NOT NULL DEFAULT 'in',
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL DEFAULT 'GET',
    payload_hash CHAR(64) NULL,
    response_status ENUM('success','denied','error') NOT NULL,
    status_code SMALLINT NOT NULL DEFAULT 200,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_il_org (organization_id),
    KEY idx_il_time (created_at),
    CONSTRAINT fk_il_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Internal chat (messages between system users)
-- ------------------------------------------------------------
CREATE TABLE chat_conversations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_no VARCHAR(40) NOT NULL UNIQUE,
    title VARCHAR(190) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_chat_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE chat_participants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    last_read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cp_member (conversation_id, user_id),
    CONSTRAINT fk_cp_conv FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_cp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    content_hash CHAR(64) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cm_conv (conversation_id, id),
    KEY idx_cm_sender (sender_id),
    CONSTRAINT fk_cm_conv FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_cm_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
