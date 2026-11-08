# Variation Stock Report Pro — 2.2.0-rc.1

[**Download the installable plugin ZIP**](https://github.com/freelancersakibid-sketch/newplugin/raw/refs/heads/main/releases/variation-stock-report-pro-2.2.0-rc.1.zip)

This is a **staging release candidate** containing tested stock-safety and reporting fixes. The full inventory upgrade is unfinished. See [the audit report](docs/BUG-FIX-REPORT.md) and [known limitations](docs/KNOWN-LIMITATIONS.md) before use.

Install through **WordPress → Plugins → Add Plugin → Upload Plugin**. Back up your site and test on staging first.

- `plugin/variation-stock-report/`: complete updated plugin source and versioned migration.
- `releases/`: installable ZIP and its SHA-256 checksum.
- `docs/`: installation, upgrade, testing, feature documentation and all 18 finding statuses.
- `validation/`: executed test fixtures and final passing logs. Run only on disposable test stores.

Validation: 44 integration assertions pass in each of legacy and HPOS order storage, four concurrent stock scenarios pass, and browser/packaged installation checks pass. This is not a production certification. No real customer data or production credentials are included.
