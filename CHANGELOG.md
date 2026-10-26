# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.2.0 — unreleased

### Added

- `cache:clear`, `cache:warm`, `about` and `doctor` (FE-18).

### Changed

- Requires `mj-zana/core` ^2.2.

## 2.0.0 — 2026-10-26

The final 2.0 release. No changes since 2.0.0-rc.1.

## 2.0.0-beta.1 — 2026-10-25

### Added

- Every command runs with a correlation id, taken from `ZANA_CORRELATION_ID` when it is valid.

## 2.0.0-alpha.3 — 2026-10-24

First release of the 2.0 line. Console commands on Symfony Console, each run in its own container scope. Requires PHP 8.5 or later.

Documentation: [the console guide](https://github.com/mjnable/zana-docs/blob/main/guides/console.md).
