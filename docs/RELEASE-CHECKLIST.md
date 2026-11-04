# Release checklist — event-seat-manager 1.0.0

- [x] Public slug, main file, text domain, plugin and block versions are consistent.
- [x] Release history is empty; historical progress/release logs removed.
- [x] Internal identifiers and existing booking data are preserved across the rename.
- [x] Duplicate old/new plugin loading and failed-schema activation are guarded.
- [x] PHP/JavaScript checks, catalog coverage, behavioral regressions and real integration evidence exist.
- [x] English/Persian catalogs, directions, roles, reports and browser interfaces reviewed.
- [x] CPT/HPOS paths tested; unsupported native CheckoutBlocks explicitly declared.
- [x] Deterministic installable ZIP and SHA-256 created from an explicit allowlist.
- [x] Actual ZIP installed into a separate clean local WordPress database and tested.
- [ ] Complete remaining original product functionality, including native PDF and commercial multi-session checkout.
- [ ] Complete external provider/gateway settlement, target-device, broad load and privacy acceptance.
- [ ] Execute the full supported PHP/core/WooCommerce compatibility matrix and remote CI.

**Disposition:** versioned package for review and acceptance; the complete original commercial product has not passed final operational acceptance. See [RELEASE-STATUS.md](RELEASE-STATUS.md).
