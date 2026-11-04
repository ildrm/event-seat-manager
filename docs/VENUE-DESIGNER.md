# Venue designer and allocated reservations

The v2 venue designer adds 50 parameterized configurations across cinema, theater,
conference, restaurant, fashion, sports hall and stadium categories. Legacy layouts,
sessions, orders and tickets retain their original identities and behavior.

The maximum layout capacity is 150,000 member places. This is a storage and geometry
limit, not a claim of 150,000 simultaneous customers. The current local 1,000-client
run passed inventory correctness but failed the hold and availability latency targets.
New allocated session creation therefore remains gated. Existing sessions and issued
tickets continue to work when creation is disabled.

## Administrator workflow

Open **Events and Seats → Venue designer**. Create the center and hall in the existing
dashboard first, then select the hall in the designer.

1. Filter the configuration gallery by venue type or name. Thumbnails are generated
   from the actual recipe geometry.
2. Choose **Exact seat count** or **Advanced arrangement**. Exact capacity can end with
   partial rows or explicitly smaller tables. Advanced capacity derives from rows,
   columns, sections, tables and tiers. Fixed group-size constraints reject infeasible
   counts instead of inventing spare places.
3. Generate an editable draft. Processing is resumable; progress survives individual
   HTTP requests and worker restarts. **Resume processing** advances one bounded batch
   if a background runner is unavailable.
4. Select a level and section. Use Map or List, search codes, edit numeric properties,
   assign categories, move complete groups, align selections, and undo/redo. Changing
   a table's category updates all its member places.
5. Publish the validated layout for future sessions. Published geometry is immutable.
   Existing sessions remain attached to their original version.
6. After deployment acceptance passes, create an allocated session. Inventory must
   finish provisioning before session publication. Configure prices, group sale modes
   and admission policies before opening sales.

All tables and boxes have explicit member places. A place belongs to at most one
group; nested and overlapping groups are unsupported. Group capacity is counted once.
Administrators with site settings permission can change the per-phone, per-event
purchase limit from 1 to 200; its existing default remains 20. Whole groups exceeding
that limit must use individual-only sales or an appropriate site limit before the
session is published.

## Reservation, pricing and admission behavior

Seat, whole-table and whole-box sales use the same session inventory rows. Buying a
whole group requires every member place to be available. Selecting a group and one
of its member seats in the same purchase is rejected.

Individual-place pricing resolves section/category override → category → section →
session default. A whole group uses its explicit total or the sum of its member prices.
Prices are integer minor units. The server freezes the quote for the hold lifetime;
replacement reprices the complete selection. Live price changes affect future quotes.
Admission-only setting updates preserve existing pricing maps.

Each whole-group purchase has one resolved ticket mode: individual guest tickets or
one shared group ticket. A session may require either or let the buyer choose. A
shared ticket never exposes usable individual member QR credentials.

Shared entry policy is snapshotted on purchase:

- **Together:** one successful scan consumes the entire party once.
- **Incremental:** the operator confirms a positive quantity. The server atomically
  consumes that quantity and rejects counts above the remaining capacity.

Retrying the same scan request returns its recorded result. A new request cannot
consume an already used admission. Credentials contain signed opaque identity.

Whole-group cancellation/refund/reallocation requires every admission to be unused.
Free cancellation releases the entire group. Paid cancellation starts manual refund
review; verified WooCommerce refund evidence is required before release. Reallocation
requires a complete destination group of equal capacity, currency, precision and price,
and rotates the old QR credentials. Price-difference settlement remains separate work.

The dedicated WooCommerce cart is a review/edit surface. Inventory stays in plugin
tables and private hold tokens stay in the WooCommerce session. Standard WooCommerce
checkout and Store API checkout remain blocked for these technical cart products.

## Interfaces and bounds

Use `rest_url('pesm/v2/')`; do not hardcode pretty-permalink URLs. Public reads require
a published event and allocated session. Administrator routes require the appropriate
WordPress capability, REST nonce, and center ownership.

| Interface | Contract |
|---|---|
| `GET presets`, `GET presets/{preset}/preview` | Catalog and small actual-geometry previews |
| `POST admin/jobs`, `GET admin/jobs/{id}` | Generation and job status |
| `POST admin/jobs/{id}/run` / `retry` | One bounded batch / retry a failed job |
| `PATCH admin/layouts/{id}/commands` | Revision, request key and at most 500 operations |
| `POST admin/layouts/{id}/publish` | Validation/publication job |
| `POST admin/import` | Multipart `file` plus `hall_id`; private staged JSON Lines |
| `GET admin/layouts/{id}/export` / `print` | Streamed interchange / bounded printable geometry |
| `POST admin/sessions` | Event, hall, UTC dates, default minor-unit price and settings |
| `GET/PATCH admin/sessions/{id}/config` | Pricing and admission configuration |
| `POST admin/sessions/{id}/publish` | Ready inventory and acceptance required |
| `GET sessions/{id}/layout` | Overview, sections, price ranges and tile manifest; no seats |
| `GET sessions/{id}/tiles/{tile}` | At most 2,000 member places |
| `GET sessions/{id}/units` / `locate` | Filtered keyset list / exact code location |
| `GET sessions/{id}/availability` | Scoped snapshots, section revisions and next expiry |
| `POST sessions/{id}/quote` | Canonical units; server-derived prices and membership |
| `GET/POST/PATCH/DELETE sessions/{id}/holds` | View, acquire, replace/renew and release |
| `POST sessions/{id}/checkout` | Dedicated one-session order |
| `GET tickets`, `POST guest-access/request` / `verify` | Private retrieval and bounded email OTP recovery |
| `POST cart/attach`, `GET cart/preview`, `POST cart/replace` / `complete` | Private WooCommerce session ownership |
| `GET gate/lookup`, `POST gate/check-in` / `manual-check-in` | Authorized individual/group admission |
| `GET admin/sessions/{id}/lines` | Bounded booking operations list |
| `POST admin/lines/{id}/cancel` / `confirm-refund` / `deny-refund` / `reallocate` | Whole-line administrative actions |

Units are `{ "type": "seat", "id": 123 }` or
`{ "type": "group", "id": "<group-code>" }`. `table` and `box` type aliases are
resolved against authoritative group definitions. Clients never supply prices or
authoritative expanded member lists.

Use `X-PESM-Hold-Token`, `X-PESM-Guest-Token` and `X-PESM-Cart-Nonce` headers for their
respective private operations. Never put bearer credentials in URLs. Administrator
and operator cookie authentication also requires `X-WP-Nonce`.

Lists default to 50 items and cap at 100. Active detailed geometry is limited to 5,000
places in the client, with four concurrent tile requests. Large views use section
overviews until sufficiently zoomed. Availability remains a bounded snapshot with
revision/expiry information; clients recover with a fresh scoped snapshot rather than
assuming an incomplete delta history.

`pesm.layout.v2` uses millimetres, explicit ordered blocks, rotations, elevation,
categories, accessibility flags and member groups. JSON Lines interchange caps total
decoded size at 128 MiB and individual records at 1 MiB. Import chunks are private
database records, so jobs do not depend on a particular application server's local disk.
Web-server/PHP upload limits must also permit the intended file size. The v1 2 MiB
JSON contract and legacy generators remain unchanged.

New geometry is consumed by the editor, public map, cart, print and optional schematic
Three.js preview. The 3D module is loaded only on request, uses instancing, disposes
resources, and falls back to the complete 2D/list experience.

## Background workers and deployment acceptance

Schema 19 is additive. Migration verifies InnoDB, tables and critical unique indexes.
No historical layout is regenerated. Resumable provisioning uses bounded batches;
unfinished inventory cannot be published for sale.

Jobs use initialized Action Scheduler when available, with a WP-Cron fallback. Run a
dedicated server-triggered CLI worker for substantial venues:

```sh
wp pesm venue-jobs --limit=1000
```

Request-time database timestamps and resource locks enforce inventory expiry; cron
timing never grants the right to oversell.

Creation is controlled by validated deployment evidence, not a cosmetic setting:

```sh
wp pesm venue-accept --report=/private/path/acceptance.json
```

The report must have `passed=true`, `schema=19`, `capacity>=150000`,
`concurrent_purchase_attempts>=1000`, `gate_operators>=20`, `oversells=0`,
`duplicate_issuance=0`, `integration_passed=true`, a nonempty `environment`, and measured
`hold_p95_ms<=2000`, `availability_p95_ms<=500`, `gate_p95_ms<=1000`.
The failed local burst report deliberately does not qualify. Do not edit its result to
open the gate. Re-run the load harness on the intended deployment and record genuine
passing evidence. Disabling creation preserves existing booking and ticket handlers.

## Development and verification

```sh
npm ci
npm run build
node tools/build-assets.mjs --check
python3 tests/run.py
python3 tools/build-release.py
```

Runtime assets are bundled locally. Source modules, dependency directories and source
maps are excluded from the WordPress ZIP; bundled licenses are included.

Real tests create disposable data and must only run in a local nonproduction site:

```sh
PESM_RUN_INTEGRATION=1 wp eval-file tests/integration-allocation.php
PESM_RUN_VENUE_INTEGRATION=1 PESM_VENUE_LARGE=1 wp eval-file tests/integration-venue.php
PESM_RUN_INTEGRATION=1 wp eval-file tests/integration-venue-load-seed.php
python3 tests/venue-load.py --help
```

Current functional, package and load evidence is indexed in
[venue acceptance](VENUE-ACCEPTANCE.md), including the failed burst and diagnostic reports. Gateway settlement, real email delivery, physical
mobile cameras and untested browser/screen-reader combinations are not implied by
the local fixtures. Native Checkout Blocks, multi-session purchases, tax expansion,
native bilingual PDFs and price-difference transfers remain separate roadmap items.
