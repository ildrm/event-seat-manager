=== event-seat-manager ===
Contributors: pesm-team
Tags: events, seating, tickets, persian, rtl
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: event-seat-manager
Stable tag: 2.0.0

English and Persian event, seating, reservation, WooCommerce ticketing and online admission management.

== Description ==
Manage centers, halls, layout versions, events, sessions, reserved seating and general admission. Includes temporary holds, guest orders, local QR tickets, online admission and reports. Interface follows the WordPress locale in English or Persian.

The venue designer provides 50 parameterized configurations, bounded maps for up to 150,000 places, seat-backed table/box reservations, tiered pricing and shared group admission. New allocated-session creation requires passing deployment acceptance. The recorded local burst passed inventory correctness but missed hold and availability latency targets; creation remains gated. See docs/VENUE-DESIGNER.md and docs/VENUE-ACCEPTANCE.md.

Read docs/RELEASE-STATUS.md before live sales. Some original product requirements remain incomplete; experimental features remain disabled by default. This version number does not certify payment gateway, Checkout Blocks, native ticket PDF or every integration.

== Installation ==
1. Back up the database and files.
2. Deactivate the previous plugin before installing the renamed package.
3. Upload event-seat-manager and activate it on an individual site.
4. Check schema version 19 and the absence of pesm_schema_error.
5. Activate WooCommerce for orders and ticket issuance, then complete local acceptance tests.

== Frequently Asked Questions ==
= Are existing records retained? =
Yes. Existing pesm_ tables, options and shortcode names are preserved. Uninstall retains data.

= Which languages are available? =
English and Persian, selected by the WordPress site or user locale.

= Does it support standard WooCommerce Checkout Blocks? =
The current cart uses the plugin's dedicated order flow. Standard Cart/Checkout Blocks and Store API checkout are not certified.

== Changelog ==
