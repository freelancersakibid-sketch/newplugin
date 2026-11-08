# Testing report — 2.2.0-rc.1

## Environment

Automated local test store, isolated from any real website or customer data:

- WordPress **7.1.4**
- WooCommerce **11.2.2**
- PHP **8.3.36**
- MariaDB **11.4**, InnoDB
- Chromium **151**, Playwright
- Default WordPress object cache; no Redis/Memcached

No test was run on the user's live website. The original ZIP was preserved.

## Executed checks

The separate validation bundle includes the executable fixtures and captured final logs.

| Check | Result / scope |
|---|---|
| PHP syntax | Every packaged PHP source/migration file linted with PHP 8.3 |
| JavaScript syntax | `node --check assets/admin.js` passed |
| Integration suite, legacy order storage | **44 assertions passed** |
| Integration suite, HPOS | **44 assertions passed** using native HPOS order reads/writes |
| Concurrency | **4 multi-process scenarios passed**, with an explicit barrier while the first command held the stock lock |
| Browser | Authenticated report renders; delayed stale audit response discarded; real nonempty audit CSV respects movement filter; desktop/mobile render without JS exceptions and mobile document overflow |
| Migration | Injected schema validation failure leaves version unchanged; retry succeeds; an existing audit row remains byte-for-byte equivalent at the field level |
| Installation | Plugin activation/deactivation exercised; final archive is separately installed and activated in a clean test database; see package verification log |

### Integration assertions

- Absolute exact set; stale expected-value rejection.
- Negative exact set/decrease rejection; enabled-backorder decrease.
- Duplicate command replay; mismatched payload rejection; invalid quantity and request identifiers.
- Injected audit INSERT failure rolls stock back; same request succeeds when retried after recovery.
- Injected stock SQL failure never reports success.
- Injected WooCommerce lookup write failure rolls stock back.
- Injected receipt write failure rolls stock and audit back.
- Spreadsheet formula text neutralization and visible audit-failure admin notice.
- Variation command changes the parent physical pool.
- All sibling demand contributes to shared-parent velocity.
- Shared retail values are consistent between siblings and stable under different SKU filters.
- SQL-like search remains literal; future sales date range excludes sales; never-sold latest-date filter executes.
- Attribute term lookup chooses the correct taxonomy when another taxonomy has the same slug.
- Summary query executes; newer audit timestamp wins over older order completion time.
- Audit cursor does not repeat records or include a new insertion above its snapshot watermark.
- Injected report-query failure raises an error rather than empty success.
- Courier-style status watch plus repeated item/fallback restoration hooks produces one audit row.
- Partial bulk failure exposes per-owner results and returns `success:false`; shared owners are updated once.
- Anonymous user and invalid nonce are rejected by the stock AJAX endpoint.
- Historical evidence import does not change physical stock, supports unmanaged items and does not duplicate on retry.
- Original historical-import sales are displayed/filtered as unverified without changing their stored type.
- Failed migration does not advance version; retry succeeds; existing audit data is preserved.

### Concurrent scenarios

1. Two exact commands with the same expected stock: one commits, the stale writer fails.
2. Two decreases of eight from stock ten with backorders disabled: one commits, the other fails, final stock two.
3. Same increase and request identifier in two processes: one physical increase, second response replays the receipt.
4. Report exact-set transaction from twenty to fifteen while core WooCommerce decreases two: core waits on the same row; final stock thirteen.

### Browser testing method

Playwright logs into the real WordPress admin. The first audit request is artificially delayed; its dialog is closed and a second product opened. The final rows are checked against the second product/owner. A global restock filter then produces a real CSV download whose rows are verified. Screenshots capture desktop and mobile layouts. These are automated browser checks and visual screenshot inspection, not a manual acceptance test on the user's store.

## Not executed / not claimed

- 10,000+ product, 50,000+ variation or hundreds-of-thousands audit performance tests; EXPLAIN comparisons and sustained concurrency stress.
- Cross-request persistent object cache, DB replica routing, MySQL variants, multisite/network activation, PHP minimum/current-version compatibility matrix.
- External courier, exchange, partial-restock, stock-count, supplier/PO, receiving, CSV-upload/dry-run or staff-role workflows. Most of those modules are not implemented in this phase.
- Action Scheduler recovery or background export resume (not implemented).
- Large multi-batch snapshot exports under live catalog mutation, historical completion changes behind an import cursor, complex refund accounting, daylight-saving correctness of legacy audit times.
- A penetration test or independent production security audit; unauthorized-user and invalid-nonce checks cover the existing stock endpoint gate, not every possible deployment/integration path.
- Manual business acceptance, real mail transport or the user's production data.

## Release decision

**Staging release candidate only.** The tested stock-safety phase is delivered as actual source and an installable ZIP. Open findings and feature work in `AUDIT.md` and `KNOWN-LIMITATIONS.md` prevent representing this as the final production-ready inventory system.
