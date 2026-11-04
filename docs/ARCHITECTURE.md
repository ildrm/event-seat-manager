# Architecture — event-seat-manager 1.0.0

The plugin is a WordPress modular monolith. The bootstrap registers lifecycle hooks and autoloads `PESM\` classes. The public plugin slug and text domain are `event-seat-manager`; existing `pesm_` tables, capabilities, options, routes and shortcodes remain stable to retain data.

| Boundary | Responsibility | Main implementations |
|---|---|---|
| WordPress | lifecycle, permissions, REST, UI | bootstrap, `Admin/Pages`, `Frontend/Shortcodes`, `Http/RestController` |
| Layout | versioned geometry, drafts, objects, print | `HallService`, `LayoutVersionService`, `DraftSaveService`, `LayoutDocument` |
| Booking | inventory, holds, expiry, adjacency | `HoldService`, `CapacityService`, `SeatingRules`, maintenance services |
| Financial adapter | dedicated guest orders, immutable amount snapshots | `CheckoutService`, `FreeAdmissionService`, `WooCommerceBridge`, `CartSeatManager` |
| Tickets and admission | issued credentials, ownership, cancellation, online check-in | `TicketService`, `CheckinService`, `AdmissionGateService` |
| Recovery and notifications | guest verification, encrypted queue, backoff | `GuestAccessService`, `NotificationQueue`, `PayloadCipher` |
| Reports | scoped inventory/attendance/analytics, CSV/XLSX | `AttendanceReport`, `SeatStatusReport`, `SalesInsights` |
| Locale | English/Persian literal catalogs, script runtime, dates and direction | `Support/I18n`, `languages/`, `assets/js/i18n.js` |

## Sources of truth

WooCommerce CRUD owns financial orders and gateway status. Plugin tables own seat/capacity inventory and admission. Each numbered seat has one inventory row per session. General admission maintains capacity counters and separate holds/tickets. Unique keys enforce ticket and check-in identity. QRs contain signed opaque identity rather than customer data.

A layout used by a session stays attached to that version; publishing another version changes the default for future sessions. JSON drafts and independent non-seat objects do not create sellable inventory.

## Transactions

Booking writers lock the session, then relevant hold/capacity/inventory records, using deterministic seat ordering. The database clock is read after acquiring the session lock. Expiry is evaluated on requests as well as during maintenance. Temporary holds are not evidence of financial payment.

Order creation crosses a WooCommerce/API boundary and cannot share one atomic SQL transaction with all gateway operations. Checkout snapshots price, currency and decimal precision. Issuance requires matching order ownership, admissible status, payment evidence and exact amount/currency equality. An ambiguous or late payment becomes a review case; it does not seize a seat sold to someone else. External gateway settlement and crash recovery still need dedicated operational acceptance.

Admission locks the ticket and records a unique check-in. Repeat scans cannot create a second accepted admission. Scope checks are repeated on authoritative server data. Center isolation is implemented; independent event-level delegation within one center remains a product gap.

## Presentation and acceptance

PHP and JavaScript translate static source strings only. User-entered titles, names, JSON IDs and opaque credentials remain unchanged. Locale follows WordPress; dates are stored in UTC and displayed in Tehran time, with Jalali presentation in Persian and Gregorian presentation in English.

The dedicated order flow is distinct from standard WooCommerce Store API/Checkout Blocks. A shared multi-session commercial bundle, native ticket PDF and cross-price seat settlement remain incomplete. See [requirements](REQUIREMENTS.md), [current verification](INTEGRATION-RESULTS.md) and [release status](RELEASE-STATUS.md) for evidence and limits.
