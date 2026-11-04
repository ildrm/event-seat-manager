# Remaining product acceptance — 2.0.0

The repository contains a versioned installable package, bilingual runtime and verified core booking/ticket paths. [Release status](RELEASE-STATUS.md) identifies what is proven and what still prevents declaring the complete original product finished.

## Functional completion

1. Standard WooCommerce Cart/Checkout Blocks and Store API integration, multi-session checkout and tax-aware financial reconciliation.
2. Native English/Persian ticket PDF, commercial multi-session bundle purchasing and cross-price seat movement settlement.
3. Independent event-level delegation, configurable privacy retention/erasure and complete recovery of all interrupted external financial operations.
4. Complete optional provider notification/ownership/discount acceptance, richer event discovery and a full Jalali calendar picker where required by the original specification.

## Operational acceptance

- Test an actual supported payment gateway callback and refund settlement, SMTP and Melipayamak with approved test credentials.
- Validate every supported historic schema upgrade and interrupted migration from backup.
- Exercise forced worker termination, repeated callbacks, lock failures, 100+ buyers and production-sized multi-session traffic.
- Test HTTPS camera scanning on target mobile devices, screen readers, theme/page-builder combinations and visual map editing.
- Run CI on the minimum PHP version and the chosen WordPress/WooCommerce support matrix, Plugin Check, coding standards and independent security review.

Do not equate partial feature counts with percentage product completion. The full requirements remain in [REQUIREMENTS.md](REQUIREMENTS.md); optional offline validation cannot promise duplicate prevention across disconnected devices.
