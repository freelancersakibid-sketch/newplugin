# Delivered feature behavior

## Stock commands

The report delegates mutations to `VSR_Stock_Service::apply(item_id, operation, quantity, request_id, expected, note, source)`.

- Operations are `set`, `increase` and `decrease`; an exact `set` never becomes a relative adjustment.
- Single-row saves require the expected value shown when the page loaded. The comparison occurs after acquiring the authoritative stock metadata lock.
- Bulk commands apply independently per physical inventory owner. They are intentionally not an all-or-nothing transaction across every selected product. Each owner commits stock, audit and receipt together, or rolls back. The response has `updated_count`, `failed_count` and item-level results. A response with failures has `success:false`, even when some owners committed.
- Shared owners are deduplicated in numeric item-ID order. Rows referring to the same owner identify that relationship in results.
- A request identifier has 16–100 ASCII letters, digits, hyphens or underscores. The browser generates 128 random bits. Keys are scoped by command source and owner; the payload hash includes the user, selected representative item, operation, quantity, expected stock and note. Reusing a key for a different payload fails.
- Receipts serialize concurrent identical requests and preserve the committed response. Retry returns that original result with `replayed:true`; it does not claim that unrelated later changes have not occurred.
- A disabled-backorder owner cannot be set or decreased below zero. Enabled backorders may produce negative net stock. Stock limits are ±999,999,999 units; `wc_stock_amount` applies the site's WooCommerce stock normalization.
- Missing, nonnumeric or duplicate `_stock` rows cause rejection. The plugin does not guess which duplicate metadata row is authoritative.
- WooCommerce's stock API still handles stock-status/lookup updates. Cache entries are cleared after commit or rollback.

The PHP service is an internal command interface, not an unauthenticated REST endpoint. An integration must enforce authorization, pass durable event IDs, own no surrounding database transaction, and avoid hooks that commit transactions or make irreversible external changes. This phase does not supply a complete returns adapter, nor does it coordinate third-party writers that bypass the service.

## Report definitions

Sales remain gross line-item quantities on orders currently marked completed. A partial refund on an order still completed does not automatically reduce this sales measure. Sales date filters use order creation dates, retaining the original report convention. Thirty-day velocity is independent of the selected reporting interval and uses the last 30 days of completed-order sales.

For a shared parent pool, daily velocity, days remaining and reorder quantity use combined demand for all sibling variations that consume that pool. A selected subset cannot divide the same physical stock into multiple independent pools. The current reorder model covers 30 days; supplier lead time, safety stock and incoming quantities are not implemented.

Shared retail valuation uses **positive owner stock × the owner's WooCommerce `_price`, falling back to `_regular_price`**. It uses zero when neither exists. This value is stable when selecting different siblings. The summary counts each owner once. Individual variation rows repeat the pool value for context; do not sum those rows. These are retail values, not cost valuation, and no product cost is inferred from selling price.

Last sale selects the later of actual completed-order time and recorded sale time, preserving null when both are absent. Legacy audit dates are site-local; the inherited SQL offset conversion has a daylight-saving limitation described in known limitations.

## Audit viewer and exports

The audit viewer uses descending insertion ID as its stable ordering, not historical event-date order. It captures a maximum ID and requests older IDs to avoid shifts when new rows arrive. Imported historical evidence therefore appears by import insertion order, with its historical date still shown. Refresh/open the dialog again to include new entries.

A request-generation check discards late responses after another product/filter is selected or the dialog closes. Escape closes the dialog; focus remains within it and returns to its opener. CSV receives the same allowlisted movement-type filter. Each CSV is built in a disk-backed temporary file under one repeatable-read consistent snapshot. Audit CSV uses ID cursors and skips repeated count queries. Inventory CSV retains sorted offset batches within the fixed database snapshot. It is synchronous, not a resumable background export. Temp files are cleaned up when the request ends; no public upload URL is created.

Spreadsheet formula-looking text is prefixed with an apostrophe. Negative numeric deltas retain the original conservative escaping behavior and may be treated as text by spreadsheets.

## Historical import

Historical import is a read-and-record evidence scan; it never calls stock update functions. It scans all shop-order IDs in ascending order, up to a per-user high watermark, then inspects completed orders in each batch. The existing AJAX `offset` field now means **last scanned order ID**, not row offset. Old browser resume state is not reused.

Each newly observed completed line gets `historical_sale`, an event key derived from order and line ID, and null physical before/after values. Managed, unmanaged and partially restocked orders are therefore never presented as proof of historical physical movements. Existing verified sale rows and earlier imported lines are skipped. Original historical-import sale rows are displayed and filtered as unverified evidence through a read projection, without changing stored records. An order that becomes completed after the scan passed its ID is picked up on a new full scan, not retroactively within the same run.

The current resume mechanism uses browser local storage and a server-side high watermark; it requires the browser to continue making batch requests. It is not an Action Scheduler background job.

## Permissions and interface

All report and mutation endpoints retain the `manage_woocommerce` capability and WordPress nonces. There is no new read-only staff role or separate cost capability yet. The dashboard shows data read on page load; it is not a continuously synchronized interface. Filter controls adapt to the available admin width, tables scroll horizontally, and bulk actions request confirmation. The original report and export functionality remains; no inactive new-module buttons have been added.
