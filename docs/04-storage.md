# Storage

Redline persists reports, users, comments, assignments and media in a single
local backend: Eloquent models on a dedicated SQLite connection named `redline`,
plus uploaded media under `storage/app/{path.base}`. There is no pluggable
storage provider - custom storage backends are **not supported**.

## Database storage

`Zipavlin\Redline\Providers\Storage\StorageProvider` - Eloquent models on a
dedicated SQLite connection named `redline`. All six model contracts
(`User`, `Report`, `Screenshot`, `Video`, `Comment`, `Assignment`) bind to
Eloquent implementations in `src/Models/Database/`.

### Where the database lives

The connection points at `databasePath() . '/redline.db'`, where `databasePath()`
resolves to `storage_path('app/{path.base}/{path.database}')`. With the default
configuration that is:

```
storage/app/redline/database/redline.db
```

> The location is fully configurable via `REDLINE_STORAGE_PATH_BASE` and
> `REDLINE_STORAGE_PATH_DATABASE`.

The connection is created at boot and is skipped if `database.connections.redline`
is already configured (the test suite injects `:memory:` this way).

### Migrations

- Schema lives in numbered migrations `database/redline/0001_…0006_`
  (`0001_create_redline_users_table` … `0006_create_redline_assignments_table`) -
  numbered, **not** timestamped.
- For a file database the schema is **not** migrated at runtime. If the database
  file is missing, boot throws:

  > `RuntimeException: Redline database not found at [storage/app/redline/database/redline.db]. Run \`php artisan redline:install\` to set up the database.`

  and if the file exists but the `redline_reports` table is absent:

  > `RuntimeException: Redline database tables not found. Run \`php artisan redline:install\` to set up the database.`

  and if the file exists but the `redline_assignments` table is absent:

  > `RuntimeException: Redline database is missing the redline_assignments table. Run \`php artisan redline:install\` to migrate it.`

  `redline:install` creates the schema by running `php artisan migrate
  --path=vendor/zipavlin/redline/database/redline --database=redline`.
- In-memory databases (`:memory:`) auto-migrate at boot - this is how the
  package's own test suite gets its schema.

### Requirements

- PHP `pdo_sqlite` extension (the connection driver is `sqlite`).

## Storage layout

All local files live under `storage/app/{path.base}`. The four path keys under
`config('redline.storage.path')`:

| Variable | Default | Purpose |
|---|---|---|
| `REDLINE_STORAGE_PATH_BASE` | `redline` | Root directory under `storage/app/` |
| `REDLINE_STORAGE_PATH_SCREENSHOTS` | `screenshots` | Uploaded PNG screenshots |
| `REDLINE_STORAGE_PATH_VIDEOS` | `videos` | Uploaded video files |
| `REDLINE_STORAGE_PATH_DATABASE` | `database` | SQLite database file |

Default resulting tree:

```
storage/app/redline/
├── screenshots/          # {ordered-uuid}.png
├── videos/               # {uuid}.{mp4|webm}
└── database/
    └── redline.db        # SQLite database
```

The directories are created automatically by the provider's `boot()`.

## Media files

Uploaded media is private by design: original filenames are discarded, files are
renamed to `{uuid}.{ext}` at rest, and they are served **only** through the
package's binary controller routes - never through public/static URLs.

### Naming & constraints

| Kind | Endpoint | Stored as | Validation |
|---|---|---|---|
| Screenshot | `POST /redline/api/report/{report}/screenshot` | `{ordered-uuid}.png` | `mimes:png`, ≤ 5120 KB (5 MB) |
| Video | `POST /redline/api/report/{report}/video` | `{uuid}.{ext}` | ≤ 51200 KB (50 MB) |

- The screenshot filename is a UUID plus `.png` (extension fixed by the storage
  handler). The video extension is derived from the MIME type:
  `video/mp4`, `video/quicktime` and `video/x-m4v` → `.mp4`, anything else →
  `.webm`. The video validation itself does **not** whitelist MIME types.
- **One attachment per kind per report**: a second screenshot or video upload
  against the same report returns `409 Conflict`
  ("Screenshot already exists for this report." / "Video already exists for this report.").
- Both `store` endpoints are feature-gated - disable with
  `REDLINE_DISABLED=screenshots,videos` (see [Configuration](03-configuration.md)).

### Private serving

Binaries stream via `response()->file()` through authenticated routes, both
inside the auth + CSRF middleware group:

- `GET /redline/api/report/{report}/screenshot/{screenshot}/binary`
- `GET /redline/api/report/{report}/video/{video}/binary`

`VIEW` permission is required. There are no public routes for these files.
Deleting a report also deletes its screenshot/video files from disk.

## Backup & git

- **Git**: keep `storage/app/redline/` out of the host app's git history - add it
  to `.gitignore`. It contains the SQLite database (which includes user accounts
  with password hashes) and user-submitted media.
- **Backup**: back up the whole `storage/app/redline/` directory - the database
  file plus `screenshots/` and `videos/`. Restoring the directory restores both
  data and media, so database and files stay in sync.

## Related

- [Installation](02-installation.md) - `redline:install` setup flow
- [Configuration](03-configuration.md) - all environment variables
- [Resources](07-resources.md) - report/screenshot/video API endpoints
- [Commands](08-commands.md) - `redline:install`, `redline:user`, `redline:remove`
