# Database — event-seat-manager 2.0.0

The product version is **2.0.0**; the independent database schema version is **19**. Do not reset `pesm_schema_version` to the product version. Tables use `{$wpdb->prefix}pesm_`, preserving existing data after the plugin rename.

`Migrator::run()` applies `dbDelta()` definitions, then verifies table existence, InnoDB engines, required unique indexes and critical columns. It records `pesm_schema_error` on failure and advances the schema version only after verification. Bootstrap stops booking/ticket route registration when the schema is incomplete. Migration rollback requires restoring a backup; uninstall retains data.

| Tables | Purpose |
|---|---|
| `centers`, `center_users` | organizational scope and user assignment |
| `halls`, `layout_versions`, `seats`, `layout_objects` | versioned hall geometry and independent decorative/structural objects |
| `events`, `sessions` | event details, publication, sales windows, mode, price and seating rules |
| `session_seats`, `holds`, `hold_items`, `hold_mutations` | numbered inventory, leases, selected seats and mutation idempotency |
| `session_capacity`, `capacity_holds` | general admission counters and leases |
| `phone_locks` | per-event serialized buyer-limit checks |
| `order_links`, `capacity_order_links` | hold/order ownership and hashed guest access |
| `tickets`, `capacity_tickets` | issued ticket identity, attendee snapshot, QR version and status |
| `checkins`, `capacity_checkins` | unique accepted admission per ticket |
| `ticket_attendee_changes`, `ticket_seat_transfers` | attendee and equal-price seat change history |
| `ticket_owners`, `ticket_ownership_transfers` | optional verified ownership transfer |
| `ticket_bundles`, `ticket_bundle_items` | optional grouping of existing multi-session tickets |
| `guest_recovery_requests`, `guest_access_tokens` | expiring guest recovery proof and access credentials |
| `notification_jobs` | encrypted payload queue, deduplication and retries |
| `event_changes`, `event_change_cases`, `refund_cases` | event follow-up and controlled manual refund cases |
| `discount_rules`, `discount_claims` | optional targeted coupons and reserved redemption quota |
| `rate_limits`, `audit_logs` | bounded request counters and non-PII audit events |
| `layout_save_receipts` | committed legacy draft-update replay receipts |
| `venue_layouts`, `venue_sections`, `venue_places`, `venue_groups` | v2 metadata and explicit ordered topology, backed by canonical seat identities |
| `venue_tiles`, `venue_objects`, `venue_commands` | bounded geometry, venue objects and revisioned command receipts |
| `venue_jobs`, `venue_import_chunks` | resumable work and private streamed import staging |
| `allocation_sessions`, `allocation_sections` | session policies, pricing and section revisions |
| `allocation_holds`, `allocation_lines`, `allocation_orders` | frozen selection/financial evidence and WooCommerce ownership |
| `allocation_tickets`, `allocation_checkins`, `allocation_requests` | individual admission slots, atomic consumption and replay receipts |

## Invariants

- `session_seats(session_id, seat_id)` is unique. Inventory is session-specific, even when sessions share a hall layout.
- Hold/mutation request hashes and ticket identities have unique indexes. Check-ins enforce one accepted entry per ticket.
- General admission increments capacity only through a guarded atomic update under a session/capacity lock. Pending financial cases are retained conservatively.
- Raw bearer credentials are not stored as access-token values. Guest access uses hashes; notification payloads are encrypted.
- Financial order data is accessed through WooCommerce CRUD. Order amount, currency and decimal snapshots are order metadata; do not alter them while settling a reservation.
- Business timestamps are UTC. Lease/check-in decisions use the database clock. Display conversion does not change stored timestamps.
- Allocated seats and whole groups share `session_seats`. Group purchases lock and reserve every explicit member; there is no separate group capacity counter.
- `layout_versions.format` defaults existing rows to `pesm.layout.v1`. Schema 19 adds v2 data without regenerating historical layouts or rebinding existing sessions.
- Shared group credentials and usable individual credentials cannot coexist for one booking line. Each consumed member slot has one unique check-in.

## Upgrade and recovery

Back up before activation or upgrade. Deactivate the old plugin file before activating `event-seat-manager/event-seat-manager.php`; keep the same WordPress table prefix and data. Pre-snapshot pending seated orders fail closed and require manual reconciliation. Already-issued tickets remain intact.

Fresh installation and selected schema-retention checks are documented in [integration results](INTEGRATION-RESULTS.md). Those checks do not prove every historical database revision or interrupted migration path. Full upgrade fixtures, financial disaster recovery and configurable PII retention/erasure need further acceptance.

Current schema-19 evidence and deployment limits are recorded in [venue acceptance](VENUE-ACCEPTANCE.md). The migration now expects 55 plugin tables, all InnoDB. New allocated-session creation remains gated independently from existing reservations and ticket access.
