# Variation Stock Report Pro 2.2.0-rc.1

**Stock-safety release candidate — a phased delivery, not the completed inventory system requested. Install on staging first.**

This upgrade preserves the original WooCommerce report and audit trail, replaces report stock writes with transactional commands, and corrects several reporting and import defects. The original 2.1.0 ZIP has not been modified.

## Included

- Absolute stock setting with optimistic conflict checks and database row locks.
- Per-owner bulk commands, negative-stock prevention when backorders are disabled, explicit partial-failure results.
- Durable idempotency receipts and transactional stock/audit writes.
- Shared-parent demand forecasting and consistent owner-price retail valuation.
- Latest sale chosen from both order and audit timestamps.
- Historical sales evidence separated from verified physical stock movements.
- ID-cursor historical scanning and audit paging; consistent database snapshots for CSV exports.
- Stale audit response protection and matching audit movement filters in CSV downloads.
- Verified additive schema migration; database error responses and audit-failure notifications.
- Responsive filter layout, load-time freshness label, bulk confirmation, accessible audit-dialog keyboard behavior.

## Not included yet

The size × color matrix, stock-count sessions, suppliers and purchase orders, goods receiving, incoming inventory, inspected returns/exchanges/damaged-stock workflow, stock-adjustment CSV upload with dry run, actual cost valuation, saved views, staff capability management, compact sales metrics, Action Scheduler jobs and large-catalog performance qualification are **not implemented in this release**. Existing retail values are explicitly selling-price values, not inventory cost.

See `docs/INSTALL-UPGRADE.md`, `docs/FEATURES.md`, `docs/KNOWN-LIMITATIONS.md`, `docs/TESTING.md`, and `AUDIT.md` for the delivered scope and evidence. Database DDL is in `migrations/2.2.0.php`.

## Requirements

WordPress and active WooCommerce are required. The existing declared minimum versions are WordPress 6.2, PHP 7.4 and WooCommerce 7.6; these minimum combinations have not been qualified. This release was exercised with WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.3.36 and MariaDB 11.4. Stock commands and consistent exports require InnoDB tables and database metadata visibility.

## Use

Open **WooCommerce → Stock Report**. Save an exact stock value on a row or select up to 100 rows for a bulk command. Shared-parent inventory changes once per selected physical owner. On partial failure, read the item-level error list; retry the unchanged command to replay committed successes and retry failed owners safely. Refresh before issuing a new command after conflicts.

The historical importer only records unverified sales evidence; it never updates stock. Reports continue to use completed WooCommerce order quantities. Use the audit dialog's movement selector to filter its corresponding CSV download.
