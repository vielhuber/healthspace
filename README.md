# 💚 healthspace

A personal health dashboard in PHP, extracted from [Extrablatt](https://github.com/vielhuber/extrablatt). Stores manual measurements in SQLite and synchronises watch activity and sleep through the Google Health API. Includes Chart.js diagrams, moving averages, goal indicators, a weekly summary, light/dark themes, password login and an installable PWA.

## Requirements

- PHP >= 8.3 with cURL and PDO SQLite.
- Composer.
- Apache with `mod_rewrite` and `.htaccess` support, or equivalent protection for dot directories and SQLite files.
- Google OAuth credentials for watch synchronisation. Manual measurements work without Google or an AI API key.

## Installation

Once the package is published on Packagist:

```bash
mkdir healthspace
cd healthspace
composer require vielhuber/healthspace
./vendor/bin/healthspace-init
```

The installer creates the bootstrap, Apache rules, `.data/.env`, an empty SQLite database and `.logs/`. It generates a login password in `.data/.env` as `AUTH_PASSWORD` without printing it. Set your own password there before use. Rerunning the installer preserves credentials, measurements and logs; the bootstrap and Apache rules are package-owned.

Point the web server at the application root. The package serves its bundled CSS, JavaScript and PWA assets through `/?asset=…`; no asset build or copy step is required. Keep `.data/`, `.logs/`, `.git/` and all other dot directories inaccessible over HTTP. The supplied `.htaccess` also blocks SQLite downloads.

## Configuration

Edit `.data/.env`:

| Variable                      | Purpose                                                                                         |
| ----------------------------- | ----------------------------------------------------------------------------------------------- |
| `AUTH_PASSWORD`               | Login password and cron authentication key. An empty value disables application authentication. |
| `GOOGLE_HEALTH_CLIENT_ID`     | Google OAuth client ID.                                                                         |
| `GOOGLE_HEALTH_CLIENT_SECRET` | Google OAuth client secret.                                                                     |
| `AI_PROVIDER`                 | Optional weekly-summary provider; defaults to `openai`.                                         |
| `AI_MODEL`                    | Optional weekly-summary model; defaults to `gpt-5-nano`.                                        |
| `AI_API_KEY`                  | Optional provider API key.                                                                      |
| `AI_BASE_URL`                 | Optional provider endpoint; defaults to `https://api.openai.com/v1`.                            |

There is no news configuration, RSS scraper or browser-impersonation binary. Runtime files belong to the consuming application; the Composer package owns only code and assets. Goals retain the original defaults: 10,000 steps, seven hours of sleep and 105 kg, with amber thresholds of 7,500 steps, six hours and 110 kg. These constants live in `src/Healthspace.php`.

## Google Health

1. Configure a Google OAuth web client with access to the Google Health API.
2. Register the exact site root as the authorised redirect URI, including the trailing slash. For this development environment: `https://healthspace.vielhuber.dev/`.
3. Set `GOOGLE_HEALTH_CLIENT_ID` and `GOOGLE_HEALTH_CLIENT_SECRET` in `.data/.env`.
4. Use the cloud project's production publishing status for a lasting refresh token. Testing status can cause refresh tokens to expire after seven days.
5. Log in, select **Google Health verbinden**, and grant the activity/fitness and sleep read scopes.
6. Select **Synchronisieren** to fetch the first watch history.

The OAuth callback checks a short-lived browser-specific state cookie. The refresh token is stored in `.data/google_health.json` with file mode `0600`. The redirect URI must be registered separately when moving to another hostname; reconnect Google after migration.

Synchronisation reads the most recent 90 days of steps, floors, calories and sleep sessions. It preserves earlier values when an API response lacks a metric, combines fragmented nights, retains sleep stages and daytime naps, and updates the weekly summary after the watch data. If Google is not connected, existing measurements remain usable. If AI is unconfigured or unavailable, the summary uses deterministic step and sleep averages.

## Measurements

The dashboard supports creating, editing and deleting one manual record per date: weight, body-fat percentage, systolic/diastolic blood pressure and pulse. Decimal commas and decimal points are accepted. Body fat requires a weight, blood pressure requires both values, and each record needs at least one measurement.

The original weight, body-fat, blood-pressure, pulse, step, calorie and sleep charts are retained, including trend lines, sleep stages and watch statistics. Authentication cookies and PWA metadata are scoped to Healthspace.

## Cron

```cron
0 6,18 * * * curl -fsS 'https://your-host/?scrape=1&key=<URL-encoded AUTH_PASSWORD>' >/dev/null
```

Authenticated browser sessions can call `/?scrape=1` directly. Concurrent synchronisations return HTTP 409. Incomplete sleep pagination leaves existing sleep totals untouched. Progress is written to `.logs/scrape.log`; this endpoint synchronises health data only.

## Migrating from Extrablatt

The two applications are independent packages. Healthspace does not read the Extrablatt installation at runtime. The local source checkout contained no `.data` directory, so there were no local measurements or credentials to migrate automatically.

For an existing installation:

1. Stop its scheduled scrape temporarily and back up its `.data` directory.
2. Initialise Healthspace and copy only `AUTH_PASSWORD`, `GOOGLE_HEALTH_CLIENT_ID`, `GOOGLE_HEALTH_CLIENT_SECRET` and the optional `AI_*` settings from the old `.data/.env` into the new file. Keep credentials outside Git.
3. Log in to Healthspace once to initialise its tables, then import the two health tables before entering new Healthspace records. Save the following SQL as `migrate-health.sql` and replace the source path. Explicit column lists also support databases whose columns were appended by older migrations.

    ```sql
    ATTACH DATABASE '/path/to/extrablatt/.data/database.sqlite' AS previous;
    BEGIN IMMEDIATE;
    INSERT OR ROLLBACK INTO health_days (
        day, steps, distance_meters, floors, calories, active_minutes,
        sleep_minutes, sleep_period_minutes, sleep_onset_minutes, nap_minutes, nap_count,
        sleep_deep_minutes, sleep_light_minutes, sleep_rem_minutes, sleep_awake_minutes,
        sleep_start, sleep_end, updated_at
    ) SELECT
        day, steps, distance_meters, floors, calories, active_minutes,
        sleep_minutes, sleep_period_minutes, sleep_onset_minutes, nap_minutes, nap_count,
        sleep_deep_minutes, sleep_light_minutes, sleep_rem_minutes, sleep_awake_minutes,
        sleep_start, sleep_end, updated_at
    FROM previous.health_days;
    INSERT OR ROLLBACK INTO health_measurements (
        id, day, weight_kg, body_fat_percentage, blood_pressure_systolic,
        blood_pressure_diastolic, pulse, created_at, updated_at
    ) SELECT
        id, day, weight_kg, body_fat_percentage, blood_pressure_systolic,
        blood_pressure_diastolic, pulse, created_at, updated_at
    FROM previous.health_measurements;
    COMMIT;
    DETACH DATABASE previous;
    ```

    Run the import with SQLite's stop-on-error mode so a failed statement cannot be followed by a partial commit:

    ```bash
    sqlite3 -bail /path/to/healthspace/.data/database.sqlite < migrate-health.sql
    ```

    The transaction requires the current Extrablatt health columns. For older installations, run the previous Extrablatt version's schema migrations first. An ID or date conflict rolls back both tables. Transfer only the health tables, not the news database or news cache.

4. Add the new OAuth redirect URI and reconnect Google in Healthspace. Run **Synchronisieren** to populate its own weekly-summary cache, then enable the new cron entry.
5. Verify record counts and charts before deleting any old health tables or credentials. This extraction does not delete existing databases or their health tables. Extrablatt stops creating, reading or updating those tables, and no longer renders health content from old digest caches. Its editorial health-news category remains available.

## Development

```bash
git clone git@github.com:vielhuber/healthspace.git
cd healthspace
composer install
php bin/healthspace-init
php -S 127.0.0.1:8080 -t .
```

The development checkout is registered with LAMP at `https://healthspace.vielhuber.dev` using PHP 8.5 and SQLite, behind the existing private Cloudflare Access policy. Package dependencies can be installed locally before the first Packagist release; publication and version tags are separate steps.

```bash
composer test
npm install
npm test
npm run format:check
```

The PHP tests use temporary databases and HTTP fixtures, including the documented migration; they do not contact Google or an AI provider. The JavaScript test checks chart updates when switching themes. To regenerate PNG icons from the SVG source with ImageMagick:

```bash
magick -background none pwa/icon.svg -resize 192x192 pwa/icon-192.png
magick -background none pwa/icon.svg -resize 512x512 pwa/icon-512.png
magick -background none pwa/icon.svg -resize 180x180 pwa/apple-touch-icon.png
```

## Backup and updates

Back up the entire `.data` directory while synchronisation is stopped. It contains credentials, the OAuth refresh token and all measurements. Update the package with Composer and rerun `healthspace-init` when the bootstrap or Apache rules change. Do not replace `.data` when deploying code updates.
