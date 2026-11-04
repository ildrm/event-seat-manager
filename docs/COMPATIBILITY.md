# Compatibility evidence — 1.0.0

Tested on 4 November 2026 in an isolated local installation. This matrix describes observed versions, not every version meeting the declared minimum.

| Component | Executed evidence | Remaining limit |
|---|---|---|
| PHP 8.5.8 | standalone regressions, syntax checks, actual WordPress runtime | PHP 8.1 minimum and 8.3 CI jobs configured; not executed locally |
| Node 26.12.0 | syntax and REST URL regression checks | Node is a developer tool, not a production plugin dependency |
| Python 3.14 | verification, deterministic packaging and process concurrency orchestration | developer tooling only |
| WordPress 7.1.4 | fresh activation, real REST, schema, public UI and per-site lifecycle | broader core version matrix and full multisite acceptance unverified; network activation rejected |
| WooCommerce 11.2.1 | guest/free/paid issuance, actual order hooks, replay and mismatched payment rejection | real gateway callbacks and settlement, full tax behavior and every order customization unverified |
| CPT and HPOS | representative real orders and table/data-store checks in both modes | HPOS declaration follows these tests; not a promise for every WooCommerce version |
| MariaDB 13.0.2 |37 InnoDB tables, unique keys,20-process seat/capacity/gate/migration races | MySQL and other MariaDB versions not executed here; broad load/crash recovery remains |
| Native Chrome |900-seat map, English/Persian output, keyboard, guest hold/release, ticket access denial and administrator UI | physical mobile/HTTPS camera, screen readers and all target browsers remain |
| Gutenberg | registered metadata, localized titles, dynamic callbacks and standalone checks | full editor/theme matrix unverified |
| Elementor | optional registration and localized widget checks | real Elementor installation/theme matrix unverified |
| Email/SMS | policy, encryption, queue, intercepted recovery mail and retries | no real SMTP, Melipayamak credentials or provider delivery acceptance |
| Ticket PDF | browser print path | native bilingual PDF renderer incomplete |
| Cart/Checkout Blocks | dedicated plugin order flow; standard Blocks explicitly incompatible | full Store API/native checkout integration incomplete |

Full integration results: [INTEGRATION-RESULTS.md](INTEGRATION-RESULTS.md). Device and language evidence: [REVIEW-FRONTEND.md](REVIEW-FRONTEND.md), [REVIEW-LOCALIZATION.md](REVIEW-LOCALIZATION.md). The example Docker stack was not the runtime used for these host-based checks.
