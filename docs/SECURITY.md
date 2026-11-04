# Security review and boundaries — 1.0.0

The implemented controls and regression evidence are described below. This is not an independent penetration-test certification. [Backend review](REVIEW-BACKEND.md), [integration results](INTEGRATION-RESULTS.md) and [release status](RELEASE-STATUS.md) describe the practical limits.

## Implemented controls

- WordPress capabilities and center assignment are checked server-side. Administrative mutations use WordPress nonces. REST cookie authentication uses the WordPress REST nonce; capabilities and resource ownership still apply separately.
- Public layout/availability responses exclude customer data. Guest tickets require a private bearer credential. Sensitive responses are marked private/no-store. Bearer credentials belong in request headers, never public query strings.
- SQL uses `$wpdb->prepare()` for values, bounded IDs/selections and fixed internal table names. Transactional reservation uses InnoDB row locks, guarded counter updates, deterministic seat order and unique constraints.
- Temporary holds and mutations enforce expiry and idempotency. The database clock is read after relevant blocking locks. A failed schema migration prevents booking and ticket route registration.
- Ticket issuance verifies authoritative order ownership, currency, precision, total, admissible status and payment evidence. Tampered or late payment cannot acquire somebody else's seat. Unresolved financial cases fail closed for manual reconciliation.
- QR payloads are signed opaque identities without PII. Online check-in requires an authorized center operator and a unique ticket entry. Old QR versions can be invalidated by transfer.
- Guest recovery uses expiring verification proof, bounded attempts and hashed access credentials. Notification jobs encrypt payloads, deduplicate delivery and cap retries. SMS credentials are configured server-side.
- CSV exports neutralize spreadsheet formula prefixes; private-data export requires a separate capability. Static localization never processes user-entered data as translation templates.
- Same-origin payment navigation is checked in the browser; backend payment URLs originate from WooCommerce order APIs.

## Threats and incomplete acceptance

| Area | Remaining acceptance or limitation |
|---|---|
| Delegation | independent event-level assignment within a center is incomplete |
| Abuse | distributed hold exhaustion and shared-IP/reverse-proxy deployment need operational rate-limit testing |
| Financial recovery | gateway settlement, full/partial/taxed refunds, worker termination and crash recovery need broader acceptance |
| Database | a repeated current-schema migration is not a complete historical upgrade/rollback test |
| Privacy | configurable PII retention, erasure/export integration and external mail/SMS retention need completion |
| Browser | mobile HTTPS cameras, all target browsers and assistive technologies need broader device testing |
| Supply chain | no claim of completed Plugin Check, PHPCS, dependency CVE audit or independent penetration test |
| Advanced integrations | standard Checkout Blocks/Store API, shared commercial bundles and native ticket PDF remain incomplete |

Use HTTPS for all production booking, recovery and admission pages. Keep access keys out of logs and shared screenshots. WP-Cron is an aid to cleanup and notifications; request-time expiry preserves numbered-seat availability when cron is late. General admission financial ambiguity remains conservatively reserved for review.

Uninstall retains data. Do not delete tables to repair a migration or payment mismatch; preserve evidence and restore a tested backup when necessary.
