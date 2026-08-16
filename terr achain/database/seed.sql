-- ============================================================
-- TERRACHAIN — Seed Data
-- ============================================================

-- ------------------------------------------------------------
-- Permissions (explicit, per section 28)
-- ------------------------------------------------------------
INSERT INTO permissions (code, description) VALUES
('auth.login','Login to the system'),
('auth.logout','Logout'),
('users.view','View users'),
('users.create','Create users'),
('users.update','Update users'),
('users.delete','Delete users'),
('roles.view','View roles'),
('roles.manage','Manage roles and permissions'),
('admin_units.view','View administrative units'),
('admin_units.manage','Manage administrative units'),
('citizens.view','View citizens'),
('citizens.create','Create citizens'),
('citizens.update','Update citizens'),
('organizations.view','View organizations'),
('organizations.manage','Manage organizations'),
('parcels.view','View parcels'),
('parcels.create','Create parcels'),
('parcels.update','Update parcels'),
('land_records.view','View land records'),
('land_records.create','Create land records'),
('land_records.verify','Verify land records'),
('land_records.approve','Approve land records'),
('applications.view','View applications'),
('applications.create','Create applications'),
('applications.process','Process application workflow'),
('applications.approve','Approve applications'),
('documents.view','View documents'),
('documents.upload','Upload documents'),
('documents.sign','Sign documents'),
('documents.revoke','Revoke documents'),
('tenders.view','View tenders'),
('tenders.create','Create tenders'),
('tenders.publish','Publish tenders'),
('bids.submit','Submit bids'),
('bids.view_sealed','View sealed bid metadata'),
('bids.open','Open bids'),
('bids.evaluate','Evaluate bids'),
('contracts.view','View contracts'),
('contracts.create','Create contracts'),
('contracts.approve','Approve contracts'),
('payments.view','View payments'),
('payments.create','Record payments'),
('audit.view','View audit logs'),
('reports.view','View reports'),
('verification.view','Verify public documents'),
('settings.manage','Manage system settings'),
('notifications.view','View notifications'),
('users.deactivate','Deactivate users'),
('admin_units.create','Create administrative units'),
('admin_units.update','Update administrative units'),
('roles.create','Create roles'),
('roles.delete','Delete roles'),
('tenders.cancel','Cancel tenders'),
('applications.cancel','Cancel applications'),
('contracts.terminate','Terminate contracts'),
('integrations.view','View institution integration keys and log'),
('integrations.manage','Manage institution integration keys'),
('chat.view','View chat conversations and messages'),
('chat.send','Send chat messages');

-- ------------------------------------------------------------
-- Roles
-- ------------------------------------------------------------
INSERT INTO roles (name, description, is_system) VALUES
('citizen','General citizen (public role)',1),
('land_officer','Land administration officer',1),
('survey_officer','Survey and technical officer',1),
('records_officer','Records management officer',1),
('procurement_officer','Procurement officer',1),
('evaluator','Bid evaluator',1),
('supervisor','Supervisor',1),
('kebele_admin','Kebele administrator',1),
('woreda_admin','Woreda administrator',1),
('regional_admin','Regional administrator',1),
('system_admin','System administrator',1),
('auditor','Auditor',1),
('supplier','Registered supplier',1);

-- Role -> permission mapping
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name='citizen'
  AND p.code IN ('auth.login','auth.logout','documents.view','verification.view','bids.submit','applications.create');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name='land_officer'
  AND p.code IN ('auth.login','auth.logout','citizens.view','citizens.create','citizens.update','parcels.view','parcels.create','parcels.update','land_records.view','land_records.create','land_records.verify','applications.view','applications.create','applications.process','documents.view','documents.upload','documents.sign','notifications.view','chat.view','chat.send');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name='survey_officer'
  AND p.code IN ('auth.login','auth.logout','parcels.view','parcels.create','parcels.update','land_records.view','land_records.create','land_records.verify','applications.view','applications.process','documents.view','documents.upload','notifications.view');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name='records_officer'
  AND p.code IN ('auth.login','auth.logout','citizens.view','parcels.view','land_records.view','land_records.create','documents.view','documents.upload','applications.view','applications.process','notifications.view');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name='procurement_officer'
  AND p.code IN ('auth.login','auth.logout','organizations.view','organizations.manage','tenders.view','tenders.create','tenders.publish','tenders.cancel','bids.view_sealed','bids.open','bids.evaluate','contracts.view','contracts.create','payments.view','payments.create','documents.view','documents.upload','documents.sign','applications.view','notifications.view','chat.view','chat.send');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name='evaluator'
  AND p.code IN ('auth.login','auth.logout','tenders.view','bids.view_sealed','bids.open','bids.evaluate','documents.view','notifications.view','chat.view','chat.send');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name='supervisor'
  AND p.code IN ('auth.login','auth.logout','users.view','citizens.view','parcels.view','land_records.view','applications.view','applications.process','tenders.view','contracts.view','payments.view','documents.view','reports.view','notifications.view');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name='kebele_admin'
  AND p.code IN ('auth.login','auth.logout','users.view','users.create','users.update','citizens.view','citizens.create','citizens.update','parcels.view','parcels.create','parcels.update','land_records.view','land_records.create','applications.view','applications.process','applications.approve','documents.view','documents.upload','documents.sign','reports.view','notifications.view','chat.view','chat.send');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name='woreda_admin'
  AND p.code IN ('auth.login','auth.logout','users.view','users.create','users.update','users.delete','citizens.view','citizens.create','citizens.update','parcels.view','parcels.create','parcels.update','land_records.view','land_records.create','land_records.approve','applications.view','applications.process','applications.approve','applications.cancel','documents.view','documents.upload','documents.sign','tenders.view','tenders.cancel','contracts.view','contracts.terminate','reports.view','audit.view','notifications.view','chat.view','chat.send');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name='regional_admin'
  AND p.code IN ('auth.login','auth.logout','users.view','users.create','users.update','users.delete','roles.view','admin_units.view','citizens.view','parcels.view','land_records.view','land_records.approve','applications.view','applications.approve','applications.cancel','tenders.view','tenders.publish','contracts.view','contracts.approve','payments.view','reports.view','audit.view','documents.view','notifications.view','chat.view','chat.send');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name='system_admin'
  AND p.code IN (SELECT code FROM permissions);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name='auditor'
  AND p.code IN ('auth.login','auth.logout','audit.view','reports.view','documents.view','applications.view','tenders.view','contracts.view','payments.view','land_records.view','parcels.view','citizens.view','users.view','roles.view','admin_units.view','notifications.view','integrations.view','chat.view');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name='supplier'
  AND p.code IN ('auth.login','auth.logout','organizations.view','tenders.view','bids.submit','documents.view','documents.upload','notifications.view');

-- ------------------------------------------------------------
-- Administrative units (Ethiopian structure)
-- ------------------------------------------------------------
INSERT INTO admin_units (unit_type, name_en, name_am, code, parent_id) VALUES
('federal','Federal Democratic Republic of Ethiopia','የኢትዮጵያ ፌዴራላዊ ዲሞክራሲያዊ ሪፐብሊክ','ET-FED',NULL),
('region','Tigray','ትግራይ','ET-TI',1),
('region','Afar','አፋር','ET-AF',1),
('region','Amhara','አማራ','ET-AM',1),
('region','Oromia','ኦሮሚያ','ET-OR',1),
('region','Somali','ሶማሌ','ET-SO',1),
('region','Benishangul-Gumuz','ቤንሻንጉል-ጉሙዝ','ET-BE',1),
('region','SNNPR','ደቡብ ብሔሮች ብሔረሰቦችና ሕዝቦች','ET-SN',1),
('region','Gambela','ጋምቤላ','ET-GA',1),
('region','Harari','ሐረሪ','ET-HA',1),
('region','Dire Dawa','ድሬዳዋ','ET-DD',1),
('region','Addis Ababa','አዲስ አበባ','ET-AA',1),
('region','Sidama','ሲዳማ','ET-SI',1),
('region','South West Ethiopia','ደቡብ ምዕራብ ኢትዮጵያ','ET-SW',1);

-- Zones, woredas, kebeles under Oromia (region id 5) and Addis Ababa (12)
INSERT INTO admin_units (unit_type, name_en, name_am, code, parent_id) VALUES
('zone','East Shewa','ምሥራቅ ሸዋ','ET-OR-ES',5),
('woreda','Ada\'a','አዳ','ET-OR-ES-ADA',15),
('kebele','Ada\'a Kebele 01','አዳ ቀበሌ 01','ET-OR-ES-ADA-01',16),
('kebele','Ada\'a Kebele 02','አዳ ቀበሌ 02','ET-OR-ES-ADA-02',16),
('zone','West Arsi','ምዕራብ አርሲ','ET-OR-WA',5),
('woreda','Shashemene','ሻሸመኔ','ET-OR-WA-SHA',19),
('kebele','Shashemene Kebele 03','ሻሸመኔ ቀበሌ 03','ET-OR-WA-SHA-03',20),
('zone','Finfinne Surrounding','ፊንፊኔ አካባቢ','ET-AA-SR',12),
('woreda','Yeka','የካ','ET-AA-YEKA',22),
('kebele','Yeka Kebele 09','የካ ቀበሌ 09','ET-AA-YEKA-09',23),
('woreda','Bole','ቦሌ','ET-AA-BOLE',22),
('kebele','Bole Kebele 04','ቦሌ ቀበሌ 04','ET-AA-BOLE-04',25);

-- ------------------------------------------------------------
-- Users  (password: Terrachain@2026 — password_hash of it)
-- ------------------------------------------------------------
INSERT INTO users (username, password_hash, full_name, email, role_id, admin_unit_id, language, is_active) VALUES
('admin','$2y$12$ET8.y0lC66milnS92m8t2OOQ/CEz93RBOxFchZPuR0N9Ne/rggxQe','System Administrator','admin@terrachain.et',(SELECT id FROM roles WHERE name='system_admin'),1,'en',1),
('regional.or','$2y$12$ET8.y0lC66milnS92m8t2OOQ/CEz93RBOxFchZPuR0N9Ne/rggxQe','Oromia Regional Admin','regional.or@terrachain.et',(SELECT id FROM roles WHERE name='regional_admin'),5,'or',1),
('woreda.adaa','$2y$12$ET8.y0lC66milnS92m8t2OOQ/CEz93RBOxFchZPuR0N9Ne/rggxQe','Ada\'a Woreda Admin','woreda.adaa@terrachain.et',(SELECT id FROM roles WHERE name='woreda_admin'),16,'am',1),
('kebele.adaa1','$2y$12$ET8.y0lC66milnS92m8t2OOQ/CEz93RBOxFchZPuR0N9Ne/rggxQe','Ada\'a Kebele 01 Admin','kebele.adaa1@terrachain.et',(SELECT id FROM roles WHERE name='kebele_admin'),17,'am',1),
('land.officer','$2y$12$ET8.y0lC66milnS92m8t2OOQ/CEz93RBOxFchZPuR0N9Ne/rggxQe','Land Officer','land@terrachain.et',(SELECT id FROM roles WHERE name='land_officer'),17,'en',1),
('survey.officer','$2y$12$ET8.y0lC66milnS92m8t2OOQ/CEz93RBOxFchZPuR0N9Ne/rggxQe','Survey Officer','survey@terrachain.et',(SELECT id FROM roles WHERE name='survey_officer'),17,'en',1),
('procurement','$2y$12$ET8.y0lC66milnS92m8t2OOQ/CEz93RBOxFchZPuR0N9Ne/rggxQe','Procurement Officer','procurement@terrachain.et',(SELECT id FROM roles WHERE name='procurement_officer'),16,'en',1),
('evaluator','$2y$12$ET8.y0lC66milnS92m8t2OOQ/CEz93RBOxFchZPuR0N9Ne/rggxQe','Bid Evaluator','evaluator@terrachain.et',(SELECT id FROM roles WHERE name='evaluator'),16,'en',1),
('auditor','$2y$12$ET8.y0lC66milnS92m8t2OOQ/CEz93RBOxFchZPuR0N9Ne/rggxQe','Auditor','auditor@terrachain.et',(SELECT id FROM roles WHERE name='auditor'),1,'en',1),
('supplier.abc','$2y$12$ET8.y0lC66milnS92m8t2OOQ/CEz93RBOxFchZPuR0N9Ne/rggxQe','ABC Construction Plc','supplier.abc@terrachain.et',(SELECT id FROM roles WHERE name='supplier'),NULL,'en',1),
('citizen.demo','$2y$12$ET8.y0lC66milnS92m8t2OOQ/CEz93RBOxFchZPuR0N9Ne/rggxQe','Demo Citizen','citizen@terrachain.et',(SELECT id FROM roles WHERE name='citizen'),17,'am',1);

-- ------------------------------------------------------------
-- Demo citizens & organizations
-- ------------------------------------------------------------
INSERT INTO citizens (national_id, first_name, father_name, grand_father_name, gender, birth_date, phone, address, kebele_id) VALUES
('CID-000001','Alemayehu','Tesfaye','Gebre','male','1985-03-12','+251911000001','Ada\'a, Kebele 01',17),
('CID-000002','Hirut','Desta','Mekonnen','female','1990-07-25','+251911000002','Ada\'a, Kebele 02',18),
('CID-000003','Bekele','Abebe','Lemma','male','1978-11-02','+251911000003','Shashemene, Kebele 03',21);

INSERT INTO organizations (name, tin_number, org_type, contact_person, phone, email, address, admin_unit_id) VALUES
('Ada\'a Woreda Land Administration Office','TIN-GOV-001','government','Woreda Admin','+251111111111','woreda@adaa.gov.et','Ada\'a',16),
('ABC Construction Plc','TIN-1001','supplier','Abebe Chala','+251911222222','info@abcconstruction.et','Addis Ababa',25),
('XYZ Supplies Ltd','TIN-1002','supplier','Zewdu Yohannes','+251911333333','info@xyzsupplies.et','Addis Ababa',25),
('Green Agricultural Inputs','TIN-1003','supplier','Getnet Alemu','+251911444444','info@greenagro.et','Ada\'a',16);

-- ------------------------------------------------------------
-- Demo parcels & land records
-- ------------------------------------------------------------
INSERT INTO parcels (parcel_no, kebele_id, location_description, geographic_ref, area, area_unit, land_use, status, current_version, created_by) VALUES
('P-000001',17,'Near the main road, Ada\'a Kebele 01','8.8736 N, 39.0077 E',450.000,'sqm','residential','registered',1,(SELECT id FROM users WHERE username='land.officer')),
('P-000002',17,'Opposite the school compound','8.8741 N, 39.0102 E',320.500,'sqm','residential','registered',1,(SELECT id FROM users WHERE username='land.officer')),
('P-000003',18,'Farmland two km east of kebele center',NULL,2.500,'hectare','agricultural','registered',2,(SELECT id FROM users WHERE username='land.officer'));

INSERT INTO land_records (parcel_id, version, status, title, record_type, data_json, previous_record_id, created_by, reason) VALUES
(1,1,'approved','Initial registration of P-000001','initial_registration',JSON_OBJECT('owner_national_id','CID-000001','area',450.000,'verified',TRUE),NULL,(SELECT id FROM users WHERE username='land.officer'),'Initial registration'),
(2,1,'approved','Initial registration of P-000002','initial_registration',JSON_OBJECT('owner_national_id','CID-000002','area',320.500,'verified',TRUE),NULL,(SELECT id FROM users WHERE username='land.officer'),'Initial registration'),
(3,1,'superseded','Initial registration of P-000003','initial_registration',JSON_OBJECT('owner_national_id','CID-000003','area',2.500,'verified',TRUE),NULL,(SELECT id FROM users WHERE username='land.officer'),'Initial registration');

INSERT INTO land_records (parcel_id, version, status, title, record_type, data_json, previous_record_id, created_by, reason)
SELECT 3,2,'approved','Correction of P-000003 area','correction',JSON_OBJECT('owner_national_id','CID-000003','area',2.500,'verified',TRUE),id,(SELECT id FROM users WHERE username='land.officer'),'Survey re-measurement'
FROM land_records WHERE parcel_id=3 AND version=1;

INSERT INTO ownership_records (parcel_id, citizen_id, share_pct, start_date, is_current, land_record_id) VALUES
(1,1,100.00,'2024-01-15',1,(SELECT id FROM land_records WHERE parcel_id=1 AND version=1)),
(2,2,100.00,'2024-02-01',1,(SELECT id FROM land_records WHERE parcel_id=2 AND version=1)),
(3,3,100.00,'2024-03-10',1,(SELECT id FROM land_records WHERE parcel_id=3 AND version=2));

-- ------------------------------------------------------------
-- Demo applications
-- ------------------------------------------------------------
INSERT INTO applications (application_no, applicant_id, application_type, parcel_id, title, description, status, current_step, assigned_to, language, applied_date, created_by) VALUES
('APP-2026-0001',1,'land_registration',1,'Registration of residential parcel','Application for initial registration of family residential plot','approved',7,(SELECT id FROM users WHERE username='woreda.adaa'),'en','2026-01-10',(SELECT id FROM users WHERE username='land.officer')),
('APP-2026-0002',2,'land_registration',2,'Registration of residential parcel','Application for initial registration','approved',7,(SELECT id FROM users WHERE username='woreda.adaa'),'en','2026-02-05',(SELECT id FROM users WHERE username='land.officer')),
('APP-2026-0003',3,'land_correction',3,'Area correction request','Survey re-measurement found area difference','approved',7,(SELECT id FROM users WHERE username='woreda.adaa'),'am','2026-03-15',(SELECT id FROM users WHERE username='survey.officer'));

-- ------------------------------------------------------------
-- Demo tenders, bids, contracts, payments
-- ------------------------------------------------------------
INSERT INTO tenders (tender_no, title, description, issuing_org_id, admin_unit_id, category, budget_estimate, publication_date, closing_date, evaluation_criteria, status, created_by) VALUES
('T-2026-0001','Construction of secondary school classroom block','Construction of 8 classroom blocks with furniture at Ada\'a',2,16,'Construction',15000000.00,'2026-04-01','2026-12-31','Lowest evaluated price with technical compliance','published',(SELECT id FROM users WHERE username='procurement')),
('T-2026-0002','Supply of agricultural fertilizers','Supply of 500 quintals of DAP fertilizer to Ada\'a woreda',2,16,'Supply',8000000.00,'2026-05-10','2027-01-31','Price and delivery time','published',(SELECT id FROM users WHERE username='procurement'));

INSERT INTO bids (bid_no, tender_id, supplier_org_id, amount, status, opening_status, opened_at, opened_by) VALUES
('B-2026-0001',1,3,14250000.00,'qualified','opened','2026-05-02 10:00:00',(SELECT id FROM users WHERE username='procurement')),
('B-2026-0002',1,4,13980000.00,'qualified','opened','2026-05-02 10:00:00',(SELECT id FROM users WHERE username='procurement')),
('B-2026-0003',2,4,7800000.00,'evaluated','opened','2026-06-12 10:00:00',(SELECT id FROM users WHERE username='procurement'));

INSERT INTO contracts (contract_no, tender_id, bid_id, supplier_org_id, title, value_amount, start_date, end_date, status, approved_by, approved_at, created_by) VALUES
('CT-2026-0001',1,1,3,'Construction of secondary school classroom block',14250000.00,'2026-06-01','2027-06-30','active',(SELECT id FROM users WHERE username='regional.or'),'2026-05-20 14:00:00',(SELECT id FROM users WHERE username='procurement'));

INSERT INTO payments (payment_no, contract_id, amount, payment_type, payment_date, reference, paid_to_org_id, created_by) VALUES
('PAY-2026-0001',1,4275000.00,'advance','2026-06-15','Advance 30%','3',(SELECT id FROM users WHERE username='procurement'));

-- ------------------------------------------------------------
-- Demo documents
-- ------------------------------------------------------------
INSERT INTO documents (document_no, document_type, title, owner_type, owner_id, issuer_id, issued_by_unit, status, current_version, content_hash, verification_token, created_by) VALUES
('DOC-2026-000001','land_certificate','Land certificate for P-000001','parcel',1,(SELECT id FROM users WHERE username='land.officer'),17,'active',1,SHA2('land-certificate-P-000001-v1',256),'TC2026A1B2C3D4E5',(SELECT id FROM users WHERE username='land.officer')),
('DOC-2026-000002','tender_document','Tender document T-2026-0001','tender',1,(SELECT id FROM users WHERE username='procurement'),16,'active',1,SHA2('tender-doc-T-2026-0001-v1',256),'TC2026F6G7H8I9J0',(SELECT id FROM users WHERE username='procurement')),
('DOC-2026-000003','bid_document','Bid document B-2026-0001','bid',1,(SELECT id FROM users WHERE username='procurement'),NULL,'active',1,SHA2('bid-doc-B-2026-0001-v1',256),'TC2026K1L2M3N4O5',(SELECT id FROM users WHERE username='procurement'));

INSERT INTO document_versions (document_id, version, storage_path, file_name, mime_type, file_size, content_hash, uploaded_by) VALUES
(1,1,'documents/DOC-2026-000001-v1.txt','P-000001-certificate.txt','text/plain',214,SHA2('land-certificate-P-000001-v1',256),(SELECT id FROM users WHERE username='land.officer')),
(2,1,'documents/DOC-2026-000002-v1.txt','T-2026-0001-tender.txt','text/plain',180,SHA2('tender-doc-T-2026-0001-v1',256),(SELECT id FROM users WHERE username='procurement')),
(3,1,'documents/DOC-2026-000003-v1.txt','B-2026-0001-bid.txt','text/plain',120,SHA2('bid-doc-B-2026-0001-v1',256),(SELECT id FROM users WHERE username='procurement'));

-- ------------------------------------------------------------
-- Demo notifications
-- ------------------------------------------------------------
INSERT INTO notifications (user_id, type, title, message, link) VALUES
((SELECT id FROM users WHERE username='land.officer'),'approval','Application APP-2026-0001 approved','Your application has been approved.','/applications.html?no=APP-2026-0001'),
((SELECT id FROM users WHERE username='procurement'),'tender','Tender T-2026-0001 published','Tender has been published.','/tenders.html?no=T-2026-0001'),
((SELECT id FROM users WHERE username='supplier.abc'),'bid','Bid B-2026-0001 evaluated','Your bid has been evaluated as qualified.','/tenders.html?no=T-2026-0001');

-- ------------------------------------------------------------
-- Audit + integrity seed
-- ------------------------------------------------------------
INSERT INTO audit_logs (user_id, username, action, resource_type, resource_id, new_state, ip_address, is_high_risk, created_at) VALUES
((SELECT id FROM users WHERE username='admin'),'admin','SYSTEM_INIT','system','1',JSON_OBJECT('event','TerraChain initialized'),'127.0.0.1',1,UTC_TIMESTAMP()),
((SELECT id FROM users WHERE username='admin'),'admin','CREATE_RECORD','user','1',JSON_OBJECT('username','admin'),'127.0.0.1',0,UTC_TIMESTAMP());

INSERT INTO settings (setting_key, setting_value, is_public) VALUES
('system.name','TerraChain',1),
('system.region','Ethiopia',1),
('org.display_name','TerraChain — Land & Procurement Transparency',1),
('security.password_min_length','8',0),
('security.max_login_attempts','5',0),
('security.lockout_minutes','15',0),
('session.timeout_minutes','60',0),
('language.default','en',1),
('calendar.primary','ethiopian',1);

-- ------------------------------------------------------------
-- External institutions + integration keys (section 32)
-- ------------------------------------------------------------
INSERT INTO organizations (name, tin_number, org_type, contact_person, phone, email, address, admin_unit_id) VALUES
('Bole District Court','TIN-GOV-002','government','Court Registrar','+251912000001','court.bole@court.gov.et','Bole Sub-city',25),
('Commercial Bank of Ethiopia — Bole Branch','TIN-BANK-001','government','Branch Manager','+251912000002','bole.branch@cbe.com.et','Bole Sub-city',25);

INSERT INTO integration_keys (organization_id, label, api_key, permissions, status, rate_limit_per_minute, created_by) VALUES
((SELECT id FROM organizations WHERE tin_number='TIN-GOV-002'),'Court verification node','91a4c2d7e6f34b0e8c1d5f29a7b3e6d4f8c1a2b3d4e5f60718293a4b5c6d7e8f','["parcels.verify","applications.verify","documents.verify"]','active',120,(SELECT id FROM users WHERE username='admin')),
((SELECT id FROM organizations WHERE tin_number='TIN-BANK-001'),'Bank payment node','e5f6a7b8c9d0e1f2435b64758a9b0c1d2e3f40516a7b8c9d0e1f2a3b4c5d6e7f','["parcels.verify","payments.confirm"]','active',120,(SELECT id FROM users WHERE username='admin'));

-- ------------------------------------------------------------
-- Internal chat demo
-- ------------------------------------------------------------
INSERT INTO chat_conversations (id, conversation_no, title, created_by) VALUES
(1,'CHT-2026-0001','Kebele 01 parcel transfer follow-up',(SELECT id FROM users WHERE username='admin'));

INSERT INTO chat_participants (conversation_id, user_id, last_read_at) VALUES
(1,(SELECT id FROM users WHERE username='admin'),NOW()),
(1,(SELECT id FROM users WHERE username='land.officer'),NOW());

INSERT INTO chat_messages (conversation_id, sender_id, body, content_hash, is_read, created_at) VALUES
(1,(SELECT id FROM users WHERE username='admin'),'Hello, please review the transfer application for parcel P-000001 today.',SHA2(CONCAT('1:',(SELECT id FROM users WHERE username='admin'),':Hello, please review the transfer application for parcel P-000001 today.'),256),1,NOW() - INTERVAL 2 HOUR),
(1,(SELECT id FROM users WHERE username='land.officer'),'Noted — I will check the ownership records and get back to you.',SHA2(CONCAT('1:',(SELECT id FROM users WHERE username='land.officer'),':Noted — I will check the ownership records and get back to you.'),256),1,NOW() - INTERVAL 90 MINUTE);
