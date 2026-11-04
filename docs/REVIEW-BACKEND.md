# Backend review for 1.0.0

Review scope: the existing reservation, checkout, ticket issuance, guest recovery,
gate access, reporting and migration implementation. This is a bounded source
review with executable regressions; it does not certify every requirement in the
original product prompt.

| Responsibility | Decision owner | Deliverable | Acceptance evidence |
| --- | --- | --- | --- |
| R03/R04/R08 architecture, WordPress, API | Backend reviewer | Trace checkout and issuance callers; retain existing data identifiers | Graph plus source inspection; existing regressions |
| R05/R06/R07 payments, database, concurrency | Backend reviewer | Verify paid amount, immutable price agreement and lease deadline | New seated issuance behavior tests; live integration is recorded separately |
| R12/R13 tickets and entry | Backend reviewer | Prevent issuance for unpaid, inactive or mismatched orders | Payment proof, identity, expiry and callback replay cases |
| R15 privacy and authorization | Backend reviewer | Inspect bearer access, recovery, center scope and QR validation | Existing recovery/report/access tests; negative tests |
| R16/R19 QA and reporting | Backend reviewer | Exercise rollback and report semantics | Existing test suite and targeted regressions |

## Corrected defects

1. Seated ticket issuance checked `is_paid()` but did not compare the WooCommerce
   amount with the checkout agreement. Checkout now saves unit price, net total,
   currency and decimal precision as server-generated order metadata. Issuance
   validates these values, quantity and discount before consuming inventory.
   Currency and decimal settings changed after checkout cannot silently alter the
   amount accepted. A payment date is required for paid issuance.
2. Issuance fetched the database clock before waiting for the session lock. The
   timestamp is now read after the session, hold and order-link locks, preventing
   a delayed worker from accepting an expired payment lease.
3. Issuance additionally verifies `hold.order_id`, rejects inactive orders and
   allows a valid fully discounted zero-total checkout. It no longer mistakes a
   nonzero session base price for proof that a fully discounted order is unpaid.
4. Checkout price validation now has the same 100,000,000,000-minor-unit bound as
   the pricing engine, preventing unchecked integer overflow in total arithmetic.
5. Seated attendance and seat-status reports now classify expired `held` and
   `payment_pending` inventory as available, matching the booking engine without
   depending on cron. General-admission pending capacity retains its existing
   conservative financial reconciliation policy.
6. Schema migration now uses a database advisory lock keyed by database and
   table prefix, with release in `finally`. Waiting upgrade workers reload the
   schema marker after locking and skip an already completed upgrade. Explicit
   same-version activation still validates the schema. Twenty real concurrent
   upgrade workers recreated the two new tables and retained issued tickets.

## Upgrade behavior

Existing tables, options, roles, capabilities, shortcodes and API namespace keep
their `pesm` identifiers. Previously issued tickets remain available. An unissued
engineering-preview seated order without the new price snapshot fails closed
with `price_mismatch`; finance must verify its original amount and inventory
before any controlled reconciliation. Do not invent snapshots from a browser
request or issue an expired/reassigned seat.

## Validation

- All existing PHP test scripts, except the WordPress seeder and fixture generator,
  passed before these edits under PHP 8.5.8.
- `php tests/seated-payment-fake-db.php`: 23 passing behavioral assertions for
  payment proof, under/overpayment, currency mismatch, missing snapshot,
  order/hold mismatch, lock-wait expiry, rollback, price changes, replay,
  full discount and overflow rejection.
- `php tests/checkout-ticket-unit.php`: 40 passing assertions.
- `php tests/attendee-manifest-unit.php`: 31 passing assertions.
- `php tests/seat-status-report-fake-db.php`: 13 passing assertions.
- Changed PHP source files passed syntax checks.

Final repository-wide validation after parallel localization and live database
results belong in the release QA report. Fake databases do not establish real
InnoDB isolation, WooCommerce compatibility, gateway behavior or browser behavior.
Real WordPress/MariaDB, 20-process races, CPT/HPOS and retention results are now
recorded in [INTEGRATION-RESULTS.md](INTEGRATION-RESULTS.md); these establish the
specific tested paths, with external gateways and providers still excluded.

## Material product gaps requiring explicit scope or further implementation

Source and the existing requirements matrix show that native downloadable ticket
PDFs, cross-price seat transfers, complete WooCommerce Checkout Blocks/Store API
integration and complete commercial multisession bundle sales are not complete.
The current center assignment model does not independently assign managers to
individual events within a center. Gateway refund settlement remains an explicit
finance workflow. No source review can substitute for actual provider, gateway,
concurrent database, device-camera and email delivery acceptance tests.

Experimental features should retain their existing opt-in flags until their
acceptance evidence is available. Package version `1.0.0` by itself does not prove
completion of the entire original specification.
