# Installation and upgrade

This is **2.2.0-rc.1**, intended for staging evaluation of the completed safety phase. It is not a production release of the entire requested feature set.

## New installation

1. Install and activate WooCommerce.
2. In WordPress, choose **Plugins → Add Plugin → Upload Plugin** and select `variation-stock-report-pro-2.2.0-rc.1.zip`.
3. Install and activate the plugin. Its top-level folder is `variation-stock-report` and the entry point is `variation-stock-report.php`.
4. Open **WooCommerce → Stock Report** as a user with `manage_woocommerce`.

No build step, external JavaScript service or Composer dependency is needed.

## Upgrade from 2.1.0

1. Back up the database and original plugin directory. Test on a staging clone with your courier and stock integrations.
2. Replace the existing plugin using WordPress's upload-and-replace workflow. Keep the folder name `variation-stock-report`.
3. Activation, or the next plugin load after replacement, applies `migrations/2.2.0.php` using `dbDelta`.
4. The migration retains existing inventory and audit rows. It adds `vsr_operations` and `created_id` on the existing audit table, and validates the required columns and indexes. It does not guess historical shared-stock ownership or rewrite old historical entries.
5. If migration validation fails, the database version is not advanced and the report endpoints are not registered. An administrator sees an error notice. Correct database permissions/schema issues and reload to retry.
6. For stock commands, `posts`, `postmeta`, `wc_product_meta_lookup`, `vsr_stock_log` and `vsr_operations` must use InnoDB. Consistent exports also require transactional taxonomy, user and relevant order source tables. Table names use your actual WordPress prefix. The plugin refuses the affected operation on unsupported engines rather than silently providing weaker guarantees; it does not convert existing engines automatically.

The database account needs the usual schema update permissions plus visibility into its own tables in `information_schema`. Verify this with your host if an operation reports an engine or metadata error.

## Rollback

Deactivate and replace the plugin with the original ZIP. New tables are retained; there is no destructive uninstall routine. The original version does not understand the new receipt protocol, and may display newly introduced `historical_sale` audit types without a friendly label. Do not run old unsafe stock writers after rollback without understanding the original defects. Keep the database backup and reconcile any stock/order activity before any database restoration.

## Recovery and logs

Errors use WooCommerce's `variation-stock-report` log source. Report stock commands either commit stock, audit and receipt together or return failure. Retry with the same request ID after network loss or a transient database failure. Do not delete receipts to retry a command: deletion removes duplicate-request protection.

The browser retains unresolved command keys in session storage when available; changing the payload creates a new command. Closing the browser session, clearing storage, changing accounts or moving devices does not preserve its pending command ID. Reconcile the audit log before manually recreating an uncertain adjustment.

An audit-failure notice for writes outside report transactions remains visible for investigation. After reconciliation, a site administrator with WP-CLI may acknowledge it with `wp option delete vsr_audit_write_error`. A failure that was rolled back within a report transaction is also returned directly to the caller.

Deactivation stops registering hooks and preserves data. There are no new scheduled jobs to cancel in this phase.
