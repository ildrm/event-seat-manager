# Event Seat Manager 1.0.0

Install the ZIP through WordPress Plugins. PHP 8.1+ and InnoDB are required. WooCommerce must be active for orders and tickets. Read [release status](RELEASE-STATUS.md) and [compatibility evidence](COMPATIBILITY.md) before enabling live sales.

If upgrading from the old plugin name, deactivate that copy first and activate `event-seat-manager/event-seat-manager.php`. Keep existing `pesm_` tables and options. Schema version remains 18. Pending orders created before financial snapshots were introduced require manual review; existing issued tickets remain intact. Network activation is unavailable; activate on each site individually.

## Administrator workflow

1. Open Events and Seats. Add a center, create a hall or use a layout preset, then save and publish the layout.
2. Create an event and session. Choose numbered seating or general admission. The initial date/time form uses Tehran time; the rescheduling form explicitly uses UTC. Ticket prices use the smallest store currency unit. A zero price creates a free order.
3. Publish both the event and session. Add `[pesm_events]` and `[pesm_event id="123"]` to pages. Use `[pesm_seat_map session="456"]` for a session map.
4. Add `[pesm_my_tickets]` for ticket retrieval and `[pesm_gate_scanner]` on an HTTPS page for authorized gate staff.
5. Assign operators a plugin role and the correct center access. A capability alone does not grant access to other centers. Event-level delegation within the same center is not implemented independently.
6. Verify the complete booking, order, payment, ticket retrieval and admission path on a test session before selling tickets.

## Buyer workflow

Select seats, reserve them temporarily, enter buyer and attendee details, then submit the dedicated order form. Free orders skip the banking step. Paid orders link to WooCommerce's order payment page. Each session currently creates a separate order. Standard WooCommerce checkout does not process reserved seat cart items.

Keep the order number and access credential private. Use My Tickets to retrieve tickets; guest recovery requires the contact information registered on the order. An issued QR is checked online by authorized staff; a picture alone does not prove admission validity.

## Language and accessibility

Choose English or Persian in Settings → General → Site Language. Administrators may select their profile language separately. English uses LTR; Persian uses RTL. User-authored event titles, attendee names and other stored content are preserved in their original language. Seat selection uses keyboard-focusable buttons with selected state; the map supports zoom and scrolling. A manual ticket-number check-in flow is available when camera scanning is unsupported.

## Financial operations and limits

Ticket seat movement supports the same price only. A paid refund is a controlled manual workflow: request a case to suspend admission, refund the exact item in WooCommerce, verify actual gateway settlement, then reconcile the case. Event rescheduling or cancellation records follow-up cases; it does not automatically refund or notify customers. Native ticket PDF, a commercial multi-session bundle, cross-price transfers and standard Checkout Blocks remain incomplete.

Experimental settings, such as ownership transfers, discounts and ticket grouping, are disabled by default. They require their own acceptance tests. Uninstall retains all data.
