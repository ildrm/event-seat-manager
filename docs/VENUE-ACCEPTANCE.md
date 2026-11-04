# Venue designer verification — 4 November 2026

The current product version is **2.0.0**. The implementation is available for review and installation. Deployment latency
acceptance has **not passed**. New `allocated` session creation remains disabled;
existing reservations, orders and ticket access continue to function.

The 2.0.0 release updates version metadata, admin labels and generated translation headers. The source checks were rerun for this version. Recorded integration/load reports and package hashes retain their original 1.0.0 provenance.

This report supersedes older schema-18 counts for the venue work. Historical QA
documents remain evidence for their original scope, rather than fresh v2 results.

## Recorded checks

| Evidence | Result and scope |
|---|---|
| [2.0.0 release verification](qa/release-2.0.0-results.json) | 256 passing offline checks and consistent plugin, block, translation and archive version metadata |
| [Source verification](qa/venue-verification-results.json) | 256 passing isolated regression/syntax/catalog checks; generated assets also pass the build freshness check |
| [Legacy integration](qa/venue-legacy-results.json) | 55 real WordPress/WooCommerce scenarios; schema 19, CPT order storage; four concurrent upgrade workers and four recovery assertions |
| [Venue storage and jobs](qa/venue-generator-results.json) | 114 real database assertions, streamed round trip, replay receipts, immutable publication and a fresh 150,000-place fixture |
| [Allocation integration](qa/venue-allocation-results.json) | 66 scenarios covering pricing, currency mismatch, immutable holds, credential exclusivity, both group-entry policies, retries, refunds, equal-price moves and cross-mode buyer limits |
| [REST and integration wiring](qa/venue-adapter-results.json) | 24 assertions using registered routes, permissions, canonical prices, list filters, cart mounting, legacy guards and reporting |
| [Group admission races](qa/venue-race-results.json) | 105 verification assertions across 42 separate worker attempts: shared scans, identical-key replay and paid cancellation versus entry |
| [Packaged upgrade](qa/venue-package-results.json) | 33 baseline and 131 post-upgrade assertions from an actual ZIP install over schema 18; original row and financial hashes preserved |
| [HPOS allocation](qa/venue-hpos-results.json) | All 66 allocation scenarios passed against the installed ZIP with authoritative WooCommerce HPOS |

The generator regressions exercise all 50 presets, exact counts, unique identities,
ordered aisle topology, partial groups, geometry bounds, spacing extremes, physical
chair/object collisions and streamed interchange. The nine legacy generators remain.
The final large fixture used 90 tiles, each bounded to 2,000 places; its measured
generation/database work took 7.397 seconds and peak PHP memory was 178.81 MiB,
including WordPress/WooCommerce. Provision at least 256 MiB per PHP job worker and
measure the actual deployment rather than assuming the local measurement is a limit.

UI regressions execute the actual application methods with controlled asynchronous
responses and form doubles. They cover attendee/QR-choice retention through renewal,
Refresh and browser restoration, group location, geometry cache/request bounds,
physical keyboard navigation, and 3D scope changes. The configuration gallery and
generation controls were also exercised in Chrome. A full physical mobile-camera,
screen-reader and browser/device matrix remains deployment acceptance work; source
tests do not substitute for that matrix.

## Load run and creation gate

The [recorded burst](qa/venue-load-results.json) used a 150,000-place fixture and
1,000 simultaneous HTTP purchase attempts, two local PHP replicas, shared InnoDB,
shared database-backed WooCommerce sessions and a separate CLI job runner. Twenty
authorized operators used the same loopback address. Hardware and worker settings
are embedded in the report.

- 1,000 holds and 1,000 free checkouts succeeded.
- Fifty contenders for one place produced one winner; forty whole-group/member
  contenders also produced one winner.
- SQL checks found zero oversells, duplicate issuance, unbacked sold places or
  inconsistent admission lines. Gate replay did not consume an extra admission.
- Gate p95 was **302.73 ms**, within the 1,000 ms target.
- Hold p95 was **11,881.56 ms**, above the 2,000 ms target.
- Scoped availability p95 was **10,776.74 ms**, above the 500 ms target.

The burst report has `passed=false`. Low-volume [diagnostics](qa/venue-profile-results.json)
measured warm HTTP holds at 39.83 ms p95 and scoped availability at 45.08 ms p95;
these sequential measurements do not qualify as load acceptance. The local burst
measurements point to the one-host PHP bootstrap/worker queue profile as the latency bottleneck. A production
PHP-FPM deployment with replicated workers, shared session storage and a dedicated
runner must repeat the burst and demonstrate all targets before recording acceptance.

Use the harness and commands in [the venue guide](VENUE-DESIGNER.md). Do not change
the failed report to enable creation. The test-only bypass is restricted to explicit
WP-CLI fixture processes and is not an HTTP setting.

No real payment gateway, external message delivery or production deployment was
used. Native Checkout Blocks, multi-session commercial orders, tax expansion,
native bilingual PDFs and price-difference transfers remain outside this change.

The installed 1.0.0 candidate and its 1.0.0 release used identical runtime files,
verified by a per-file SHA256 aggregate in the package report. That recorded hash
describes the earlier package. The 2.0.0 archive includes the version metadata and
translation updates plus these completed QA reports. Packaging excludes tests, source modules, Node modules,
source maps and local environment files.
