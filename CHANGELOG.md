# Changelog

All notable changes to `redline` will be documented in this file.

## [Unreleased]

- Added `php artisan redline:update` (alias `redline:upgrade`): idempotent
  upgrade for existing installs - reports and runs pending package migrations
  with a pre-migration SQLite backup, re-publishes assets with `--force`,
  offers to re-publish published config/mail views (never overwritten under
  `--force`), and clears config/view/route caches. Flags `--force`/`-F`,
  `--dry-run`, `--no-backup`.
