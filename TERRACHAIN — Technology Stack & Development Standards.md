# TERRACHAIN — Technology Stack & Development Standards

## 1. Project Identity

**Project:** TerraChain  
**Parent Program:** Project ARWE  
**Category:** Land & Procurement Transparency Infrastructure  
**Primary Technology Stack:** C, PHP, HTML, CSS, Pure JavaScript  
**Database:** MySQL/MariaDB

TerraChain is an ARWE project designed to provide a secure, transparent and auditable digital infrastructure for **land administration and government procurement workflows** in Ethiopia.

The system should prioritize:

- Data integrity
- Transparency
- Accountability
- Auditability
- Secure records
- Controlled access
- Document verification
- Workflow tracking
- Tamper evidence
- Ethiopian localization
- Long-term maintainability

---

# 2. Official Technology Stack

TerraChain uses the following application technologies:

```text
C
PHP
HTML
CSS
Pure JavaScript
MySQL / MariaDB
```

The core application must not depend on:

- Python
- Java
- C#
- Node.js
- React
- Vue
- Angular
- Laravel
- Django
- Spring
- Other major application frameworks

The system should remain based on the five primary programming technologies:

```text
.C
.php
.html
.css
.js
```

---

# 3. Technology Responsibilities

| Technology | Primary Responsibility |
|---|---|
| C | Core systems, cryptographic utilities, integrity services, synchronization, specialized infrastructure |
| PHP | Backend, APIs, business logic, authentication, workflows |
| HTML | Interface structure |
| CSS | Interface design |
| Pure JavaScript | Client-side behavior and API communication |
| MySQL/MariaDB | Persistent data |

---

# 4. High-Level Architecture

```text
                    TERRACHAIN
                        │
        ┌───────────────┼────────────────┐
        │               │                │
        ▼               ▼                ▼
       C              PHP         HTML/CSS/JS
        │               │                │
        │               ▼                ▼
        │          MySQL/MariaDB      Browser
        │
        ▼
Integrity / Security /
Synchronization /
System Services
```

---

# 5. C — Core Systems Layer

C is used for TerraChain's system-level capabilities.

C should be used where direct control over:

- Memory
- Files
- Operating-system resources
- Networking
- Hardware
- Performance
- Local services
- Cryptographic infrastructure

is beneficial.

## C Responsibilities

Potential C components include:

- Data-integrity utilities
- Hash generation
- Hash verification
- Local synchronization
- Tamper-evidence services
- Secure file processing
- Offline node services
- Hardware integration
- Document integrity utilities
- Local monitoring
- Background services
- High-performance processing

---

# 6. C and Blockchain-Style Integrity

TerraChain should distinguish between:

**Blockchain**

and

**Tamper-evident data architecture**.

The purpose of the integrity layer is to make unauthorized historical modification detectable.

A simplified record chain could be:

```text
Record 1
   │
   ├── Hash
   │
   ▼
Record 2
   │
   ├── Previous Hash
   ├── Current Hash
   │
   ▼
Record 3
   │
   ├── Previous Hash
   └── Current Hash
```

If Record 2 is changed:

```text
Record 2
   ↓
Hash changes
   ↓
Record 3 previous-hash relationship fails
   ↓
Integrity violation detected
```

Do not claim that hashing alone makes a system immutable.

The architecture must also protect:

- Keys
- Audit logs
- Database access
- Administrative privileges
- Backups
- Replicas
- Synchronization nodes

---

# 7. Cryptography

TerraChain may use cryptographic mechanisms for:

- Hashing
- Digital signatures
- Document integrity
- Transaction verification
- Authentication
- Secure communication

Do not create proprietary cryptographic algorithms.

Use established cryptographic standards and well-tested implementations.

C should act as a specialized security layer where required rather than unnecessarily replacing mature cryptographic libraries.

---

# 8. PHP — Main Backend

PHP is the primary application backend.

PHP handles:

- Authentication
- Authorization
- Land workflows
- Procurement workflows
- Users
- Roles
- Administrative hierarchy
- Applications
- Documents
- Records
- Approvals
- Notifications
- Reports
- Audit operations
- APIs
- Database communication
- Business rules

---

# 9. PHP API Architecture

TerraChain should expose structured APIs.

Example:

```text
/api/v1/
│
├── auth/
├── users/
├── roles/
├── regions/
├── woredas/
├── kebeles/
├── land/
├── parcels/
├── ownership/
├── applications/
├── documents/
├── procurement/
├── tenders/
├── bids/
├── contracts/
├── payments/
├── verification/
├── audit/
└── reports/
```

All protected endpoints must enforce authentication and authorization.

---

# 10. HTML — Interface Structure

HTML provides the structure for TerraChain interfaces.

Major interfaces include:

## Land Administration

- Land dashboard
- Parcel search
- Citizen/property records
- Application forms
- Ownership records
- Document records
- Verification pages

## Procurement

- Procurement dashboard
- Tender creation
- Tender publication
- Bid submission interface
- Evaluation interface
- Contract interface
- Procurement reports

## Administration

- User management
- Roles
- Permissions
- Administrative units
- Audit logs
- System configuration

---

# 11. CSS — Interface Design

CSS controls the TerraChain visual system.

It must support:

- Government dashboards
- Tables
- Forms
- Search interfaces
- Maps/placeholders
- Status indicators
- Workflow visualization
- Document views
- Responsive layouts
- Mobile layouts
- Print-ready documents
- Accessibility

Do not require a frontend CSS framework.

The design should be implemented using native CSS.

---

# 12. Pure JavaScript

Pure JavaScript handles client-side behavior.

Use JavaScript for:

- API communication
- Search
- Filtering
- Sorting
- Pagination
- Form validation
- Dynamic forms
- Workflow interfaces
- Dashboards
- Notifications
- Document previews
- QR verification interfaces
- Interactive tables
- File uploads
- Offline browser functionality where appropriate

Example:

```text
HTML
  ↓
JavaScript
  ↓
fetch()
  ↓
PHP API
  ↓
Database
```

---

# 13. Frontend Architecture

TerraChain should use:

```text
HTML
+
CSS
+
Pure JavaScript
```

No frontend framework is required.

A typical page:

```text
land-record.html
      │
      ├── land-record.css
      │
      └── land-record.js
               │
               ▼
        PHP REST API
               │
               ▼
          MySQL/MariaDB
```

---

# 14. Database

MySQL/MariaDB stores TerraChain's structured information.

Major entities may include:

```text
Users
Roles
Permissions
Administrative Units
Citizens
Organizations
Parcels
Land Records
Ownership Records
Applications
Documents
Documents Versions
Approvals
Transactions
Tenders
Bids
Evaluations
Contracts
Payments
Audit Logs
Integrity Records
Notifications
```

---

# 15. Land Data Architecture

The land module should distinguish between:

**Person**

**Property/Parcel**

**Legal/Administrative Record**

**Document**

**Transaction**

These must not be treated as one object.

Example:

```text
Citizen
   │
   └── Ownership Relationship
             │
             ▼
           Parcel
             │
             ├── Location
             ├── Status
             ├── Area
             └── Records
                    │
                    ▼
                 Documents
```

---

# 16. Parcel Identification

Every parcel should have a unique system identifier.

The identifier should not rely only on a citizen's name.

A parcel record may contain:

- Parcel ID
- Administrative location
- Geographic reference
- Area
- Status
- Creation date
- Record version
- Related documents
- Ownership relationships
- Audit history

Sensitive information must remain protected.

---

# 17. Land Record Versioning

Land records should be versioned.

Example:

```text
Parcel P-000001
│
├── Version 1
│
├── Version 2
│
├── Version 3
│
└── Current Version
```

Historical versions should not simply be overwritten.

The system should preserve:

- Who changed the record
- What changed
- Why it changed
- When it changed
- Which authorization allowed the change

---

# 18. Land Transaction Workflow

A generalized workflow may be:

```text
Application
    ↓
Identity Verification
    ↓
Document Verification
    ↓
Administrative Review
    ↓
Technical Review
    ↓
Approval
    ↓
Digital Record Update
    ↓
Document Generation
    ↓
Digital Signature
    ↓
Audit Entry
```

Specific legal workflows must be configured according to applicable Ethiopian laws and government procedures.

---

# 19. Procurement Module

TerraChain should provide transparent procurement workflows.

Potential functionality:

- Procurement planning
- Tender creation
- Tender publication
- Supplier registration
- Bid submission
- Bid opening
- Evaluation
- Approval
- Contract creation
- Contract monitoring
- Payment tracking
- Procurement reporting
- Audit

---

# 20. Procurement Workflow

Example:

```text
Procurement Plan
       ↓
Tender Creation
       ↓
Approval
       ↓
Publication
       ↓
Bid Submission
       ↓
Bid Opening
       ↓
Evaluation
       ↓
Decision
       ↓
Approval
       ↓
Contract
       ↓
Execution
       ↓
Payment
       ↓
Audit
```

Every stage must be traceable.

---

# 21. Tender Integrity

A tender should have:

- Tender ID
- Issuing organization
- Procurement category
- Publication date
- Closing date
- Requirements
- Status
- Documents
- Audit history

Once a tender is officially published, critical information must not be silently modified.

Changes should create an auditable version.

---

# 22. Bid Security

Bid information must remain confidential until the legally defined opening stage.

The system must prevent unauthorized users from accessing:

- Submitted bids
- Confidential attachments
- Evaluation information
- Restricted procurement data

Access should be controlled by role and workflow state.

---

# 23. Procurement Transparency

TerraChain should make appropriate procurement information publicly verifiable.

Potential public information:

- Tender ID
- Procuring organization
- Tender category
- Publication date
- Deadline
- Status
- Award information where legally publishable
- Contract information where legally publishable

Sensitive information should remain restricted.

---

# 24. Digital Documents

TerraChain should manage:

- Land certificates
- Ownership documents
- Survey documents
- Tender documents
- Bid documents
- Evaluation documents
- Contracts
- Government approvals
- Administrative correspondence

Each document should have:

- Document ID
- Owner
- Issuer
- Type
- Version
- Creation date
- Status
- Integrity hash
- Signature information
- Audit history

---

# 25. Document Verification

Documents can contain:

- QR code
- Verification number
- Document identifier
- Integrity information

Public verification should return minimal information.

Example:

```text
Document: Valid
Issuer: Authorized Government Office
Issue Date: YYYY-MM-DD
Status: Active
```

Do not expose unnecessary citizen information.

---

# 26. Audit Architecture

Every important action must produce an audit record.

Example:

```text
User
 ↓
Action
 ↓
Resource
 ↓
Previous State
 ↓
New State
 ↓
Timestamp
 ↓
Reason
 ↓
Integrity Record
```

Audit records should be protected from ordinary users.

---

# 27. Audit Events

Examples:

```text
LOGIN
LOGOUT
CREATE_RECORD
UPDATE_RECORD
DELETE_REQUEST
APPROVE
REJECT
SIGN
VERIFY
REVOKE
PUBLISH_TENDER
SUBMIT_BID
OPEN_BIDS
EVALUATE_BID
CREATE_CONTRACT
CHANGE_PERMISSION
```

High-risk operations require enhanced auditing.

---

# 28. Role-Based Access Control

Roles may include:

- Citizen
- Land Officer
- Survey Officer
- Records Officer
- Procurement Officer
- Evaluator
- Supervisor
- Kebele Administrator
- Woreda Administrator
- Regional Administrator
- System Administrator
- Auditor

Roles must not automatically grant unrestricted access.

Permissions should be explicit.

---

# 29. Administrative Scope

Access should follow administrative boundaries.

Example:

```text
Federal
   │
Region
   │
Zone
   │
Woreda
   │
Kebele
```

A user should only access records within their authorized scope unless explicitly granted higher-level access.

---

# 30. Security

TerraChain must defend against:

- SQL injection
- XSS
- CSRF
- Session attacks
- Credential theft
- Privilege escalation
- Insider threats
- Database manipulation
- Document forgery
- API abuse
- Malware
- Ransomware
- Unauthorized exports
- Data tampering

Security must exist at:

```text
Browser
 ↓
PHP
 ↓
API
 ↓
Database
 ↓
Operating System
 ↓
Network
```

---

# 31. Data Integrity

Integrity protection should combine:

- Database constraints
- Access control
- Audit logs
- Record versioning
- Cryptographic hashing
- Digital signatures
- Secure backups
- Restricted administrative access

Do not rely on a single technology for trust.

---

# 32. Offline Architecture

TerraChain should support selected offline workflows where necessary.

Possible architecture:

```text
Central TerraChain
       ▲
       │
Secure Synchronization
       │
       ▼
Local Government Office
       │
       ├── PHP
       ├── C Services
       └── Local Database
```

Offline functionality must carefully define which operations are allowed without central connectivity.

High-risk operations may require online authorization.

---

# 33. Ethiopian Localization

TerraChain should support:

- Ethiopian calendar
- Gregorian calendar
- Amharic
- Afaan Oromo
- Tigrinya
- Somali
- Afar
- English

Language resources should not be hard-coded into application logic.

---

# 34. Ethiopian Calendar

Important dates should support both:

```text
Ethiopian Calendar
+
Gregorian Calendar
```

Examples:

- Application date
- Tender publication
- Tender deadline
- Contract date
- Approval date
- Document issue date

The database should use a consistent internal representation while the interface supports localized display.

---

# 35. API Security

Every API request must pass:

```text
Authentication
      ↓
Authorization
      ↓
Role
      ↓
Permission
      ↓
Administrative Scope
      ↓
Resource Validation
      ↓
Business Rule Validation
      ↓
Database Operation
      ↓
Audit
```

---

# 36. File Security

All uploaded documents must be considered untrusted.

Validate:

- File size
- File extension
- MIME type
- Content
- Filename
- Storage path

Files must not automatically become executable.

---

# 37. Reporting

TerraChain should provide dashboards for:

## Land

- Applications
- Records
- Transactions
- Processing times
- Administrative workload
- Verification activity

## Procurement

- Active tenders
- Closed tenders
- Awards
- Contracts
- Procurement volume
- Processing time
- Audit events

Public dashboards should only expose information authorized for public disclosure.

---

# 38. Performance

The system should be designed to support growth from:

```text
One Office
     ↓
Multiple Kebeles
     ↓
Multiple Woredas
     ↓
Regional Deployment
     ↓
National Deployment
```

Use:

- Database indexes
- Efficient queries
- Pagination
- Caching where appropriate
- Optimized APIs
- Background processing
- File-storage separation
- Horizontal scaling where required

---

# 39. Deployment

A standard server may contain:

```text
Linux
│
├── Apache
├── PHP
├── MySQL/MariaDB
├── TerraChain
│   ├── PHP
│   ├── HTML
│   ├── CSS
│   └── JS
│
└── C Services
```

HTTPS must be mandatory for production deployments.

---

# 40. Backup

Backups should include:

- Database
- Documents
- Configuration
- Audit records
- Integrity information

Backups should be:

- Encrypted
- Tested
- Access controlled
- Stored separately
- Periodically restored for verification

---

# 41. Disaster Recovery

Plan for:

- Hardware failure
- Database corruption
- Cyberattack
- Ransomware
- Power failure
- Network failure
- Storage failure
- Human error

Define:

**RPO — Recovery Point Objective**

**RTO — Recovery Time Objective**

Recovery procedures must be tested rather than merely documented.

---

# 42. Testing

TerraChain requires:

### Unit Testing

Business logic.

### Integration Testing

PHP ↔ Database.

### API Testing

Authentication and endpoints.

### Security Testing

Attack resistance.

### Integrity Testing

Record-chain and hash validation.

### Workflow Testing

Land and procurement workflows.

### Permission Testing

Role and administrative boundaries.

### Performance Testing

Large record volumes.

### Recovery Testing

Backup restoration and disaster recovery.

---

# 43. Development Standards

All developers must follow:

- Secure coding
- Input validation
- Output encoding
- Parameterized queries
- Least privilege
- Error handling
- Logging
- Code review
- Version control
- Automated testing
- Dependency auditing
- Secure configuration

---

# 44. Project Structure

Recommended structure:

```text
terr achain/
│
├── public/
│   ├── index.php
│   ├── css/
│   └── js/
│
├── app/
│   ├── controllers/
│   ├── services/
│   ├── models/
│   ├── repositories/
│   ├── middleware/
│   ├── validators/
│   └── security/
│
├── api/
│   ├── auth/
│   ├── land/
│   ├── parcels/
│   ├── procurement/
│   ├── tenders/
│   ├── contracts/
│   └── verification/
│
├── database/
│
├── storage/
│
├── config/
│
├── assets/
│   ├── css/
│   └── js/
│
└── c/
    ├── integrity/
    ├── sync/
    ├── security/
    └── services/
```

The structure may evolve, but the separation of responsibilities must remain.

---

# 45. No Unnecessary Frameworks

TerraChain should not use a framework simply because it is popular.

The project is intentionally based on:

```text
C
PHP
HTML
CSS
Pure JavaScript
```

This provides:

- Full control
- Low complexity
- Lightweight deployment
- Easier source-code understanding
- Reduced framework dependency
- Long-term maintainability

Security-critical functionality should use mature libraries rather than being reimplemented unnecessarily.

---

# 46. Development Philosophy

TerraChain should follow:

> **Build only what is necessary, make every important action traceable, protect every sensitive record, and make unauthorized changes detectable.**

The system should not pretend that putting data on a blockchain automatically creates trust.

Trust comes from:

```text
Strong Identity
+
Authorization
+
Secure Workflows
+
Auditability
+
Cryptographic Integrity
+
Transparent Rules
+
Protected Infrastructure
```

---

# 47. Official TerraChain Stack

```text
┌───────────────────────────────┐
│          TERRACHAIN           │
├───────────────────────────────┤
│ Frontend                      │
│ HTML + CSS + Pure JavaScript  │
├───────────────────────────────┤
│ Backend                       │
│ PHP                           │
├───────────────────────────────┤
│ Core/System Services          │
│ C                             │
├───────────────────────────────┤
│ Database                      │
│ MySQL / MariaDB               │
└───────────────────────────────┘
```

## Final Rule

**TerraChain is developed using:**

> **C + PHP + HTML + CSS + Pure JavaScript + MySQL/MariaDB**

C provides the systems and specialized infrastructure layer.

PHP provides the backend and government business logic.

HTML provides the structure.

CSS provides the visual system.

Pure JavaScript provides client-side behavior.

MySQL/MariaDB provides persistent structured storage.

Together, these technologies form the official TerraChain development foundation for building a **secure, transparent, auditable land and procurement infrastructure for Digital Ethiopia**.