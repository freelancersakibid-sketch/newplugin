# Validation evidence

These tests ran on a disposable WordPress/WooCommerce store, not a real store. They create products, orders and inventory commands and intentionally inject database failures. Do not run them on production.

The installable plugin ZIP does not contain these fixtures or generated store data.

- `integration.php`: execute with `wp eval-file /tests/integration.php` on a test store where this directory is mounted as `/tests`. Requires an administrator with user ID 1 and active WooCommerce and this plugin. Final logs show 44 assertions for both legacy and HPOS order storage. WordPress AJAX is captured with a test `wp_die` handler; the importer core method is called directly so its outer Throwable guard does not catch the test exit exception. WordPress nonce/capability logic still executes.
- `worker.php` / `concurrency.py`: require Docker containers named `vsr-cli` and `vsr-db`, the fixture directory mounted at `/tests`, and this directory located at `/workspace/work/tests` (adjust paths on another machine). Launches two actual PHP/WooCommerce processes with a file barrier while the first holds its stock lock. No simulated database is used.
- `browser.cjs`: requires Node and Playwright, Chromium, and the disposable site at `http://localhost:8080`. The hardcoded login is a disposable test account created solely for this run (`inventory-admin`, local test password). Adjust those fixture credentials for another test store. It verifies real AJAX and CSV download behavior and captures screenshots.
- `package-smoke.php`: run in a newly installed disposable database with the built ZIP active. It checks schema version, an exact stock command and one-row report data. Its one-product assertion assumes an otherwise empty product catalog.
- Logs are captured final passing runs. Screenshots show generated test inventory, including deliberate negative-stock/backorder fixtures; they contain no real business inventory or customer data.

Reproduction prerequisites: a Docker network with MariaDB 11.4, WordPress core and WooCommerce files, WordPress CLI/PHP 8.3 container, a test database, active plugins, and an admin account. To test both order stores, synchronize orders with `wp wc hpos sync --batch-size=100` before changing `woocommerce_custom_orders_table_enabled`. Do not force the storage switch around WooCommerce's unsynchronized-order protection.

No production dataset, external courier service, persistent object cache or large-catalog benchmark was used. The detailed release limitations are in the plugin's `docs/TESTING.md` and `AUDIT.md`.
