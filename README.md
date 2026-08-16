# TerraChain — Land & Procurement Transparency Infrastructure

TerraChain is an ARWE project providing a secure, transparent and auditable
digital infrastructure for **land administration and government procurement
workflows** in Ethiopia.

## Purpose

TerraChain prioritizes:

- Data integrity
- Transparency and accountability
- Auditability
- Secure records and controlled access
- Document verification
- Workflow tracking
- Tamper-evident records
- Ethiopian localization
- Long-term maintainability

## Technology Stack

| Technology | Primary Responsibility |
|---|---|
| C | Core systems, cryptographic utilities, integrity services, synchronization |
| PHP | Backend, APIs, business logic, authentication, workflows |
| HTML / CSS / Pure JavaScript | Interface structure, design and client-side behavior |
| MySQL/MariaDB | Persistent data |

The core application avoids Python, Java, Node.js, React, Laravel, Django and
other major frameworks.

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
Integrity / Security /
Synchronization /
System Services
```

## Repository Layout

TerraChain currently contains two companion subprojects:

```
achain/         Land administration app (api: auth, contracts, land,
                parcels, procurement, tenders, verification)
terr achain/    Procurement/API-focused app (api: routes, index)
```

Both share the same stack and are developed under the TerraChain umbrella:

```
api/        REST API (v1)
app/        PHP application (controllers, models, services, security)
c/          C components
config/     Configuration
database/   Schema and migrations
public/     Web root
storage/    Storage
tests/      Test suites
```

## License

MIT — see [LICENSE](LICENSE).