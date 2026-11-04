# Acceptance checklist — 1.0.0

Executed items are bounded by [recorded integration versions](INTEGRATION-RESULTS.md). An item is not a certificate for every deployment or every original requirement.

- [x] Fresh WordPress activation creates37InnoDB tables and schema18.
- [x] A real15×60session creates900inventory records; both900-seat fixture geometries pass standalone checks.
- [x] Guest free checkout creates one WooCommerce order and distinct valid ticket QRs; replay is idempotent.
- [x] An unpaid or mismatched paid order cannot issue tickets; valid payment hook issues once.
- [x] A late payment preserves a replacement seat owner and enters manual review.
- [x] Wrong bearer/center access is rejected; private guest REST and anonymous admin denial are exercised.
- [x] Twenty independent processes accept one owner for a seat, three for capacity3and one gate admission per ticket.
- [x] Twenty concurrent schema17→18workers restore missing empty fixture tables and retain tickets under an advisory lock.
- [x] Guest recovery OTP counters/replay, fully discounted issuance, equal-price seat transfer and free cancellation are exercised.
- [x] Expired numbered leases appear available in reports without cron.
- [x] Representative CPT and HPOS orders use authoritative stores and preserve issuance/inventory mappings.
- [x] Deactivation clears scheduled jobs and retains all37tables; reactivation restores scheduling/schema.
- [x] The generated ZIP installs in a separate clean WordPress site; its runtime passes the55-check integration suite.
- [x] English/Persian rendering, keyboard selection and guest hold/release are checked in native Chrome.
- [ ] Full external payment gateway and refund settlement, tax/partial-refund combinations.
- [ ] Standard StoreAPI/Cart/CheckoutBlocks and one commercial multi-session checkout.
- [ ] Native bilingual ticket PDF, cross-price settlement and independent event delegation.
- [ ] Physical HTTPSmobile camera, assistive technologies, page-builder/theme matrix.
- [ ] Real SMTP/Melipayamak delivery, configurable privacy lifecycle and all historic upgrades.
- [ ]100+buyers, forced worker termination, crash-after-commit and production load/recovery acceptance.

A checked core flow does not mark all114broad product requirements complete. Outstanding work is tracked in [RELEASE-STATUS.md](RELEASE-STATUS.md) and [REQUIREMENTS.md](REQUIREMENTS.md).
