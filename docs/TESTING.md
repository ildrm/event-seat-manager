# Verification workflow — 2.0.0

Run independent regressions and PHP/JavaScript syntax checks from the repository root:

```sh
python3 tests/run.py --report dist/verification.json
```

Each PHP test runs in a separate process because fixtures define WordPress/WooCommerce stubs. These tests cover policy, expiry, idempotency, ownership, financial snapshots, scope, layout geometry, localization, reports and controlled failure paths. They do not emulate a gateway, browser or transactional database.

## Real integration

Use a disposable local WordPress installation with WooCommerce and InnoDB. Never run fixture scripts on a live sales site. Activate the plugin, then run:

```sh
PESM_RUN_INTEGRATION=1 wp --path=/path/to/disposable-wordpress eval-file /path/to/event-seat-manager/tests/integration-wp.php
```

The integration suite creates test centers, 900-seat hall data, sessions, orders and tickets. Read the script before executing; it deliberately changes test order totals/statuses and schema-version options to exercise failure paths. Further concurrency commands and exact versions/results are recorded in [integration results](INTEGRATION-RESULTS.md).

For a local HTTP environment, `compose.staging.yaml` mounts the plugin under the new slug. The example container stack is separate from the host-based integration environment and must be validated in its own runtime. Use test credentials only.

## Localization and browser checks

Run the catalog generation/validation tool in `tools/`, then the locale tests included by `tests/run.py`. Verify both languages in a real browser with an English site and a Persian site or administrator profile. Test 900-seat focus, arrow navigation, zoom, expiry, connection errors, ticket retrieval, camera/manual admission and mobile viewport behavior. Browser evidence belongs in [frontend review](REVIEW-FRONTEND.md).

## Package

```sh
python3 tools/build-release.py
```

The build checks the public slug, version, empty release history, block metadata and runtime file allowlist. It creates a deterministic ZIP plus SHA-256. Archive creation is separate from operational release acceptance. Install the actual ZIP into a clean site as the final packaging check.

CI is configured for PHP 8.1/8.3/8.5 and JavaScript syntax, but a local run does not imply GitHub Actions has executed. External payment gateway, SMTP/SMS, all historic migrations, production load, mobile camera and end-to-end Store API/Checkout Blocks remain separate acceptance work.
