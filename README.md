# TerraChain — Land & Procurement Transparency Infrastructure

## Overview

TerraChain is an ARWE project providing a secure, transparent and auditable
digital infrastructure for **land administration and government procurement
workflows** in Ethiopia. It combines a REST API, a browser interface and C-based
system utilities (cryptography, integrity verification, synchronization) into a
single lightweight, self-hostable platform.

The repository is made up of two companion applications that share one
technology stack:

```
terr achain/    Procurement/API-focused application with land workflows,
                documents, chat, institution integrations and the C toolchain
achain/         Land administration application (auth, contracts, land,
                parcels, procurement, tenders, verification)
```

## Problem

Land administration and government procurement in low-resource environments
face the same set of failures:

- Records that can be silently altered, lost or duplicated with no audit trail.
- Closed procurement cycles: sealed bids, evaluations and awards that cannot be
  independently verified.
- Government institutions cannot verify documents or parcel/application status
  issued by other agencies without manual phone calls or counterfeit-prone
  paper.
- Expensive frameworks (React, Laravel, Django, Node) that over-consume the
  memory and connectivity budgets of small agencies.
- No tamper-evident, hash-linked history that survives both database problems
  and human cover-ups.

## Solution

TerraChain implements a **hash-chained integrity ledger**: every meaningful
state change (parcel record, tender, bid, contract, document, chat message,
integration call, audit event) is content-hashed and appended to an immutable
SHA-256 chain that C utilities can independently verify offline.

- **RBAC + admin-unit scoping** — citizens, land officers, procurement,
  evaluators, supervisors, auditors and admins each see only what their
  permission set allows.
- **Sealed-bid confidentiality** — prices stay sealed until the designated
  opening step; the process leaves a versioned, auditable trail.
- **Document lifecycle** — upload, sign, version, revoke and public
  verification using a token-verifier model that exposes only minimal
  information (section 25 of the standards doc).
- **Institution (machine) integration** — organizations (courts, banks) get
  API keys and authenticate with HMAC-SHA256-signed requests to verify parcels,
  applications and documents or confirm payments — every attempt is logged.
- **Internal chat** — participants exchange messages with content hashing and
  unread tracking, while every message is appended to the chain.
- **A C toolchain** (`c/crypto/hmac.c`, `c/integrity/chain.c`) providing
  standalone verification utilities with the same canonical string used by the
  PHP API.

## Features

- Land records and parcel lifecycle with transfer and ownership history
- Applications with multi-step workflow (submit, approve, cancel) with
  snapshots of every decision
- Procurement: tender drafts, publishing, sealed bids, amendments, evaluations,
  awards, contract signing, cancellation and termination
- Document management: upload, versioning, signing, revocation, public
  verification
- Organization registry with status control (active / blacklisted)
- Institution API with HMAC-SHA256 machine authentication, rate limiting,
  timestamps and full request logging
- Internal chat with polling, unread badges and participant permissions
- Admin panel: users (create/deactivate), roles, admin units, system settings
- Audit log and integrity ledger covering 8 chains, verifiable via
  `c/bin/chain-verify`
- C HMAC-SHA256 utility with RFC 4231 self-tests
- API test suite (89 checks) plus 25 unit tests

## Architecture

```
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
 Integrity / Security / Cryptography
 (c/bin/hmac, c/bin/chain-verify)
```

```
terr achain/
├── api/          REST API (v1)
├── app/          PHP application (controllers, models, services, security)
├── bin/          dev helpers (server, db-reset, test)
├── c/            C components (crypto, integrity) + tests
├── config/       Configuration
├── database/     Schema and seed data
├── public/       Web root
├── storage/      Storage (logs, uploads, cache)
└── tests/        API and unit test suites
```

## Technology

| Technology | Primary Responsibility |
|---|---|
| C | Cryptography utilities (HMAC-SHA256), integrity verification, self-tests |
| PHP | Backend, REST API, business logic, authentication, workflows |
| HTML / CSS / Pure JavaScript | Interface structure, design and client-side behavior |
| MySQL/MariaDB | Persistent data |

The core application avoids Python, Java, Node.js, React, Laravel, Django and
other major frameworks — it stays entirely within `.c .php .html .css .js`.

## Installation

Requirements: PHP ≥ 8, a C compiler (`make`, `gcc`) and MySQL/MariaDB.

```bash
cd "terr achain"

# 1. Build the C toolchain (hmac + chain verification)
make -C c test        # verify with RFC 4231 vectors, then:
make -C c

# 2. Create the database
mysql -h 127.0.0.1 -P 33306 -u terrachain -pterrachain_local_dev -e \
  "CREATE DATABASE terrachain CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -h 127.0.0.1 -P 33306 -u terrachain -pterrachain_local_dev terrachain \
  < database/schema.sql
mysql -h 127.0.0.1 -P 33306 -u terrachain -pterrachain_local_dev terrachain \
  < database/seed.sql

# 3. Start the dev server
bin/dev.sh server     # app on http://127.0.0.1:8081
```

Or use the bundled helper / full verification battery:

```bash
bin/dev.sh db-reset   # drop + schema + seed
bin/dev.sh test       # DB reset + C build + API (89) + unit (25) suites
```

Adjust the DB credentials in `config/config.php` for a non-default server.

## Usage

Open **http://127.0.0.1:8081/app.html** and sign in. All seeded accounts use the
password `Terrachain@2026`:

| Account | Role |
|---|---|
| `admin` | System admin — every module + admin panel |
| `citizen.demo` | Citizen self-service |
| `land.officer`, `surveyor`, `records.officer` | Land workflow |
| `procurement`, `evaluator`, `supplier.tech` | Procurement cycle |
| `woreda.adaa` | Woreda admin (approvals, cancellations) |
| `auditor` | Audit + integrity views |

Core flows:

1. **Land** — register parcels, file applications, approve through the chain.
2. **Procurement** — create tender → publish → sealed bid → open → award →
   contract → terminate.
3. **Documents** — upload, sign, revoke; any citizen can verify
   `DOC-2026-XXXX` with its token under minimal-information rules.
4. **Integrations** — issue an API key as admin; sign requests with
   `X-TC-Key`, `X-TC-Signature` and `X-TC-Timestamp` headers over the canonical
   string `METHOD\nPATH\nTIMESTAMP_MS\nBODY` (see `tests/run_api_tests.php`
   `T::machine()` for a reference client).
5. **Chat** — create conversations between users with polling every 3 seconds.
6. **Integrity** — inspect the ledger; verify chains with `c/bin/chain-verify`.

Test everything: `bin/dev.sh stop && bin/dev.sh db-reset && bin/dev.sh server`
then `php tests/run_api_tests.php` (expect 89 passed, 0 failed).

## Security

- **RBAC permission codes** — every route checks explicit permissions;
  admin-unit scope isolates data between zones.
- **CSRF protection** — all state-changing user requests require a CSRF token.
- **Hash-chained integrity** — land records, documents, tenders, bids,
  contracts, chats, integrations and audit events are SHA-256 chained and
  independently verifiable in C.
- **Institution auth** — HMAC-SHA256 over the exact canonical string with a
  5-minute timestamp window, per-minute rate limits and constant-time
  comparison (`hash_equals`); every machine call is logged including denials.
- **Sealed bids** — prices are confidential until opening; amendments version
  the tender for audit.
- **Minimal verification info** — public document verification returns only
  status and essential metadata, never citizen PII.
- **C constant-time comparison** — `c/bin/hmac` rejects forged signatures,
  wrong keys and malformed hex with distinct exit codes (0/1/2).
- **Password hashing** — bcrypt with per-user salts; deactivated users cannot
  log in.

## Screenshots

Screenshots will be added here as the interfaces are finalized across the land,
procurement, documents, integrations and chat modules.

## Roadmap

- Browser-driven E2E smoke tests for the new views (documents upload, chat
  polling, key issue)
- Export of additional integrity chains (parcels, applications) and offline
  verification docs
- Institution onboarding documentation (canonical string + reference client
  examples)
- Arabic/Amharic localization pass and RTL support
- Sync utilities for low-connectivity field offices

## License

ARWE Public Source License (ARWE-PSL) v1.0 — see [LICENSE](LICENSE) and [NOTICE](NOTICE).

Copyright © 2026 Henok Akriso. All rights reserved. Developer / Project Alias: Sergio — Founder of Halziz. "TerraChain" and "ARWE" are trademarks of the ARWE project; see the license for trademark terms.