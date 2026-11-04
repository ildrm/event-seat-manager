# Current implementation decisions — 2.0.0

| Area | Decision | Practical limit |
|---|---|---|
| Rename | change plugin folder, main file and text domain; preserve PESM internal identity | old copy must be deactivated before activation |
| Versioning | product 2.0.0, schema19 | database version is not a release label |
| Localization | WordPress locale, English/Persian catalogs, static literals only | user-entered content is not translated |
| Time | UTC storage and server expiry; Tehran display, Jalali in Persian | management forms state their time zone explicitly |
| Inventory | SQL row locks, guarded counters and unique keys | deadlock/crash recovery needs broader load acceptance |
| Financial orders | WooCommerce CRUD with immutable price/currency/precision snapshots | standard Checkout Blocks and automatic gateway refunds incomplete |
| Payment mismatch | stop issuance and require manual review | no automatic bank settlement/reversal |
| Tickets | signed opaque QR, online validation and unique check-in | native ticket PDF and offline validation incomplete |
| Roles | capabilities plus center assignment | independent event-level assignment remains incomplete |
| Experimental features | default disabled; documented opt-in | opt-in is not production acceptance |
| Removal | retain all product data | privacy erasure/retention controls need further work |
| Packaging | deterministic ZIP allowlist and checksum | release acceptance is assessed separately from the build |

[Release evidence](RELEASE-STATUS.md) controls readiness claims. Historical release entries have been cleared at the product owner's request.
