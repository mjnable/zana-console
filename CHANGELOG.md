# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.19.0 — 2026-11-07

### Added

- `release:clean` applies the release exclusion rules on a live server: sparse-checkout in a Git checkout,
  deletion elsewhere. It runs only in production; `--dry-run` works anywhere. A checkout needs Git 2.35
  (FE-66).
- `doctor` warns in production when files the rules exclude are on the server (`release.files`).
- `Release\ExclusionRules`, the one list of those rules.

## 2.18.0 — 2026-11-07

### Added

- `release:build --no-public` leaves `public/` out of the code archive, for the split layout, where
  `public_html.zip` is the only copy that is served (FE-65).

### Changed

- `release:build` leaves `AGENTS.md`, `CLAUDE.md`, `README.md` and `.env.example` out of the code archive
  (FE-65).

## 2.11.0 — 2026-11-01

### Changed

- `ConsoleKernel::runBuilder()` and `runApplication()` record the script of the real command line in
  `ConsoleScript`, relative to the application (FE-54).
- Requires `mj-zana/core` ^2.11.

## 2.10.0 — 2026-11-01

### Changed

- A `ValidationException` (mj-zana/validation) is printed as its field errors, one per line
  (ADR-025).

## 2.7.0 — 2026-10-31

### Added

- `inspect [section] [--format=json]`: the environment, modules, services with their lifetimes and
  origins, console commands, configuration keys with their types (never their values), packages, and
  the sections packages add (FE-45).
- `check [--format=json]`: the container builds, the deployment checks, every singleton can be
  created, the architecture rules of `mj-zana/testing` on `modules/`, and the checks packages add.
  Problems are findings with an id, a severity, a file and a hint; exit code 1 on an error (FE-46).
- `doctor --format=json`, with the same report as `check`.
- `ConsoleKernel::runBuilder()`: builds the application and runs the console, so that `check`
  reports a container that does not build.

### Changed

- `doctor` lists its problems as findings, the same way as `check`.
- Requires `mj-zana/core` ^2.7.

## 2.5.0 — 2026-10-27

### Added

- `release:build`, moved from the website skeleton (ADR-021): the upload archives for shared hosting,
  built from a commit. Fixed on Windows (FE-41):
  - git and Composer write to a file instead of pipes, so a command writing more than a pipe holds no
    longer blocks for ever;
  - Composer is found on the `PATH`, including the `composer.phar` beside `composer.bat`, and a `.bat`
    runs through `cmd /c`;
  - `-v` shows the output as it comes, and `--timeout` (600 seconds) stops the build, showing the end
    of the output.
- `ext-zip` is suggested, for `release:build`.
## 2.4.0 — 2026-10-26

### Changed

- `cache:clear` deletes the configuration cache and `cache:warm` writes it, failing with the key
  that cannot be cached (ADR-019). `about` and `doctor` report it.
- `doctor` creates every singleton once and reports the ones that cannot be created, such as a
  factory resolving a scoped service (FE-40).
- Requires `mj-zana/core` ^2.4.

## 2.3.0 — 2026-10-26

### Changed

- Tests and tooling (`tests/`, `phpunit.xml.dist`, `phpstan.neon.dist`) are no longer in Composer
  archives, so they stay out of production `vendor/` folders (FE-17).

## 2.2.0 — 2026-10-26

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
