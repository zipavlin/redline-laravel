# Changelog

All notable changes to `redline` will be documented in this file.

## [Unreleased]

- Reports: a report's author is now never a member. Author ids in
  `POST /report/{report}/member` are a no-op (not a 422), the `members` read
  surfaces exclude the author, and migration `0008` removes historical author
  rows. The developer Claim action (`noticed -> progress`) auto-assigns the
  acting user as a member when they are eligible (`approved`, non-guest), and
  the status response now includes the author-excluded `members` list.
- Added `php artisan redline:update` (alias `redline:upgrade`): idempotent
  upgrade for existing installs - reports and runs pending package migrations
  with a pre-migration SQLite backup, re-publishes assets with `--force`,
  offers to re-publish published config/mail views (never overwritten under
  `--force`), and clears config/view/route caches. Flags `--force`/`-F`,
  `--dry-run`, `--no-backup`.
