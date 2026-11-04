# Specialist review responsibilities — 1.0.0

These are engineering review responsibilities exercised by the agent team, not a claim that twenty independent human specialists certified the product. The coordinator combines product, architecture and release decisions; parallel reviewers handle backend/security, frontend/design and localization. Evidence is bounded by the executed checks.

| Role | Decision owner / deliverable | Acceptance evidence or unresolved work |
|---|---|---|
| R01 Product owner | coordinator: preserve scope and expose incomplete requirements | all114 F-codes retained; release status is explicit |
| R02 Event analyst | coordinator/backend: seated, general admission, sessions and change policies | core free/paid/session scenarios; commercial bundles incomplete |
| R03 Architect | coordinator: boundaries and data-preserving rename | architecture, stable internal identifiers, reviewed bootstrap |
| R04 WordPress | backend/coordinator: activation, migration, hooks, capability checks | real WP installation; broader version/network acceptance limited |
| R05 WooCommerce | backend: payment proof and CRUD order behavior | real free/paid/mismatch/replay paths; gateway and Blocks incomplete |
| R06 Database | backend: schema, indexes and retention | InnoDB installation and scoped migration checks |
| R07 Concurrency | backend: single ownership and capacity/check-in invariants | twenty-process seated, capacity and gate races |
| R08 API/backend | backend: validation, no-store, credentials and failure status | guest REST and negative authorization regressions |
| R09 Frontend | frontend reviewer: public booking scripts and REST URLs | literal localization, URL regression and browser checks |
| R10 Layout engineer | frontend/backend:900-seat geometry and interaction | fixture/layout tests; focus preservation; broader visual editing acceptance pending |
| R11 UX/mobile | frontend reviewer: direction, focus, touch/zoom and error feedback | EN/FA rendering and keyboard checks; full mobile/device audit pending |
| R12 Ticket/QR/PDF | backend/frontend: signed credentials and print path | QR identity and issuance checks; native PDF incomplete |
| R13 Admission | backend: unique authorized entry | repeated and concurrent scan tests; HTTPS device camera pending |
| R14 Integrations | backend: optional adapters, queue and recovery | policy/queue tests; provider delivery and external gateway pending |
| R15 AppSec/privacy | backend/coordinator: scope, price tampering, expiry and PII boundaries | negative core integration tests; privacy lifecycle and independent audit incomplete |
| R16 QA | all reviewers: repeatable regressions and actual evidence | runner, integration and browser reports |
| R17 Performance/SRE | backend/frontend: bounded map/race work |900-seat rendering and20-process concurrency;100+buyer/crash/load acceptance pending |
| R18 DevOps/release | coordinator: metadata, deterministic ZIP, checksum and install | package build/check; CI configured, remote execution unclaimed |
| R19 Analytics | backend: scope and expired-inventory correctness | report regression and real DB expiry checks; financial BI incomplete |
| R20 Documentation/a11y | coordinator/frontend: bilingual guides and keyboard access | current guides, locale/output tests; full assistive technology audit pending |

## Module review ownership

| Module | Design/implementation | Review | Verification |
|---|---|---|---|
| Lifecycle, packaging and docs | coordinator | backend/localization | package metadata, activation and install checks |
| Booking, payment, schema and reports | backend | coordinator | policy/regression and real InnoDB/WooCommerce suite |
| UI, layout interaction and browser scripts | frontend | localization/coordinator | locale, REST URL, keyboard and browser checks |
| Catalogs and language runtime | localization | frontend/coordinator | source coverage, compiled catalogs and both locales |

Unresolved security, financial and acceptance requirements remain release blockers. No role label substitutes for missing execution evidence.
