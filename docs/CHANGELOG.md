# Changelog

## 2.2.0-rc.1 — 2026-11-08

Phased stock-safety and reporting release candidate. This is not a production-complete implementation of the full requested inventory system.

- Add `VSR_Stock_Service`: InnoDB checks, receipt serialization, stock-row locks, absolute WooCommerce writes, expected-stock conflict checks, negative-stock validation, rollback on stock/audit/receipt failure, and cache cleanup.
- Replace inline and bulk delta writers. Cap bulk commands at 100 selected rows, deduplicate physical owners, and expose partial failures.
- Generate cryptographic request IDs; retain pending IDs in browser session storage across a reload when available.
- Avoid an extra return audit entry when status and item hooks describe the same restoration; ignore repeated identical item callbacks within the request.
- Forecast shared inventory against all sibling demand; use the owner's retail price for shared valuation regardless of variation filtering.
- Choose the latest of order completion and recorded sale dates.
- Record new historical imports as `historical_sale`, with unknown before/after values. Scan orders by ascending ID through a saved high watermark.
- Page audits by descending log ID and a captured high watermark. Build exports in temporary files inside consistent database snapshots; suppress partial CSV delivery on query errors.
- Apply movement filtering equally to the audit dialog and export. Discard stale asynchronous dialog responses.
- Resolve attribute terms by slug before taxonomy validation.
- Extract additive schema DDL into `migrations/2.2.0.php`; verify required columns and indexes before recording the new database version. Add operation receipts and a global timestamp index.
- Surface failed report queries, schema failures and observed audit write failures.
- Improve filter responsiveness, bulk confirmation, modal keyboard handling, freshness wording and shared-forecast explanations.
- Remove obsolete private stock parsing and logging helpers.

The original public report, tracker, importer and exporter entry points remain available. No existing audit records are rewritten or deleted by this migration.
