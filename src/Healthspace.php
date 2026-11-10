<?php
declare(strict_types=1);

namespace vielhuber\healthspace;

use DateTimeImmutable;
use PDO;
use PDOException;

final class Healthspace
{
    private const GOOGLE_HEALTH_BASE_URL = 'https://health.googleapis.com/v4';
    private const GOOGLE_HEALTH_SCOPE =
        'https://www.googleapis.com/auth/googlehealth.activity_and_fitness.readonly' .
        ' https://www.googleapis.com/auth/googlehealth.sleep.readonly';
    private const GOOGLE_HEALTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const GOOGLE_HEALTH_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const GOOGLE_HEALTH_DATA_TYPES = [
        'steps' => 90,
        'floors' => 90,
        'total-calories' => 14
    ];
    private const GOOGLE_HEALTH_DAY_SLEEP_FROM_HOUR = 9;
    private const GOOGLE_HEALTH_NIGHT_FROM_HOUR = 18;
    private const GOOGLE_HEALTH_SLEEP_STAGES = [
        'DEEP' => 'sleep_deep_minutes',
        'LIGHT' => 'sleep_light_minutes',
        'REM' => 'sleep_rem_minutes',
        'AWAKE' => 'sleep_awake_minutes'
    ];
    private const GOOGLE_HEALTH_HISTORY_DAYS = 90;
    private const GOOGLE_HEALTH_STEP_GOAL = 10000;
    private const GOOGLE_HEALTH_STEP_AMBER = 7500;
    private const HEALTH_WEIGHT_GOAL_KG = 105;
    private const HEALTH_WEIGHT_AMBER_KG = 110;
    private const GOOGLE_HEALTH_SLEEP_GOAL = 420;
    private const GOOGLE_HEALTH_SLEEP_AMBER = 360;
    private const GOOGLE_HEALTH_TREND_WINDOW = 7;
    private const AUTH_COOKIE_KEY = 'healthspace-cookie-key-v1';
    private const FETCH_CONNECT_TIMEOUT_SECONDS = 8;
    private const FETCH_MAX_TIME_SECONDS = 20;

    private string $cssDir;
    private string $pwaDir;
    private string $jsDir;
    private string $dataDir;
    private string $logDir;
    private string $databaseFile;
    private string $envFile;
    private ?PDO $database = null;
    /** @var array<string, string>|null */
    private ?array $environment = null;

    /**
     * Keep runtime data in the consuming application and assets in the package.
     */
    public function __construct(string $rootDir)
    {
        $this->cssDir = __DIR__ . '/../css';
        $this->pwaDir = __DIR__ . '/../pwa';
        $this->jsDir = __DIR__ . '/../js';
        $this->dataDir = $rootDir . '/.data';
        $this->logDir = $rootDir . '/.logs';
        $this->databaseFile = $this->dataDir . '/database.sqlite';
        $this->envFile = $this->dataDir . '/.env';
    }

    /**
     * Keep each health workflow on its own linked page.
     */
    private function renderDashboard(): string
    {
        $pwa = $this->pwaHeadTags();
        $pages = [
            'overview' => 'Übersicht',
            'measurements' => 'Messwerte',
            'nutrition' => 'Ernährung',
            'activity' => 'Aktivität & Schlaf'
        ];
        $page = (string) ($_GET['page'] ?? (isset($_GET['edit_measurement']) ? 'measurements' : 'overview'));
        if (!isset($pages[$page])) {
            http_response_code(response_code: 404);
            return '<h1>Seite nicht gefunden</h1><a href="/">Zur Übersicht</a>';
        }
        $title = $pages[$page];
        $navigation = '';
        foreach ($pages as $key => $label) {
            $navigation .=
                '<a href="/?page=' .
                $key .
                '"' .
                ($page === $key ? ' aria-current="page"' : '') .
                '>' .
                $label .
                '</a>';
        }
        $content = $page === 'nutrition' ? $this->nutrition()->render() : $this->buildHealthBlock(page: $page);
        if ($page === 'overview') {
            $digest = json_decode(json: $this->cacheGet(key: 'health_digest') ?? '{}', associative: true);
            $content =
                $this->buildHealthDigestBlock(health: is_array(value: $digest) ? $digest : []) .
                '<p class="page-links"><a href="/?page=nutrition">Gericht fotografieren</a> · ' .
                '<a href="/?page=measurements#health-entry-form">Messwerte erfassen</a></p>' .
                $content;
        }
        $connection = $this->isGoogleHealthConnected()
            ? '<span class="connection-status">Google Health verbunden</span>'
            : '<a href="/?health=connect">Google Health verbinden</a>';
        $lastSync = is_file(filename: $this->logDir . '/scrape.log')
            ? 'Zuletzt synchronisiert: ' .
                date(format: 'd.m.Y H:i', timestamp: (int) filemtime(filename: $this->logDir . '/scrape.log'))
            : '';
        return <<<HTML
        <!doctype html>
        <html lang="de">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1,viewport-fit=cover">
            <title>{$title} · healthspace</title>
            {$pwa}
            <script src="/?asset=js/theme.js&v=2"></script>
            <link rel="stylesheet" href="/?asset=css/healthspace.css&v=2">
        </head>
        <body>
            <main>
                <header class="top">
                    <h1><a href="/">💚 healthspace</a></h1>
                    <nav aria-label="Aktionen">
                        <button type="button" id="themeToggle" aria-label="Farbschema wechseln">◐</button>
                        {$connection}
                        <a href="/?scrape=1" target="_blank" rel="noopener">Synchronisieren ↻</a>
                        <a href="/?logout=1">Logout</a>
                    </nav>
                </header>
                <nav class="page-nav" aria-label="Hauptnavigation">{$navigation}</nav>
                <h2 class="page-title">{$title}</h2>
                <p class="last-sync">{$lastSync}</p>
                {$content}
            </main>
            <script src="/?asset=js/charts.js&v=2"></script>
        </body>
        </html>
        HTML;
    }

    /**
     * Route authenticated health requests, measurements and scheduled synchronisation.
     */
    public function run(): void
    {
        if (isset($_GET['asset'])) {
            $this->serveAsset(relPath: (string) $_GET['asset']);
            return;
        }
        header(header: 'Cache-Control: no-store');
        if (isset($_POST['auth_password'])) {
            $this->handleLogin();
            return;
        }
        $env = $this->loadEnv();
        $syncRequested = isset($_GET['scrape']);
        $expectedKey = (string) ($env['AUTH_PASSWORD'] ?? '');
        $keyAccepted =
            $syncRequested &&
            $expectedKey !== '' &&
            hash_equals(known_string: $expectedKey, user_string: (string) ($_GET['key'] ?? ''));
        if (!$keyAccepted && !$this->isAuthenticated()) {
            if ($syncRequested) {
                http_response_code(response_code: 403);
                echo "forbidden\n";
                return;
            }
            $this->renderLoginPage();
            return;
        }
        if (isset($_GET['logout'])) {
            $this->handleLogout();
            return;
        }
        $database = $this->openDatabase();
        if (isset($_GET['health']) || isset($_GET['state'])) {
            $this->handleGoogleHealthOauth(
                step: ((string) ($_GET['health'] ?? '')) === 'connect' ? 'connect' : 'callback'
            );
            return;
        }
        if ($syncRequested) {
            if (!is_dir(filename: $this->logDir)) {
                mkdir(directory: $this->logDir, permissions: 0700, recursive: true);
            }
            $lock = fopen(filename: $this->logDir . '/scrape.lock', mode: 'c+');
            if ($lock === false || !flock(stream: $lock, operation: LOCK_EX | LOCK_NB)) {
                if ($lock !== false) {
                    fclose(stream: $lock);
                }
                http_response_code(response_code: 409);
                echo "Synchronisierung läuft bereits.\n";
                return;
            }
            header(header: 'Content-Type: text/plain; charset=utf-8');
            set_time_limit(seconds: 0);
            file_put_contents(filename: $this->logDir . '/scrape.log', data: '');
            $emit = function (string $message): void {
                file_put_contents(filename: $this->logDir . '/scrape.log', data: $message . "\n", flags: FILE_APPEND);
                echo $message . "\n";
                flush();
            };
            try {
                $emit('Google Health: Tagesdaten der Uhr synchronisieren');
                $this->syncGoogleHealth(db: $database, emit: $emit);
                $stats = $this->healthDigestStats();
                if ($stats !== []) {
                    $prose = $this->generateHealthProse(
                        stats: $stats,
                        aiConfig: [
                            'provider' => (string) ($env['AI_PROVIDER'] ?? ''),
                            'model' => (string) ($env['AI_MODEL'] ?? ''),
                            'url' => (string) ($env['AI_BASE_URL'] ?? '')
                        ],
                        apiKey: (string) ($env['AI_API_KEY'] ?? '')
                    );
                    $this->cacheSet(
                        key: 'health_digest',
                        value: (string) json_encode(
                            value: [
                                'generated_at' => time(),
                                'prose' => $prose
                            ]
                        )
                    );
                }
                $emit('Synchronisierung abgeschlossen.');
            } finally {
                flock(stream: $lock, operation: LOCK_UN);
                fclose(stream: $lock);
            }
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['page'] ?? '') === 'nutrition') {
            $this->nutrition()->handleRequest();
            return;
        }
        if (isset($_POST['health_action'])) {
            $action = (string) $_POST['health_action'];
            $measurementIdInput = trim(string: (string) ($_POST['measurement_id'] ?? ''));
            $measurementId =
                $measurementIdInput === '' ? null : filter_var(value: $measurementIdInput, filter: FILTER_VALIDATE_INT);
            $redirectParameters = ['page' => 'measurements'];
            if ($action === 'delete') {
                if ($measurementId === false || $measurementId === null || $measurementId < 1) {
                    $redirectParameters['health_error'] = 'Ungültiger Datensatz.';
                } else {
                    $statement = $this->openDatabase()->prepare(
                        query: 'DELETE FROM health_measurements WHERE id = :id'
                    );
                    $statement->execute(params: [':id' => $measurementId]);
                    $deleted = $statement->rowCount() > 0;
                    $redirectParameters[$deleted ? 'health_status' : 'health_error'] = $deleted
                        ? 'deleted'
                        : 'Datensatz nicht gefunden.';
                }
                header(
                    header: 'Location: /?' . http_build_query(data: $redirectParameters) . '#health-entry-form',
                    response_code: 303
                );
                return;
            }

            $day = trim(string: (string) ($_POST['day'] ?? ''));
            $weightInput = str_replace(
                search: ',',
                replace: '.',
                subject: trim(string: (string) ($_POST['weight_kg'] ?? ''))
            );
            $bodyFatInput = str_replace(
                search: ',',
                replace: '.',
                subject: trim(string: (string) ($_POST['body_fat_percentage'] ?? ''))
            );
            $systolicInput = trim(string: (string) ($_POST['blood_pressure_systolic'] ?? ''));
            $diastolicInput = trim(string: (string) ($_POST['blood_pressure_diastolic'] ?? ''));
            $pulseInput = trim(string: (string) ($_POST['pulse'] ?? ''));
            $date = DateTimeImmutable::createFromFormat(format: '!Y-m-d', datetime: $day);
            $weight = $weightInput === '' ? null : filter_var(value: $weightInput, filter: FILTER_VALIDATE_FLOAT);
            $bodyFat = $bodyFatInput === '' ? null : filter_var(value: $bodyFatInput, filter: FILTER_VALIDATE_FLOAT);
            $systolic = $systolicInput === '' ? null : filter_var(value: $systolicInput, filter: FILTER_VALIDATE_INT);
            $diastolic =
                $diastolicInput === '' ? null : filter_var(value: $diastolicInput, filter: FILTER_VALIDATE_INT);
            $pulse = $pulseInput === '' ? null : filter_var(value: $pulseInput, filter: FILTER_VALIDATE_INT);
            $error = '';
            if ($action !== 'save') {
                $error = 'Ungültige Aktion.';
            }
            if ($error === '' && $measurementId === false) {
                $error = 'Ungültiger Datensatz.';
            }
            if ($error === '' && ($date === false || $date->format(format: 'Y-m-d') !== $day)) {
                $error = 'Bitte ein gültiges Datum angeben.';
            }
            if ($error === '' && ($weight === false || ($weight !== null && $weight <= 0))) {
                $error = 'Bitte ein gültiges Gewicht angeben.';
            }
            if ($error === '' && ($bodyFat === false || ($bodyFat !== null && ($bodyFat <= 0 || $bodyFat > 100)))) {
                $error = 'Bitte einen gültigen Körperfettanteil angeben.';
            }
            if ($error === '' && $bodyFat !== null && $weight === null) {
                $error = 'Bitte zum Körperfettanteil auch das Gewicht angeben.';
            }
            if ($error === '' && ($systolic === null) !== ($diastolic === null)) {
                $error = 'Bitte den systolischen und diastolischen Blutdruck angeben.';
            }
            if (
                $error === '' &&
                ($systolic === false ||
                    $diastolic === false ||
                    ($systolic !== null && $systolic <= 0) ||
                    ($diastolic !== null && $diastolic <= 0))
            ) {
                $error = 'Bitte einen gültigen Blutdruck angeben.';
            }
            if ($error === '' && ($pulse === false || ($pulse !== null && $pulse <= 0))) {
                $error = 'Bitte einen gültigen Puls angeben.';
            }
            if ($error === '' && $weight === null && $systolic === null && $pulse === null) {
                $error = 'Bitte mindestens einen Messwert angeben.';
            }
            if ($error === '' && $measurementId !== null && $measurementId < 1) {
                $error = 'Ungültiger Datensatz.';
            }
            if ($error === '') {
                $database = $this->openDatabase();
                if ($measurementId !== null) {
                    $measurementStatement = $database->prepare(
                        query: 'SELECT 1 FROM health_measurements WHERE id = :id'
                    );
                    $measurementStatement->execute(params: [':id' => $measurementId]);
                    if ($measurementStatement->fetchColumn() === false) {
                        $error = 'Datensatz nicht gefunden.';
                    }
                }
            }
            if ($error === '') {
                $existingStatement = $database->prepare(
                    query: 'SELECT id FROM health_measurements WHERE day = :day AND (:id IS NULL OR id != :id)'
                );
                $existingStatement->execute(params: [':day' => $day, ':id' => $measurementId]);
                if ($existingStatement->fetchColumn() !== false) {
                    $error = 'Für dieses Datum existiert bereits ein Eintrag.';
                }
            }
            if ($error === '' && $measurementId === null) {
                $statement = $database->prepare(
                    query: 'INSERT INTO health_measurements
                        (day, weight_kg, body_fat_percentage, blood_pressure_systolic, blood_pressure_diastolic, pulse,
                            created_at, updated_at)
                        VALUES (:day, :weight, :body_fat, :systolic, :diastolic, :pulse, :created_at, :updated_at)'
                );
                $statement->execute(
                    params: [
                        ':day' => $day,
                        ':weight' => $weight,
                        ':body_fat' => $bodyFat,
                        ':systolic' => $systolic,
                        ':diastolic' => $diastolic,
                        ':pulse' => $pulse,
                        ':created_at' => time(),
                        ':updated_at' => time()
                    ]
                );
            }
            if ($error === '' && $measurementId !== null) {
                $statement = $database->prepare(
                    query: 'UPDATE health_measurements
                        SET day = :day, weight_kg = :weight, body_fat_percentage = :body_fat,
                            blood_pressure_systolic = :systolic, blood_pressure_diastolic = :diastolic,
                            pulse = :pulse, updated_at = :updated_at
                        WHERE id = :id'
                );
                $statement->execute(
                    params: [
                        ':day' => $day,
                        ':weight' => $weight,
                        ':body_fat' => $bodyFat,
                        ':systolic' => $systolic,
                        ':diastolic' => $diastolic,
                        ':pulse' => $pulse,
                        ':updated_at' => time(),
                        ':id' => $measurementId
                    ]
                );
            }
            if ($error !== '') {
                $redirectParameters['health_error'] = $error;
                if ($measurementId !== null && $measurementId !== false) {
                    $redirectParameters['edit_measurement'] = $measurementId;
                }
            } else {
                $redirectParameters['health_status'] = 'saved';
            }
            header(
                header: 'Location: /?' . http_build_query(data: $redirectParameters) . '#health-entry-form',
                response_code: 303
            );
            return;
        }

        header(header: 'Content-Type: text/html; charset=utf-8');
        echo $this->renderDashboard();
    }

    /**
     * Initialise only the health tables and the cached weekly summary.
     */
    private function openDatabase(): PDO
    {
        $db = &$this->database;
        if ($db !== null) {
            return $db;
        }
        if (!is_dir(filename: $this->dataDir)) {
            mkdir(directory: $this->dataDir, permissions: 0700, recursive: true);
        }
        $db = new PDO(dsn: 'sqlite:' . $this->databaseFile);
        $db->setAttribute(attribute: PDO::ATTR_ERRMODE, value: PDO::ERRMODE_EXCEPTION);
        $db->exec(statement: 'PRAGMA busy_timeout = 5000');
        $db->exec(
            statement: 'CREATE TABLE IF NOT EXISTS cache (
                key TEXT PRIMARY KEY NOT NULL,
                value BLOB,
                updated_at INTEGER NOT NULL
            );'
        );
        $db->exec(
            statement: 'CREATE TABLE IF NOT EXISTS health_days (
                day TEXT PRIMARY KEY NOT NULL,
                steps INTEGER DEFAULT NULL,
                distance_meters INTEGER DEFAULT NULL,
                floors INTEGER DEFAULT NULL,
                calories INTEGER DEFAULT NULL,
                active_minutes INTEGER DEFAULT NULL,
                sleep_minutes INTEGER DEFAULT NULL,
                sleep_period_minutes INTEGER DEFAULT NULL,
                sleep_onset_minutes INTEGER DEFAULT NULL,
                nap_minutes INTEGER DEFAULT NULL,
                nap_count INTEGER DEFAULT NULL,
                sleep_deep_minutes INTEGER DEFAULT NULL,
                sleep_light_minutes INTEGER DEFAULT NULL,
                sleep_rem_minutes INTEGER DEFAULT NULL,
                sleep_awake_minutes INTEGER DEFAULT NULL,
                sleep_start TEXT DEFAULT NULL,
                sleep_end TEXT DEFAULT NULL,
                updated_at INTEGER NOT NULL
            );'
        );
        $db->exec(
            statement: 'CREATE TABLE IF NOT EXISTS health_meals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                day TEXT NOT NULL,
                dish TEXT NOT NULL,
                calories INTEGER NOT NULL CHECK(calories >= 0),
                submission_id TEXT UNIQUE NOT NULL,
                created_at INTEGER NOT NULL
            );'
        );
        $db->exec(
            statement: 'CREATE TABLE IF NOT EXISTS health_measurements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                day TEXT UNIQUE NOT NULL,
                weight_kg REAL DEFAULT NULL,
                body_fat_percentage REAL DEFAULT NULL,
                blood_pressure_systolic INTEGER DEFAULT NULL,
                blood_pressure_diastolic INTEGER DEFAULT NULL,
                pulse INTEGER DEFAULT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                CHECK(weight_kg IS NOT NULL OR blood_pressure_systolic IS NOT NULL OR pulse IS NOT NULL),
                CHECK((blood_pressure_systolic IS NULL) = (blood_pressure_diastolic IS NULL))
            );'
        );
        foreach (
            [
                'sleep_minutes',
                'sleep_period_minutes',
                'sleep_onset_minutes',
                'nap_minutes',
                'nap_count',
                'sleep_deep_minutes',
                'sleep_light_minutes',
                'sleep_rem_minutes',
                'sleep_awake_minutes'
            ]
            as $sleepColumn
        ) {
            $this->addColumnIfMissing(
                db: $db,
                table: 'health_days',
                column: $sleepColumn,
                definition: 'INTEGER DEFAULT NULL'
            );
        }
        $this->addColumnIfMissing(
            db: $db,
            table: 'health_days',
            column: 'sleep_start',
            definition: 'TEXT DEFAULT NULL'
        );
        $this->addColumnIfMissing(db: $db, table: 'health_days', column: 'sleep_end', definition: 'TEXT DEFAULT NULL');
        $this->addColumnIfMissing(
            db: $db,
            table: 'health_measurements',
            column: 'body_fat_percentage',
            definition: 'REAL DEFAULT NULL'
        );
        return $db;
    }

    /**
     * Stream a bundled static asset (css or pwa) through PHP. The library
     * ships its own css/* and pwa/* — the consumer needs no asset copy step.
     */
    private function serveAsset(string $relPath): void
    {
        if (
            preg_match(pattern: '~^(css|pwa|js)/[A-Za-z0-9._-]+$~', subject: $relPath) !== 1 ||
            str_contains(haystack: $relPath, needle: '..')
        ) {
            http_response_code(response_code: 404);
            return;
        }
        [$subdir, $file] = explode(separator: '/', string: $relPath, limit: 2);
        $absolute =
            match ($subdir) {
                'css' => $this->cssDir,
                'js' => $this->jsDir,
                default => $this->pwaDir
            } .
            '/' .
            $file;
        if (!is_file(filename: $absolute)) {
            http_response_code(response_code: 404);
            return;
        }
        $mime = match (true) {
            str_ends_with(haystack: $file, needle: '.css') => 'text/css',
            str_ends_with(haystack: $file, needle: '.js') => 'text/javascript',
            str_ends_with(haystack: $file, needle: '.json') => 'application/json',
            str_ends_with(haystack: $file, needle: '.svg') => 'image/svg+xml',
            str_ends_with(haystack: $file, needle: '.png') => 'image/png',
            default => 'application/octet-stream'
        };
        header(header: 'Content-Type: ' . $mime);
        // The service worker must not be cached aggressively, otherwise
        // updates would never reach installed clients.
        if (str_ends_with(haystack: $file, needle: 'sw.js')) {
            header(header: 'Cache-Control: no-cache');
            header(header: 'Service-Worker-Allowed: /');
        } else {
            header(header: 'Cache-Control: public, max-age=86400');
        }
        header(header: 'Content-Length: ' . filesize(filename: $absolute));
        readfile(filename: $absolute);
    }

    /**
     * Per-deployment HMAC key for the auth cookie, derived from AUTH_PASSWORD
     * so the signing secret is never present in the (public) source.
     */
    private function authCookieKey(string $password): string
    {
        return hash_hmac(algo: 'sha256', data: self::AUTH_COOKIE_KEY, key: $password);
    }

    /**
     * Check whether the request carries a valid auth cookie. The cookie
     * format is `<expiry>.<hmac>`, where hmac = HMAC-SHA256(expiry, secret).
     * This is stateless (no server-side session storage); the secret in
     * .env is the only thing tying valid cookies to this instance.
     */
    private function isAuthenticated(): bool
    {
        $env = $this->loadEnv();
        $password = (string) ($env['AUTH_PASSWORD'] ?? '');
        if ($password === '') {
            return true; // auth disabled
        }
        $cookie = (string) ($_COOKIE['healthspace_auth'] ?? '');
        if ($cookie === '') {
            return false;
        }
        $parts = explode(separator: '.', string: $cookie, limit: 2);
        if (count(value: $parts) !== 2) {
            return false;
        }
        [$expiry, $sig] = $parts;
        if (!ctype_digit(text: $expiry) || (int) $expiry < time()) {
            return false;
        }
        $expected = hash_hmac(algo: 'sha256', data: $expiry, key: $this->authCookieKey($password));
        return hash_equals(known_string: $expected, user_string: $sig);
    }

    /**
     * Use secure cookies when served over TLS.
     */
    private function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    /**
     * Keep the signed session scoped to this application.
     */
    private function setAuthCookie(int $expiry, string $signature): void
    {
        // Positional-only because PHP's named parameter for the options
        // array is `$expires_or_options`, not `$options`.
        setcookie('healthspace_auth', $expiry . '.' . $signature, [
            'expires' => $expiry,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $this->isHttps()
        ]);
    }

    /**
     * Expire the current browser session.
     */
    private function clearAuthCookie(): void
    {
        setcookie('healthspace_auth', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $this->isHttps()
        ]);
    }

    /**
     * Issue a signed session after verifying the configured password.
     */
    private function handleLogin(): void
    {
        $env = $this->loadEnv();
        $expected = (string) ($env['AUTH_PASSWORD'] ?? '');
        $submitted = (string) ($_POST['auth_password'] ?? '');
        if ($expected === '') {
            $this->renderLoginPage(error: 'AUTH_PASSWORD in .env nicht gesetzt.');
            return;
        }
        if (!hash_equals(known_string: $expected, user_string: $submitted)) {
            // Sleep a tiny bit to slow brute force without DoS'ing ourselves.
            usleep(microseconds: 200_000);
            $this->renderLoginPage(error: 'Falsches Passwort.');
            return;
        }
        // 1-year session so the PWA stays logged in.
        $expiry = time() + 365 * 86400;
        $sig = hash_hmac(algo: 'sha256', data: (string) $expiry, key: $this->authCookieKey($expected));
        $this->setAuthCookie(expiry: $expiry, signature: $sig);
        header(header: 'Location: /');
    }

    /**
     * Expire the session and return to the login page.
     */
    private function handleLogout(): void
    {
        $this->clearAuthCookie();
        header(header: 'Location: /');
    }

    /**
     * Render the password gate with the bundled PWA metadata.
     */
    private function renderLoginPage(?string $error = null): void
    {
        header(header: 'Content-Type: text/html; charset=utf-8');
        $pwa = $this->pwaHeadTags();
        $errorHtml =
            $error !== null ? '<p class="err">' . htmlspecialchars(string: $error, flags: ENT_QUOTES) . '</p>' : '';
        echo <<<HTML
        <!doctype html>
        <html lang="de">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1,viewport-fit=cover">
            <title>healthspace — Login</title>
            {$pwa}
            <style>
                :root { color-scheme: light; }
                * { box-sizing: border-box; }
                html, body { margin: 0; height: 100%; }
                body { font-family: Arial, sans-serif; background: #f4f4f5; color: #111; display: flex; align-items: center; justify-content: center; padding: 1rem; }
                form { background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; padding: 32px 28px; box-shadow: 0 4px 24px rgba(0,0,0,.06); width: 100%; max-width: 360px; display: flex; flex-direction: column; gap: 14px; }
                h1 { margin: 0 0 4px; font-size: 22px; letter-spacing: -0.02em; }
                input { font: 600 16px/1 Arial, sans-serif; padding: 12px 14px; border: 1px solid #d4d4d8; border-radius: 8px; width: 100%; }
                input:focus { outline: none; border-color: #18181b; }
                button { font: 700 14px/1 Arial, sans-serif; padding: 12px 14px; background: #18181b; color: #fff; border: 0; border-radius: 8px; cursor: pointer; }
                button:hover { background: #3f3f46; }
                .err { color: #b91c1c; font: 500 13px/1.4 Arial, sans-serif; margin: 0; }
            </style>
        </head>
        <body>
            <form method="post" action="/">
                <h1>💚 healthspace</h1>
                {$errorHtml}
                <input type="password" name="auth_password" autofocus required placeholder="Passwort">
                <button type="submit">Login</button>
            </form>
        </body>
        </html>
        HTML;
    }

    /**
     * Expose the bundled manifest and register the service worker.
     */
    private function pwaHeadTags(): string
    {
        return '<link rel="manifest" href="/?asset=pwa/manifest.json">' .
            '<meta name="theme-color" content="#111111">' .
            '<link rel="icon" type="image/svg+xml" href="/?asset=pwa/icon.svg">' .
            '<link rel="icon" type="image/png" sizes="192x192" href="/?asset=pwa/icon-192.png">' .
            '<link rel="apple-touch-icon" href="/?asset=pwa/apple-touch-icon.png">' .
            '<meta name="apple-mobile-web-app-capable" content="yes">' .
            '<meta name="mobile-web-app-capable" content="yes">' .
            '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' .
            '<meta name="apple-mobile-web-app-title" content="Healthspace">' .
            '<script>' .
            'if("serviceWorker" in navigator){' .
            'window.addEventListener("load",()=>{' .
            'navigator.serviceWorker.register("/?asset=pwa/sw.js",{scope:"/"});' .
            '});' .
            '}' .
            '</script>';
    }

    /**
     * @return array<string, string>
     */
    private function loadEnv(): array
    {
        $env = &$this->environment;
        if ($env !== null) {
            return $env;
        }
        $env = [];
        if (!file_exists(filename: $this->envFile)) {
            return $env;
        }
        foreach (file(filename: $this->envFile, flags: FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim(string: $line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = explode(separator: '=', string: $line, limit: 2);
            if (count(value: $parts) !== 2) {
                continue;
            }
            $env[trim(string: $parts[0])] = trim(string: $parts[1], characters: " \t\n\r\0\x0B\"'");
        }
        return $env;
    }

    /**
     * Keep OAuth credentials in the protected runtime directory.
     */
    private function googleHealthTokenFile(): string
    {
        return $this->dataDir . '/google_health.json';
    }

    /**
     * Detect a saved connection without refreshing tokens during page rendering.
     */
    private function isGoogleHealthConnected(): bool
    {
        if (!is_file(filename: $this->googleHealthTokenFile())) {
            return false;
        }
        $token = json_decode(
            json: (string) file_get_contents(filename: $this->googleHealthTokenFile()),
            associative: true
        );
        return is_array(value: $token) &&
            is_string(value: $token['refresh_token'] ?? null) &&
            $token['refresh_token'] !== '';
    }

    /**
     * Reuse the configured AI provider and the measurements database for meals.
     */
    private function nutrition(): Nutrition
    {
        $environment = $this->loadEnv();
        return new Nutrition(
            database: $this->openDatabase(),
            provider: (string) ($environment['AI_PROVIDER'] ?? 'openai'),
            model: (string) ($environment['AI_MODEL'] ?? 'gpt-5-nano'),
            apiKey: (string) ($environment['AI_API_KEY'] ?? ''),
            baseUrl: (string) ($environment['AI_BASE_URL'] ?? '')
        );
    }

    /**
     * Absolute callback URL — must be registered verbatim as an authorized
     * redirect URI in the Cloud console.
     */
    private function googleHealthRedirectUri(): string
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        $scheme = $https !== '' && $https !== 'off' ? 'https' : 'http';
        return $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? '') . '/';
    }

    /**
     * POST against Google's OAuth / Health endpoints (plain curl — these are
     * first-party JSON APIs that need no browser impersonation).
     *
     * @return array<string, mixed>|null decoded response, null on any error
     */
    private function postGoogle(string $url, string $body, string $contentType, string $bearer): ?array
    {
        $headers = ['Content-Type: ' . $contentType, 'Accept: application/json'];
        if ($bearer !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        $ch = curl_init(url: $url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array(
            handle: $ch,
            options: [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::FETCH_CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::FETCH_MAX_TIME_SECONDS
            ]
        );
        $response = curl_exec(handle: $ch);
        $status = (int) curl_getinfo(handle: $ch, option: CURLINFO_RESPONSE_CODE);
        if (!is_string(value: $response) || $status !== 200) {
            error_log(message: 'healthspace google health POST failed: HTTP ' . $status);
            return null;
        }
        $decoded = json_decode(json: $response, associative: true);
        return is_array(value: $decoded) ? $decoded : null;
    }

    /**
     * GET counterpart of postGoogle. Returns the decoded body even on an error
     * status, so callers can report what the API actually complained about.
     *
     * @return array<string, mixed>|null
     */
    private function getGoogle(string $url, string $bearer): ?array
    {
        $ch = curl_init(url: $url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array(
            handle: $ch,
            options: [
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearer, 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::FETCH_CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::FETCH_MAX_TIME_SECONDS
            ]
        );
        $response = curl_exec(handle: $ch);
        if (!is_string(value: $response)) {
            return null;
        }
        $decoded = json_decode(json: $response, associative: true);
        return is_array(value: $decoded) ? $decoded : null;
    }

    /**
     * Trade the stored refresh token for a short-lived access token.
     */
    private function googleHealthAccessToken(): ?string
    {
        $env = $this->loadEnv();
        $clientId = (string) ($env['GOOGLE_HEALTH_CLIENT_ID'] ?? '');
        $clientSecret = (string) ($env['GOOGLE_HEALTH_CLIENT_SECRET'] ?? '');
        if ($clientId === '' || $clientSecret === '' || !is_file(filename: $this->googleHealthTokenFile())) {
            return null;
        }
        $stored = json_decode(
            json: (string) file_get_contents(filename: $this->googleHealthTokenFile()),
            associative: true
        );
        $refreshToken = is_array(value: $stored) ? (string) ($stored['refresh_token'] ?? '') : '';
        if ($refreshToken === '') {
            return null;
        }
        $response = $this->postGoogle(
            url: self::GOOGLE_HEALTH_TOKEN_URL,
            body: http_build_query(
                data: [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret
                ]
            ),
            contentType: 'application/x-www-form-urlencoded',
            bearer: ''
        );
        $accessToken = (string) ($response['access_token'] ?? '');
        return $accessToken !== '' ? $accessToken : null;
    }

    /**
     * Kick off the consent flow (?health=connect) or persist the refresh token
     * Google hands back on the callback.
     */
    private function handleGoogleHealthOauth(string $step): void
    {
        $env = $this->loadEnv();
        $clientId = (string) ($env['GOOGLE_HEALTH_CLIENT_ID'] ?? '');
        $clientSecret = (string) ($env['GOOGLE_HEALTH_CLIENT_SECRET'] ?? '');
        header(header: 'Content-Type: text/plain; charset=utf-8');
        if ($clientId === '' || $clientSecret === '') {
            echo "GOOGLE_HEALTH_CLIENT_ID / GOOGLE_HEALTH_CLIENT_SECRET fehlen in .data/.env\n";
            return;
        }
        if ($step === 'connect') {
            $state = bin2hex(string: random_bytes(length: 32));
            setcookie('healthspace_oauth_state', $state, [
                'expires' => time() + 600,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $this->isHttps()
            ]);
            // prompt=consent forces a fresh refresh token even if the account
            // already granted the scope — otherwise Google omits it on re-auth.
            header(
                header: 'Location: ' .
                    self::GOOGLE_HEALTH_AUTH_URL .
                    '?' .
                    http_build_query(
                        data: [
                            'client_id' => $clientId,
                            'redirect_uri' => $this->googleHealthRedirectUri(),
                            'response_type' => 'code',
                            'scope' => self::GOOGLE_HEALTH_SCOPE,
                            'access_type' => 'offline',
                            'prompt' => 'consent',
                            'include_granted_scopes' => 'true',
                            'state' => $state
                        ]
                    ),
                response_code: 302
            );
            return;
        }
        $expectedState = (string) ($_COOKIE['healthspace_oauth_state'] ?? '');
        if (
            $expectedState === '' ||
            !hash_equals(known_string: $expectedState, user_string: (string) ($_GET['state'] ?? ''))
        ) {
            http_response_code(response_code: 400);
            echo "Ungültiger OAuth-Status. Bitte Google Health erneut verbinden.\n";
            return;
        }
        setcookie('healthspace_oauth_state', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $this->isHttps()
        ]);
        $error = (string) ($_GET['error'] ?? '');
        if ($error !== '') {
            echo 'Google hat die Freigabe abgelehnt: ' . $error . "\n";
            return;
        }
        $code = (string) ($_GET['code'] ?? '');
        if ($code === '') {
            echo "Kein Autorisierungscode erhalten.\n";
            return;
        }
        $response = $this->postGoogle(
            url: self::GOOGLE_HEALTH_TOKEN_URL,
            body: http_build_query(
                data: [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $this->googleHealthRedirectUri()
                ]
            ),
            contentType: 'application/x-www-form-urlencoded',
            bearer: ''
        );
        $refreshToken = (string) ($response['refresh_token'] ?? '');
        if ($refreshToken === '') {
            echo "Google hat kein Refresh-Token geliefert (siehe error_log).\n";
            return;
        }
        file_put_contents(
            filename: $this->googleHealthTokenFile(),
            data: (string) json_encode(value: ['refresh_token' => $refreshToken, 'created_at' => time()])
        );
        chmod(filename: $this->googleHealthTokenFile(), permissions: 0600);
        echo "Google Health verbunden. Bei der nächsten Synchronisierung werden die Uhrdaten geholt.\n";
    }

    /**
     * Pull daily rollups for every configured data type into health_days.
     */
    private function syncGoogleHealth(PDO $db, callable $emit): void
    {
        $accessToken = $this->googleHealthAccessToken();
        if ($accessToken === null) {
            $emit('  → übersprungen (nicht verbunden – /oauth/google-Flow über /?health=connect starten)');
            return;
        }
        $today = new DateTimeImmutable(datetime: 'today');
        $days = [];
        foreach (self::GOOGLE_HEALTH_DATA_TYPES as $dataType => $maxWindowDays) {
            $fetched = 0;
            for ($offset = 0; $offset < self::GOOGLE_HEALTH_HISTORY_DAYS; $offset += $maxWindowDays) {
                $chunkDays = min($maxWindowDays, self::GOOGLE_HEALTH_HISTORY_DAYS - $offset);
                $end = $today->modify(modifier: '-' . $offset . ' days');
                $start = $end->modify(modifier: '-' . ($chunkDays - 1) . ' days');
                $response = $this->postGoogle(
                    url: self::GOOGLE_HEALTH_BASE_URL . '/users/me/dataTypes/' . $dataType . '/dataPoints:dailyRollUp',
                    body: (string) json_encode(
                        value: [
                            'range' => [
                                'start' => [
                                    'date' => [
                                        'year' => (int) $start->format(format: 'Y'),
                                        'month' => (int) $start->format(format: 'n'),
                                        'day' => (int) $start->format(format: 'j')
                                    ]
                                ],
                                'end' => [
                                    'date' => [
                                        'year' => (int) $end->format(format: 'Y'),
                                        'month' => (int) $end->format(format: 'n'),
                                        'day' => (int) $end->format(format: 'j')
                                    ]
                                ]
                            ],
                            'windowSizeDays' => 1
                        ]
                    ),
                    contentType: 'application/json',
                    bearer: $accessToken
                );
                foreach ((array) ($response['rollupDataPoints'] ?? []) as $point) {
                    if (!is_array(value: $point)) {
                        continue;
                    }
                    $date = $point['civilStartTime']['date'] ?? null;
                    if (!is_array(value: $date)) {
                        continue;
                    }
                    $day = sprintf(
                        '%04d-%02d-%02d',
                        (int) ($date['year'] ?? 0),
                        (int) ($date['month'] ?? 0),
                        (int) ($date['day'] ?? 0)
                    );
                    $value = match ($dataType) {
                        'steps' => (int) ($point['steps']['countSum'] ?? 0),
                        'floors' => (int) ($point['floors']['countSum'] ?? 0),
                        default => (int) round(num: (float) ($point['totalCalories']['kcalSum'] ?? 0))
                    };
                    $column = match ($dataType) {
                        'steps' => 'steps',
                        'floors' => 'floors',
                        default => 'calories'
                    };
                    $days[$day][$column] = $value;
                    $fetched++;
                }
            }
            $emit(sprintf('  %s: %d Tage', $dataType, $fetched));
        }
        $this->collectGoogleHealthSleep(accessToken: $accessToken, today: $today, days: $days, emit: $emit);
        if ($days === []) {
            $emit('  → keine Daten erhalten');
            return;
        }
        $columns = [
            'steps',
            'floors',
            'calories',
            'sleep_minutes',
            'sleep_period_minutes',
            'sleep_onset_minutes',
            'nap_minutes',
            'nap_count',
            'sleep_deep_minutes',
            'sleep_light_minutes',
            'sleep_rem_minutes',
            'sleep_awake_minutes',
            'sleep_start',
            'sleep_end'
        ];
        // COALESCE keeps a previously stored value when this run didn't fetch
        // that metric — a partial API failure must not blank out history.
        $updates = array_map(
            callback: fn(string $c): string => $c . ' = COALESCE(excluded.' . $c . ', health_days.' . $c . ')',
            array: $columns
        );
        $stmt = $db->prepare(
            query: 'INSERT INTO health_days (day, ' .
                implode(separator: ', ', array: $columns) .
                ', updated_at)
            VALUES (:day, :' .
                implode(separator: ', :', array: $columns) .
                ', :updated)
            ON CONFLICT(day) DO UPDATE SET ' .
                implode(separator: ', ', array: $updates) .
                ', updated_at = excluded.updated_at'
        );
        $now = time();
        foreach ($days as $day => $values) {
            $params = [':day' => $day, ':updated' => $now];
            foreach ($columns as $column) {
                $params[':' . $column] = $values[$column] ?? null;
            }
            $stmt->execute(params: $params);
        }
        $emit(sprintf('  → %d Tage gespeichert', count(value: $days)));
    }

    /**
     * Merge all sleep sessions into per-day buckets without excluding naps.
     *
     * Three quirks of the live API drive the shape of this: responses carry
     * only UTC timestamps plus an offset (no civil time), interrupted nights
     * arrive as separate fragments, and the result is paged far below the
     * requested page size.
     *
     * @param array<string, array<string, int|string>> $days
     */
    private function collectGoogleHealthSleep(
        string $accessToken,
        DateTimeImmutable $today,
        array &$days,
        callable $emit
    ): void {
        $from = $today->modify(modifier: '-' . self::GOOGLE_HEALTH_HISTORY_DAYS . ' days')->format(format: 'Y-m-d');
        $until = $today->modify(modifier: '+1 day')->format(format: 'Y-m-d');
        $nights = [];
        $pageToken = '';
        $page = 0;
        do {
            $query = [
                'pageSize' => 1000,
                'filter' =>
                    'sleep.interval.civil_end_time >= "' .
                    $from .
                    '" AND sleep.interval.civil_end_time < "' .
                    $until .
                    '"'
            ];
            if ($pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }
            $decoded = $this->getGoogle(
                url: self::GOOGLE_HEALTH_BASE_URL .
                    '/users/me/dataTypes/sleep/dataPoints?' .
                    http_build_query(data: $query),
                bearer: $accessToken
            );
            if (!is_array(value: $decoded)) {
                $emit('  sleep: keine Antwort');
                return;
            }
            // A token minted before the sleep scope existed fails here, and the
            // only cure is a fresh consent — so say that instead of a bare error.
            $apiError = (string) ($decoded['error']['message'] ?? '');
            if ($apiError !== '') {
                $missingScope = ($decoded['error']['details'][0]['reason'] ?? '') === 'MISSING_OAUTH_SCOPE';
                $emit(
                    '  sleep: ' .
                        $apiError .
                        ($missingScope ? ' → /?health=connect erneut aufrufen (Schlaf-Scope fehlt im Token)' : '')
                );
                return;
            }
            foreach ((array) ($decoded['dataPoints'] ?? []) as $point) {
                $sleep = is_array(value: $point) ? $point['sleep'] ?? null : null;
                $endTimestamp = is_array(value: $sleep)
                    ? strtotime(datetime: (string) ($sleep['interval']['endTime'] ?? ''))
                    : false;
                if ($endTimestamp === false) {
                    continue;
                }
                $endOffset = (int) rtrim(
                    string: (string) ($sleep['interval']['endUtcOffset'] ?? '0s'),
                    characters: 's'
                );
                $localEnd = $endTimestamp + $endOffset;
                $endHour = (int) gmdate(format: 'G', timestamp: $localEnd);
                $day =
                    $endHour >= self::GOOGLE_HEALTH_NIGHT_FROM_HOUR
                        ? gmdate(format: 'Y-m-d', timestamp: $localEnd + 86400)
                        : gmdate(format: 'Y-m-d', timestamp: $localEnd);
                $summary = is_array(value: $sleep['summary'] ?? null) ? $sleep['summary'] : [];
                $startTimestamp = strtotime(datetime: (string) ($sleep['interval']['startTime'] ?? ''));
                $startOffset = (int) rtrim(
                    string: (string) ($sleep['interval']['startUtcOffset'] ?? '0s'),
                    characters: 's'
                );
                $localStart = $startTimestamp === false ? null : $startTimestamp + $startOffset;
                // Fragments add up: an interrupted night is still one night.
                $night = $nights[$day] ?? [];
                $night['sleep_minutes'] = (int) ($night['sleep_minutes'] ?? 0) + (int) ($summary['minutesAsleep'] ?? 0);
                $night['sleep_period_minutes'] =
                    (int) ($night['sleep_period_minutes'] ?? 0) + (int) ($summary['minutesInSleepPeriod'] ?? 0);
                $night['sleep_onset_minutes'] =
                    (int) ($night['sleep_onset_minutes'] ?? 0) + (int) ($summary['minutesToFallAsleep'] ?? 0);
                $night['nap_minutes'] = (int) ($night['nap_minutes'] ?? 0);
                $night['nap_count'] = (int) ($night['nap_count'] ?? 0);
                if ($localStart !== null) {
                    $startHour = (int) gmdate(format: 'G', timestamp: $localStart);
                    // Google also marks short night fragments as naps, so local daytime is the stable discriminator.
                    if (
                        $startHour >= self::GOOGLE_HEALTH_DAY_SLEEP_FROM_HOUR &&
                        $startHour < self::GOOGLE_HEALTH_NIGHT_FROM_HOUR
                    ) {
                        $night['nap_minutes'] += (int) ($summary['minutesAsleep'] ?? 0);
                        $night['nap_count']++;
                    }
                }
                foreach ((array) ($summary['stagesSummary'] ?? []) as $stage) {
                    $column = self::GOOGLE_HEALTH_SLEEP_STAGES[(string) ($stage['type'] ?? '')] ?? null;
                    if ($column !== null) {
                        $night[$column] = (int) ($night[$column] ?? 0) + (int) ($stage['minutes'] ?? 0);
                    }
                }
                $end = gmdate(format: 'Y-m-d H:i:s', timestamp: $localEnd);
                if (($night['sleep_end'] ?? '') < $end) {
                    $night['sleep_end'] = $end;
                }
                if ($localStart !== null) {
                    $start = gmdate(format: 'Y-m-d H:i:s', timestamp: $localStart);
                    if (!isset($night['sleep_start']) || $start < $night['sleep_start']) {
                        $night['sleep_start'] = $start;
                    }
                }
                $nights[$day] = $night;
            }
            $pageToken = (string) ($decoded['nextPageToken'] ?? '');
            $page++;
        } while ($pageToken !== '' && $page < 50);
        if ($pageToken !== '') {
            $emit('  sleep: unvollständige Seitenauswahl – bestehende Schlafdaten bleiben erhalten');
            return;
        }
        foreach ($nights as $day => $night) {
            $days[$day] = ($days[$day] ?? []) + $night;
        }
        $emit(sprintf('  sleep: %d Nächte', count(value: $nights)));
    }

    /**
     * Render manual measurements and the watch's daily rollups as Chart.js diagrams.
     */
    private function buildHealthBlock(string $page = 'all'): string
    {
        $measurementRows = $this->openDatabase()
            ->query(
                query: 'SELECT id, day, weight_kg, body_fat_percentage,
                    blood_pressure_systolic, blood_pressure_diastolic, pulse
                FROM health_measurements ORDER BY day DESC'
            )
            ->fetchAll(mode: PDO::FETCH_ASSOC);
        $editMeasurement = null;
        $editMeasurementId = filter_var(value: $_GET['edit_measurement'] ?? null, filter: FILTER_VALIDATE_INT);
        if ($editMeasurementId !== false && $editMeasurementId > 0) {
            foreach ($measurementRows as $measurementRow) {
                if ((int) $measurementRow['id'] === $editMeasurementId) {
                    $editMeasurement = $measurementRow;
                    break;
                }
            }
        }
        $formDay = (string) ($editMeasurement['day'] ?? date(format: 'Y-m-d'));
        $formWeight = (string) ($editMeasurement['weight_kg'] ?? '');
        $formBodyFat = (string) ($editMeasurement['body_fat_percentage'] ?? '');
        $formSystolic = (string) ($editMeasurement['blood_pressure_systolic'] ?? '');
        $formDiastolic = (string) ($editMeasurement['blood_pressure_diastolic'] ?? '');
        $formPulse = (string) ($editMeasurement['pulse'] ?? '');
        $formId =
            $editMeasurement !== null
                ? '<input type="hidden" name="measurement_id" value="' . (int) $editMeasurement['id'] . '">'
                : '';
        $formTitle = $editMeasurement !== null ? 'Messwerte bearbeiten' : 'Messwerte erfassen';
        $submitLabel = $editMeasurement !== null ? 'Änderungen speichern' : 'Speichern';
        $cancelLink =
            $editMeasurement !== null
                ? '<a class="health-entry__cancel" href="/?page=measurements#health-entry-form">Abbrechen</a>'
                : '';
        $message = '';
        $healthStatus = (string) ($_GET['health_status'] ?? '');
        if ($healthStatus === 'saved') {
            $message = '<p class="health-entry__message health-entry__message--success">Messwerte gespeichert.</p>';
        }
        if ($healthStatus === 'deleted') {
            $message = '<p class="health-entry__message health-entry__message--success">Messwerte gelöscht.</p>';
        }
        $healthError = trim(string: (string) ($_GET['health_error'] ?? ''));
        if ($healthError !== '') {
            $message =
                '<p class="health-entry__message health-entry__message--error">' .
                htmlspecialchars(string: $healthError, flags: ENT_QUOTES) .
                '</p>';
        }
        $measurementTableRows = '';
        foreach ($measurementRows as $measurementRow) {
            $weightLabel =
                $measurementRow['weight_kg'] !== null
                    ? number_format(
                            num: (float) $measurementRow['weight_kg'],
                            decimals: 1,
                            decimal_separator: ',',
                            thousands_separator: '.'
                        ) . ' kg'
                    : '–';
            $bodyFatLabel =
                $measurementRow['body_fat_percentage'] !== null
                    ? number_format(
                            num: (float) $measurementRow['body_fat_percentage'],
                            decimals: 1,
                            decimal_separator: ',',
                            thousands_separator: '.'
                        ) . ' %'
                    : '–';
            $bloodPressureLabel =
                $measurementRow['blood_pressure_systolic'] !== null
                    ? (int) $measurementRow['blood_pressure_systolic'] .
                        '/' .
                        (int) $measurementRow['blood_pressure_diastolic']
                    : '–';
            $pulseLabel = $measurementRow['pulse'] !== null ? (string) (int) $measurementRow['pulse'] : '–';
            $measurementTableRows .=
                '<tr>' .
                '<td><time datetime="' .
                htmlspecialchars(string: (string) $measurementRow['day'], flags: ENT_QUOTES) .
                '">' .
                date(format: 'd.m.Y', timestamp: (int) strtotime(datetime: (string) $measurementRow['day'])) .
                '</time></td>' .
                '<td>' .
                $weightLabel .
                '</td>' .
                '<td>' .
                $bodyFatLabel .
                '</td>' .
                '<td>' .
                $bloodPressureLabel .
                '</td>' .
                '<td>' .
                $pulseLabel .
                '</td>' .
                '<td class="health-entry__actions"><a class="health-entry__action" href="/?page=measurements&edit_measurement=' .
                (int) $measurementRow['id'] .
                '#health-entry-form" aria-label="Bearbeiten" title="Bearbeiten">' .
                '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4Zm9.5-13.5 4 4"/></svg></a>' .
                '<form method="post" action="/?page=measurements" onsubmit="return confirm(\'Eintrag wirklich löschen?\')">' .
                '<input type="hidden" name="health_action" value="delete">' .
                '<input type="hidden" name="measurement_id" value="' .
                (int) $measurementRow['id'] .
                '">' .
                '<button class="health-entry__action" type="submit" aria-label="Löschen" title="Löschen">' .
                '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5"/></svg></button></form></td>' .
                '</tr>';
        }
        $measurementTableContent =
            $measurementTableRows !== ''
                ? '<div class="health-entry__table-wrap"><table class="health-entry__table"><thead><tr>' .
                    '<th>Datum</th><th>Gewicht</th><th>Körperfettanteil</th><th>Blutdruck</th><th>Puls</th><th aria-label="Aktionen"></th>' .
                    '</tr></thead><tbody>' .
                    $measurementTableRows .
                    '</tbody></table></div>'
                : '<p class="health-entry__empty">Noch keine manuellen Messwerte vorhanden.</p>';
        $measurementTableHtml =
            '<section class="health-entry"><h2 class="health-entry__title">Messwerte</h2>' .
            $measurementTableContent .
            '</section>';
        $measurementFormHtml =
            '<section class="health-entry" id="health-entry-form">' .
            '<h2 class="health-entry__title">' .
            $formTitle .
            '</h2>' .
            $message .
            '<form class="health-entry__form" method="post" action="/?page=measurements">' .
            '<input type="hidden" name="health_action" value="save">' .
            $formId .
            '<label>Datum<input type="date" name="day" value="' .
            htmlspecialchars(string: $formDay, flags: ENT_QUOTES) .
            '" required></label>' .
            '<label>Gewicht (kg)<input type="number" name="weight_kg" value="' .
            htmlspecialchars(string: $formWeight, flags: ENT_QUOTES) .
            '" min="0.1" step="0.1" inputmode="decimal"></label>' .
            '<label>Körperfettanteil (%)<input type="number" name="body_fat_percentage" value="' .
            htmlspecialchars(string: $formBodyFat, flags: ENT_QUOTES) .
            '" min="0.1" max="100" step="0.1" inputmode="decimal"></label>' .
            '<label>Blutdruck systolisch<input type="number" name="blood_pressure_systolic" value="' .
            htmlspecialchars(string: $formSystolic, flags: ENT_QUOTES) .
            '" min="1" step="1" inputmode="numeric"></label>' .
            '<label>Blutdruck diastolisch<input type="number" name="blood_pressure_diastolic" value="' .
            htmlspecialchars(string: $formDiastolic, flags: ENT_QUOTES) .
            '" min="1" step="1" inputmode="numeric"></label>' .
            '<label>Puls<input type="number" name="pulse" value="' .
            htmlspecialchars(string: $formPulse, flags: ENT_QUOTES) .
            '" min="1" step="1" inputmode="numeric"></label>' .
            '<div class="health-entry__buttons"><button type="submit">' .
            $submitLabel .
            '</button>' .
            $cancelLink .
            '</div>' .
            '</form></section>';

        $measurementRowsAscending = array_reverse(array: $measurementRows);
        $measurementCharts = [];
        foreach (
            [
                ['id' => 'healthWeight', 'title' => 'Gewicht', 'column' => 'weight_kg'],
                ['id' => 'healthBodyFat', 'title' => 'Körperfettanteil', 'column' => 'body_fat_percentage'],
                ['id' => 'healthPulse', 'title' => 'Puls', 'column' => 'pulse']
            ]
            as $measurementChart
        ) {
            $chartRows = array_values(
                array: array_filter(
                    array: $measurementRowsAscending,
                    callback: fn(array $measurementRow): bool => $measurementRow[$measurementChart['column']] !== null
                )
            );
            $labels = array_map(
                callback: fn(array $measurementRow): string => date(
                    format: 'd.m.',
                    timestamp: (int) strtotime(datetime: (string) $measurementRow['day'])
                ),
                array: $chartRows
            );
            $values = array_map(
                callback: fn(array $measurementRow): float|int => $measurementChart['column'] === 'weight_kg' ||
                $measurementChart['column'] === 'body_fat_percentage'
                    ? round(num: (float) $measurementRow[$measurementChart['column']], precision: 1)
                    : (int) $measurementRow[$measurementChart['column']],
                array: $chartRows
            );
            $trend = [];
            $span = (int) floor(num: self::GOOGLE_HEALTH_TREND_WINDOW / 2);
            foreach ($values as $index => $ignored) {
                $from = max(0, $index - $span);
                $to = min(count(value: $values) - 1, $index + $span);
                $window = array_slice(array: $values, offset: $from, length: $to - $from + 1);
                $trend[] = round(num: array_sum(array: $window) / count(value: $window), precision: 2);
            }
            $measurementCharts[] = [
                'id' => $measurementChart['id'],
                'type' => 'line',
                'label' => $measurementChart['title'],
                'goal' => 0,
                'labels' => $labels,
                'data' => $values,
                'stacks' => [],
                'series' => [],
                'trend' => $trend,
                'points' => true,
                'beginAtZero' => false
            ];
        }
        $bloodPressureRows = array_values(
            array: array_filter(
                array: $measurementRowsAscending,
                callback: fn(array $measurementRow): bool => $measurementRow['blood_pressure_systolic'] !== null
            )
        );
        array_splice(
            array: $measurementCharts,
            offset: 2,
            length: 0,
            replacement: [
                [
                    'id' => 'healthBloodPressure',
                    'type' => 'line',
                    'label' => 'Blutdruck',
                    'goal' => 0,
                    'labels' => array_map(
                        callback: fn(array $measurementRow): string => date(
                            format: 'd.m.',
                            timestamp: (int) strtotime(datetime: (string) $measurementRow['day'])
                        ),
                        array: $bloodPressureRows
                    ),
                    'data' => [],
                    'stacks' => [],
                    'series' => [
                        [
                            'label' => 'Systolisch',
                            'data' => array_map(
                                callback: fn(array $measurementRow): int => (int) $measurementRow[
                                    'blood_pressure_systolic'
                                ],
                                array: $bloodPressureRows
                            )
                        ],
                        [
                            'label' => 'Diastolisch',
                            'data' => array_map(
                                callback: fn(array $measurementRow): int => (int) $measurementRow[
                                    'blood_pressure_diastolic'
                                ],
                                array: $bloodPressureRows
                            )
                        ]
                    ],
                    'trend' => [],
                    'points' => true,
                    'beginAtZero' => false
                ]
            ]
        );
        $measurementChartHtml = '';
        foreach ($measurementCharts as $measurementChart) {
            if ($page === 'overview' && $measurementChart['id'] !== 'healthWeight') {
                continue;
            }
            $measurementChartHtml .=
                '<div class="health__chart"><h3 class="health__chart-title">' .
                $measurementChart['label'] .
                '</h3><div class="health__canvas"><canvas id="' .
                $measurementChart['id'] .
                '"></canvas></div></div>';
        }
        if ($page === 'overview' || $page === 'measurements') {
            $payload = htmlspecialchars(string: (string) json_encode(value: $measurementCharts), flags: ENT_QUOTES);
            $latestWeight = '';
            foreach ($measurementRows as $measurementRow) {
                if ($page !== 'overview' || $measurementRow['weight_kg'] === null) {
                    continue;
                }
                $weightLevel = match (true) {
                    (float) $measurementRow['weight_kg'] <= self::HEALTH_WEIGHT_GOAL_KG => 'green',
                    (float) $measurementRow['weight_kg'] <= self::HEALTH_WEIGHT_AMBER_KG => 'amber',
                    default => 'red'
                };
                $latestWeight =
                    '<div class="health__light"><span class="health__lamp health__lamp--on health__lamp--' .
                    $weightLevel .
                    '" aria-hidden="true"></span><div class="health__light-text">' .
                    '<span class="health__light-title">Letztes Gewicht</span><strong class="health__light-value">' .
                    number_format(
                        num: (float) $measurementRow['weight_kg'],
                        decimals: 1,
                        decimal_separator: ',',
                        thousands_separator: '.'
                    ) .
                    ' kg</strong><span class="health__light-caption">Ziel ' .
                    self::HEALTH_WEIGHT_GOAL_KG .
                    ' kg · ' .
                    date(format: 'd.m.Y', timestamp: (int) strtotime(datetime: $measurementRow['day'])) .
                    '</span></div></div>';
                break;
            }
            return '<section class="health">' .
                $latestWeight .
                $measurementChartHtml .
                ($page === 'measurements' ? $measurementFormHtml . $measurementTableHtml : '') .
                '</section><script src="/?asset=js/chart.min.js&v=2"></script>' .
                '<script type="application/json" data-chart-config="' .
                $payload .
                '"></script>';
        }
        if ($page === 'activity') {
            $measurementChartHtml = '';
            $measurementTableHtml = '';
            $measurementFormHtml = '';
            $measurementCharts = [];
        }
        $rows = $this->openDatabase()
            ->query(
                query: 'SELECT day, steps, floors, calories, sleep_minutes, sleep_period_minutes, nap_minutes, nap_count,
                    sleep_onset_minutes, sleep_deep_minutes, sleep_light_minutes, sleep_rem_minutes,
                    sleep_awake_minutes, sleep_start, sleep_end
                FROM health_days ORDER BY day DESC LIMIT ' . self::GOOGLE_HEALTH_HISTORY_DAYS
            )
            ->fetchAll(mode: PDO::FETCH_ASSOC);
        if ($rows === []) {
            $connected = $this->isGoogleHealthConnected();
            $hint = $connected
                ? 'Verbunden, aber noch keine Daten – bei der nächsten Synchronisierung werden sie geholt.'
                : 'Noch nicht verbunden. <a href="/?health=connect">Google Health jetzt verbinden</a>.';
            $payload = htmlspecialchars(string: (string) json_encode(value: $measurementCharts), flags: ENT_QUOTES);
            return '<section class="health">' .
                $measurementChartHtml .
                '<p class="viewnav__empty">' .
                $hint .
                '</p>' .
                $measurementTableHtml .
                $measurementFormHtml .
                '</section><script src="/?asset=js/chart.min.js&v=2"></script>' .
                '<script type="application/json" data-chart-config="' .
                $payload .
                '"></script>';
        }
        $rows = array_reverse(array: $rows);
        $days = array_column(array: $rows, column_key: 'day');
        $steps = array_map(callback: 'intval', array: array_column(array: $rows, column_key: 'steps'));
        // The tiles summarise only days the watch actually recorded: a day it
        // was left on the charger would drag every average towards zero.
        $stepRows = array_values(
            array: array_filter(array: $rows, callback: fn(array $r): bool => (int) $r['steps'] > 0)
        );
        $stepDays = array_map(callback: fn(array $r): int => (int) $r['steps'], array: $stepRows);
        $latestDay = $stepRows === [] ? (string) end($days) : (string) end($stepRows)['day'];
        $latestSteps = $stepDays === [] ? 0 : (int) end($stepDays);
        $latestLabel =
            $latestDay === date(format: 'Y-m-d')
                ? 'Schritte heute'
                : 'Schritte am ' . date(format: 'd.m.', timestamp: (int) strtotime(datetime: $latestDay));
        $lastSeven = array_slice(array: $stepDays, offset: -7);
        $average = $lastSeven === [] ? 0 : (int) round(num: array_sum(array: $lastSeven) / count(value: $lastSeven));
        $best = $stepDays === [] ? 0 : max($stepDays);
        $goalDays = count(
            value: array_filter(
                array: $lastSeven,
                callback: fn(int $value): bool => $value >= self::GOOGLE_HEALTH_STEP_GOAL
            )
        );
        $kpis = [
            [
                'value' => number_format(
                    num: $latestSteps,
                    decimals: 0,
                    decimal_separator: ',',
                    thousands_separator: '.'
                ),
                'label' => $latestLabel
            ],
            [
                'value' => number_format(num: $average, decimals: 0, decimal_separator: ',', thousands_separator: '.'),
                'label' => 'Ø Schritte ' . count(value: $lastSeven) . ' Tage'
            ],
            [
                'value' => number_format(num: $best, decimals: 0, decimal_separator: ',', thousands_separator: '.'),
                'label' => 'Bester Tag'
            ],
            ['value' => $goalDays . ' / ' . count(value: $lastSeven), 'label' => 'Ziel erreicht']
        ];
        $sleepRows = array_values(
            array: array_filter(array: $rows, callback: fn(array $r): bool => (int) $r['sleep_minutes'] > 0)
        );
        $sleepAverage = null;
        if ($sleepRows !== []) {
            $lastNight = end($sleepRows);
            $recentNights = array_slice(array: $sleepRows, offset: -7);
            $sleepAverage = (int) round(
                num: array_sum(
                    array: array_map(callback: fn(array $r): int => (int) $r['sleep_minutes'], array: $recentNights)
                ) / count(value: $recentNights)
            );
            $periodSum = array_sum(
                array: array_map(callback: fn(array $r): int => (int) $r['sleep_period_minutes'], array: $recentNights)
            );
            $asleepSum = array_sum(
                array: array_map(callback: fn(array $r): int => (int) $r['sleep_minutes'], array: $recentNights)
            );
            $efficiency = $periodSum > 0 ? (int) round(num: ($asleepSum / $periodSum) * 100) : 0;
            $deepSum = array_sum(
                array: array_map(callback: fn(array $r): int => (int) $r['sleep_deep_minutes'], array: $recentNights)
            );
            $deepShare = $asleepSum > 0 ? (int) round(num: ($deepSum / $asleepSum) * 100) : 0;
            $remSum = array_sum(
                array: array_map(callback: fn(array $r): int => (int) $r['sleep_rem_minutes'], array: $recentNights)
            );
            $remShare = $asleepSum > 0 ? (int) round(num: ($remSum / $asleepSum) * 100) : 0;
            $onsetAverage = (int) round(
                num: array_sum(
                    array: array_map(
                        callback: fn(array $r): int => (int) $r['sleep_onset_minutes'],
                        array: $recentNights
                    )
                ) / count(value: $recentNights)
            );
            $bedtime =
                $lastNight['sleep_start'] !== null
                    ? date(format: 'H:i', timestamp: (int) strtotime(datetime: (string) $lastNight['sleep_start']))
                    : '–';
            $wakeup =
                $lastNight['sleep_end'] !== null
                    ? date(format: 'H:i', timestamp: (int) strtotime(datetime: (string) $lastNight['sleep_end']))
                    : '–';
            $formatDuration = fn(int $minutes): string => intdiv($minutes, 60) .
                ':' .
                str_pad(string: (string) ($minutes % 60), length: 2, pad_string: '0', pad_type: STR_PAD_LEFT) .
                ' h';
            $kpis[] = [
                'value' => $formatDuration((int) $lastNight['sleep_minutes']),
                'label' =>
                    'Schlaf in der Nacht auf ' .
                    date(format: 'd.m.', timestamp: (int) strtotime(datetime: (string) $lastNight['day']))
            ];
            $kpis[] = [
                'value' => $formatDuration($sleepAverage),
                'label' => 'Ø Schlaf ' . count(value: $recentNights) . ' Nächte'
            ];
            $kpis[] = ['value' => $efficiency . ' %', 'label' => 'Schlafeffizienz'];
            $kpis[] = [
                'value' => $bedtime . ' – ' . $wakeup,
                'label' =>
                    'Bettzeit am ' .
                    date(format: 'd.m.', timestamp: (int) strtotime(datetime: (string) $lastNight['day']))
            ];
            $longestNight = $sleepRows[0];
            foreach ($sleepRows as $candidate) {
                if ((int) $candidate['sleep_minutes'] > (int) $longestNight['sleep_minutes']) {
                    $longestNight = $candidate;
                }
            }
            $kpis[] = [
                'value' => $formatDuration((int) $longestNight['sleep_minutes']),
                'label' =>
                    'Längste Nacht (' .
                    date(format: 'd.m.', timestamp: (int) strtotime(datetime: (string) $longestNight['day'])) .
                    ')'
            ];
            $kpis[] = ['value' => $formatDuration((int) $lastNight['sleep_period_minutes']), 'label' => 'Zeit im Bett'];
            $kpis[] = ['value' => $deepShare . ' %', 'label' => 'Ø Tiefschlaf-Anteil'];
            $kpis[] = ['value' => $remShare . ' %', 'label' => 'Ø REM-Anteil'];
            // Fitbit-derived sessions report no onset time — skip the tile
            // rather than showing a permanent zero.
            if ($onsetAverage > 0) {
                $kpis[] = ['value' => $onsetAverage . ' min', 'label' => 'Ø Einschlafdauer'];
            }
            $recentNapMinutes = array_sum(
                array: array_map(callback: fn(array $r): int => (int) $r['nap_minutes'], array: $recentNights)
            );
            $recentNapCount = array_sum(
                array: array_map(callback: fn(array $r): int => (int) $r['nap_count'], array: $recentNights)
            );
            $averageNapMinutes = $recentNapCount > 0 ? (int) round(num: $recentNapMinutes / $recentNapCount) : null;
            $previousNightCount = min(7, max(0, count(value: $sleepRows) - count(value: $recentNights)));
            $previousNights =
                $previousNightCount > 0
                    ? array_slice(
                        array: $sleepRows,
                        offset: -count(value: $recentNights) - $previousNightCount,
                        length: $previousNightCount
                    )
                    : [];
            $previousAverage =
                $previousNights !== []
                    ? (int) round(
                        num: array_sum(
                            array: array_map(
                                callback: fn(array $r): int => (int) $r['sleep_minutes'],
                                array: $previousNights
                            )
                        ) / count(value: $previousNights)
                    )
                    : null;
            $trendDifference = $previousAverage !== null ? $sleepAverage - $previousAverage : null;
            $goalDifference = $sleepAverage - self::GOOGLE_HEALTH_SLEEP_GOAL;
            $sleepGoalDays = count(
                value: array_filter(
                    array: $recentNights,
                    callback: fn(array $r): bool => (int) $r['sleep_minutes'] >= self::GOOGLE_HEALTH_SLEEP_GOAL
                )
            );
            $kpis[] = [
                'value' => $averageNapMinutes !== null ? $formatDuration($averageNapMinutes) : '–',
                'label' => 'Ø Mittagsschlaf · ' . $recentNapCount . ' Nickerchen'
            ];
            $kpis[] = [
                'value' =>
                    $trendDifference !== null
                        ? ($trendDifference >= 0 ? '+' : '−') . $formatDuration(abs($trendDifference))
                        : '–',
                'label' => 'vs. vorige ' . count(value: $previousNights) . ' Nächte'
            ];
            $kpis[] = [
                'value' => $sleepGoalDays . ' / ' . count(value: $recentNights),
                'label' => 'Schlafziel ≥ ' . intdiv(self::GOOGLE_HEALTH_SLEEP_GOAL, 60) . ' h'
            ];
            $kpis[] = [
                'value' => ($goalDifference >= 0 ? '+' : '−') . $formatDuration(abs($goalDifference)),
                'label' => 'Ø zum ' . intdiv(self::GOOGLE_HEALTH_SLEEP_GOAL, 60) . '-h-Ziel'
            ];
        }
        $kpiHtml = '';
        foreach ($kpis as $kpi) {
            $kpiHtml .=
                '<div class="health__kpi"><span class="health__kpi-value">' .
                $kpi['value'] .
                '</span><span class="health__kpi-label">' .
                $kpi['label'] .
                '</span></div>';
        }
        // Traffic lights reduce the actionable metrics to a red/amber/green verdict.
        $lights = [];
        if ($lastSeven !== []) {
            $lights[] = [
                'title' => 'Schritte',
                'caption' =>
                    'Ø ' .
                    count(value: $lastSeven) .
                    ' Tage · Ziel ' .
                    number_format(
                        num: self::GOOGLE_HEALTH_STEP_GOAL,
                        decimals: 0,
                        decimal_separator: ',',
                        thousands_separator: '.'
                    ),
                'value' => number_format(num: $average, decimals: 0, decimal_separator: ',', thousands_separator: '.'),
                'level' => match (true) {
                    $average >= self::GOOGLE_HEALTH_STEP_GOAL => 'green',
                    $average >= self::GOOGLE_HEALTH_STEP_AMBER => 'amber',
                    default => 'red'
                }
            ];
        }
        if ($sleepAverage !== null) {
            $lights[] = [
                'title' => 'Schlaf',
                'caption' =>
                    'Ø ' .
                    count(value: $recentNights) .
                    ' Nächte · Ziel ' .
                    intdiv(self::GOOGLE_HEALTH_SLEEP_GOAL, 60) .
                    ' h',
                'value' =>
                    intdiv($sleepAverage, 60) .
                    ':' .
                    str_pad(string: (string) ($sleepAverage % 60), length: 2, pad_string: '0', pad_type: STR_PAD_LEFT) .
                    ' h',
                'level' => match (true) {
                    $sleepAverage >= self::GOOGLE_HEALTH_SLEEP_GOAL => 'green',
                    $sleepAverage >= self::GOOGLE_HEALTH_SLEEP_AMBER => 'amber',
                    default => 'red'
                }
            ];
        }
        foreach ($measurementRows as $measurementRow) {
            if ($page === 'activity') {
                break;
            }
            if ($measurementRow['weight_kg'] === null) {
                continue;
            }
            $latestWeight = (float) $measurementRow['weight_kg'];
            $lights[] = [
                'title' => 'Gewicht',
                'caption' =>
                    'Zuletzt ' .
                    date(format: 'd.m.', timestamp: (int) strtotime(datetime: (string) $measurementRow['day'])) .
                    ' · Ziel ' .
                    self::HEALTH_WEIGHT_GOAL_KG .
                    ' kg',
                'value' =>
                    number_format(num: $latestWeight, decimals: 1, decimal_separator: ',', thousands_separator: '.') .
                    ' kg',
                'level' => match (true) {
                    $latestWeight <= self::HEALTH_WEIGHT_GOAL_KG => 'green',
                    $latestWeight <= self::HEALTH_WEIGHT_AMBER_KG => 'amber',
                    default => 'red'
                }
            ];
            break;
        }
        $lightHtml = '';
        foreach ($lights as $light) {
            $lamps = '';
            foreach (['red', 'amber', 'green'] as $level) {
                $lamps .=
                    '<span class="health__lamp health__lamp--' .
                    $level .
                    ($light['level'] === $level ? ' health__lamp--on' : '') .
                    '"></span>';
            }
            $lightHtml .=
                '<div class="health__light">' .
                '<div class="health__lamps" role="img" aria-label="' .
                $light['title'] .
                ': ' .
                $light['level'] .
                '">' .
                $lamps .
                '</div>' .
                '<div class="health__light-text">' .
                '<span class="health__light-value">' .
                $light['value'] .
                '</span>' .
                '<span class="health__light-title">' .
                $light['title'] .
                '</span>' .
                '<span class="health__light-caption">' .
                $light['caption'] .
                '</span>' .
                '</div></div>';
        }
        if ($lightHtml !== '') {
            $lightHtml = '<div class="health__lights">' . $lightHtml . '</div>';
        }
        $sleepWithoutStages = array_map(
            callback: function (array $r): float {
                $classifiedSleep =
                    (int) $r['sleep_deep_minutes'] + (int) $r['sleep_light_minutes'] + (int) $r['sleep_rem_minutes'];
                return round(num: max(0, (int) $r['sleep_minutes'] - $classifiedSleep) / 60, precision: 2);
            },
            array: $rows
        );
        $charts = [
            [
                'id' => 'healthSleep',
                'title' => 'Schlaf',
                'type' => 'bar',
                'goal' => 0,
                'data' => array_map(
                    callback: fn(array $r): float => round(num: ((int) $r['sleep_minutes']) / 60, precision: 2),
                    array: $rows
                ),
                'stacks' => [
                    [
                        'label' => 'Tief',
                        'data' => array_map(
                            callback: fn(array $r): float => round(
                                num: ((int) $r['sleep_deep_minutes']) / 60,
                                precision: 2
                            ),
                            array: $rows
                        )
                    ],
                    [
                        'label' => 'REM',
                        'data' => array_map(
                            callback: fn(array $r): float => round(
                                num: ((int) $r['sleep_rem_minutes']) / 60,
                                precision: 2
                            ),
                            array: $rows
                        )
                    ],
                    [
                        'label' => 'Leicht',
                        'data' => array_map(
                            callback: fn(array $r): float => round(
                                num: ((int) $r['sleep_light_minutes']) / 60,
                                precision: 2
                            ),
                            array: $rows
                        )
                    ],
                    ['label' => 'Ohne Phasen', 'data' => $sleepWithoutStages],
                    [
                        'label' => 'Wach',
                        'data' => array_map(
                            callback: fn(array $r): float => round(
                                num: ((int) $r['sleep_awake_minutes']) / 60,
                                precision: 2
                            ),
                            array: $rows
                        )
                    ]
                ]
            ],
            [
                'id' => 'healthSteps',
                'title' => 'Schritte',
                'type' => 'bar',
                'goal' => self::GOOGLE_HEALTH_STEP_GOAL,
                'data' => $steps
            ],
            [
                'id' => 'healthCalories',
                'title' => 'Kalorienverbrauch',
                'type' => 'bar',
                'goal' => 0,
                'data' => array_map(callback: 'intval', array: array_column(array: $rows, column_key: 'calories'))
            ]
        ];
        $chartHtml = '';
        $chartConfig = [];
        foreach ($charts as $chart) {
            // Days the watch recorded nothing for are left out entirely rather
            // than drawn as zero — a gap in the series would read as "walked
            // nothing" instead of "wasn't wearing it".
            $labels = [];
            $values = [];
            $stacks = array_map(
                callback: fn(array $s): array => ['label' => $s['label'], 'data' => []],
                array: $chart['stacks'] ?? []
            );
            foreach ($chart['data'] as $index => $value) {
                if ($value <= 0) {
                    continue;
                }
                $labels[] = date(format: 'd.m.', timestamp: (int) strtotime(datetime: $days[$index]));
                $values[] = $value;
                foreach ($chart['stacks'] ?? [] as $stackIndex => $stack) {
                    $stacks[$stackIndex]['data'][] = $stack['data'][$index];
                }
            }
            // A metric the account never recorded at all gets no card.
            if ($values === []) {
                continue;
            }
            // Centred moving average — the daily series is far too noisy to
            // read a direction off, the trend line is what the chart is for.
            $trend = [];
            $span = (int) floor(num: self::GOOGLE_HEALTH_TREND_WINDOW / 2);
            foreach ($values as $index => $ignored) {
                $from = max(0, $index - $span);
                $to = min(count(value: $values) - 1, $index + $span);
                $window = array_slice(array: $values, offset: $from, length: $to - $from + 1);
                $trend[] = round(num: array_sum(array: $window) / count(value: $window), precision: 2);
            }
            $chartHtml .=
                '<div class="health__chart"><h3 class="health__chart-title">' .
                $chart['title'] .
                '</h3><div class="health__canvas"><canvas id="' .
                $chart['id'] .
                '"></canvas></div></div>';
            $chartConfig[] = [
                'id' => $chart['id'],
                'type' => $chart['type'],
                'label' => $chart['title'],
                'goal' => $chart['goal'],
                'labels' => $labels,
                'data' => $values,
                'stacks' => $stacks,
                'trend' => $trend
            ];
        }
        $chartConfig = array_merge($chartConfig, $measurementCharts);
        $payload = htmlspecialchars(string: (string) json_encode(value: $chartConfig), flags: ENT_QUOTES);
        return '<section class="health">' .
            $lightHtml .
            $chartHtml .
            '<div class="health__kpis">' .
            $kpiHtml .
            '</div>' .
            $measurementChartHtml .
            $measurementTableHtml .
            $measurementFormHtml .
            '</section><script src="/?asset=js/chart.min.js&v=2"></script>' .
            '<script type="application/json" data-chart-config="' .
            $payload .
            '"></script>';
    }

    /**
     * Last seven recorded days against the seven before them, for steps and
     * sleep. Only days the watch actually recorded count, matching the
     * dashboard's tiles.
     *
     * @return array{steps?: array{average: int, change: ?int, days: int}, sleep?: array{average: int, change: ?int, days: int}}
     */
    private function healthDigestStats(): array
    {
        try {
            $rows = $this->openDatabase()
                ->query(
                    query: 'SELECT day, steps, sleep_minutes, sleep_period_minutes, sleep_deep_minutes,
                        sleep_light_minutes, sleep_rem_minutes, sleep_awake_minutes
                    FROM health_days ORDER BY day DESC LIMIT 60'
                )
                ->fetchAll(mode: PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log(message: 'healthspace health digest failed: ' . $e->getMessage());
            return [];
        }
        $stepValues = array_values(
            array: array_map(
                callback: fn(array $r): int => (int) $r['steps'],
                array: array_filter(array: $rows, callback: fn(array $r): bool => (int) $r['steps'] > 0)
            )
        );
        $sleepValues = array_values(
            array: array_map(
                callback: fn(array $r): int => (int) $r['sleep_minutes'],
                array: array_filter(array: $rows, callback: fn(array $r): bool => (int) $r['sleep_minutes'] > 0)
            )
        );
        // Rows arrive newest-first, so the first seven are the current week.
        $trend = function (array $values): ?array {
            if (count(value: $values) < 4) {
                return null;
            }
            $current = array_slice(array: $values, offset: 0, length: 7);
            $previous = array_slice(array: $values, offset: 7, length: 7);
            $currentAverage = array_sum(array: $current) / count(value: $current);
            $previousAverage = $previous === [] ? null : array_sum(array: $previous) / count(value: $previous);
            $change =
                $previousAverage === null || $previousAverage <= 0
                    ? null
                    : (int) round(num: (($currentAverage - $previousAverage) / $previousAverage) * 100);
            return ['average' => $currentAverage, 'change' => $change, 'days' => count(value: $current)];
        };
        $stats = [];
        $steps = $trend($stepValues);
        if ($steps !== null) {
            $stats['steps'] = [
                'average' => (int) round(num: $steps['average']),
                'change' => $steps['change'],
                'days' => $steps['days']
            ];
        }
        $sleep = $trend($sleepValues);
        if ($sleep !== null) {
            $stats['sleep'] = [
                'average' => (int) round(num: $sleep['average']),
                'change' => $sleep['change'],
                'days' => $sleep['days']
            ];
        }
        return $stats;
    }

    /**
     * Summarise recorded activity and sleep trends without medical advice.
     *
     * @param array{steps?: array{average: int, change: ?int, days: int}, sleep?: array{average: int, change: ?int, days: int}} $stats
     */
    private function generateHealthProse(array $stats, array $aiConfig, string $apiKey): ?string
    {
        if ($stats === [] || !class_exists(class: 'vielhuber\\aihelper\\aihelper')) {
            return null;
        }
        $provider = (string) ($aiConfig['provider'] ?? '');
        $model = (string) ($aiConfig['model'] ?? '');
        if ($provider === '' || $model === '' || $apiKey === '') {
            return null;
        }
        $describeChange = fn(?int $change): string => $change === null
            ? 'kein Vorwochenwert vorhanden'
            : ($change === 0
                ? 'unverändert'
                : ($change > 0
                    ? '+' . $change . ' % gegenüber der Vorwoche'
                    : $change . ' % gegenüber der Vorwoche'));
        $lines = [];
        if (isset($stats['steps'])) {
            $lines[] = sprintf(
                'Schritte: %d pro Tag im Schnitt (%d Tage gewertet), %s. Tagesziel: %d.',
                $stats['steps']['average'],
                $stats['steps']['days'],
                $describeChange($stats['steps']['change']),
                self::GOOGLE_HEALTH_STEP_GOAL
            );
        }
        if (isset($stats['sleep'])) {
            $lines[] = sprintf(
                'Schlaf: %d:%02d Stunden pro Nacht im Schnitt (%d Nächte gewertet), %s. Zielwert: %d:00 Stunden.',
                intdiv($stats['sleep']['average'], 60),
                $stats['sleep']['average'] % 60,
                $stats['sleep']['days'],
                $describeChange($stats['sleep']['change']),
                intdiv(self::GOOGLE_HEALTH_SLEEP_GOAL, 60)
            );
        }
        $prompt =
            'Schreibe einen flüssigen, prägnanten Absatz auf Deutsch (2 bis 3 Sätze) über die ' .
            "Fitness-Woche des Lesers, basierend auf diesen Messwerten seiner Smartwatch:\n\n" .
            implode(separator: "\n", array: $lines) .
            "\n\n" .
            "Anforderungen:\n" .
            "- Sprich den Leser mit \"du\" an.\n" .
            "- Keine Aufzählung, keine Tabelle, keine Wiederholung der Rohwerte als Liste.\n" .
            '- Interpretiere den Trend: mehr/weniger Bewegung, besserer/schlechterer Schlaf, ' .
            "und ob die Zielwerte erreicht werden.\n" .
            "- Wenn Schlaf und Bewegung gegenläufig sind, benenne das als Spannung.\n" .
            "- Bleib sachlich und ohne medizinische Ratschläge; keine Diagnosen, keine Warnungen vor Krankheiten.\n" .
            "- Hebe 1 bis 2 zentrale Zahlen mit Markdown-Bold (**…**) hervor.\n" .
            '- Antworte AUSSCHLIESSLICH mit dem Fließtext, ohne Anführungszeichen, ohne Codeblock, ohne Vorrede.';
        try {
            $aiClass = 'vielhuber\\aihelper\\aihelper';
            $aiUrl = (string) ($aiConfig['url'] ?? '');
            $ai = $aiClass::create(
                provider: $provider,
                model: $model,
                temperature: (float) ($aiConfig['temperature'] ?? 0.4),
                api_key: $apiKey,
                max_tries: (int) ($aiConfig['max_tries'] ?? 2),
                timeout: (int) ($aiConfig['timeout'] ?? 60),
                url: $aiUrl !== '' ? $aiUrl : null
            );
            $resp = $ai->ask(prompt: $prompt)['response'] ?? null;
        } catch (\Exception $exception) {
            return null;
        }
        if (is_object(value: $resp) || is_array(value: $resp)) {
            $resp = json_encode(value: $resp);
        }
        $text = trim(string: (string) $resp);
        $text = (string) preg_replace(pattern: '~^\s*```(?:\w+)?\s*|\s*```\s*$~i', replacement: '', subject: $text);
        $text = trim(string: $text, characters: " \t\n\r\0\x0B\"'");
        return $text !== '' ? $text : null;
    }

    /**
     * Prefers the LLM prose stored with the digest; falls back to a minimal
     * generated sentence pair so the section survives an AI outage.
     *
     * @param array{prose?: string} $health
     */
    private function buildHealthDigestBlock(array $health): string
    {
        $prose = trim(string: (string) ($health['prose'] ?? ''));
        if ($prose !== '') {
            $escaped = htmlspecialchars(string: $prose, flags: ENT_QUOTES);
            $escaped = (string) preg_replace(
                pattern: '/\*\*(.+?)\*\*/s',
                replacement: '<strong>$1</strong>',
                subject: $escaped
            );
            $body = '<p>' . $escaped . '</p>';
        } else {
            $stats = $this->healthDigestStats();
            $sentences = [];
            if (isset($stats['steps'])) {
                $sentences[] =
                    'Du gehst im Schnitt <strong>' .
                    number_format(
                        num: $stats['steps']['average'],
                        decimals: 0,
                        decimal_separator: ',',
                        thousands_separator: '.'
                    ) .
                    ' Schritte</strong> pro Tag.';
            }
            if (isset($stats['sleep'])) {
                $sentences[] =
                    'Geschlafen hast du <strong>' .
                    intdiv($stats['sleep']['average'], 60) .
                    ':' .
                    str_pad(
                        string: (string) ($stats['sleep']['average'] % 60),
                        length: 2,
                        pad_string: '0',
                        pad_type: STR_PAD_LEFT
                    ) .
                    ' Stunden</strong> pro Nacht.';
            }
            if ($sentences === []) {
                return '';
            }
            $body = '<p>' . implode(separator: ' ', array: $sentences) . '</p>';
        }
        return '<div class="digest__health">' .
            '<h2 class="digest__title">Gesundheit <span class="digest__date">7-Tage-Trend</span></h2>' .
            $body .
            '</div>';
    }

    /**
     * Return cached prose without making a damaged cache fatal to the dashboard.
     */
    private function cacheGet(string $key): ?string
    {
        // A cache row sitting on a corrupt page (torn DB copy) must degrade
        // to a cache miss instead of taking the whole page down — the entry
        // regenerates on the next fetch anyway.
        try {
            $stmt = $this->openDatabase()->prepare(query: 'SELECT value FROM cache WHERE key = :k');
            $stmt->execute(params: [':k' => $key]);
            $v = $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log(message: 'healthspace cacheGet("' . $key . '") failed: ' . $e->getMessage());
            return null;
        }
        return $v === false ? null : (string) $v;
    }

    /**
     * Persist the generated summary independently of measurements.
     */
    private function cacheSet(string $key, string $value): void
    {
        try {
            $stmt = $this->openDatabase()->prepare(
                query: 'INSERT INTO cache (key, value, updated_at) VALUES (:k, :v, :t)
                        ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
            );
            $stmt->execute(params: [':k' => $key, ':v' => $value, ':t' => time()]);
        } catch (PDOException $e) {
            error_log(message: 'healthspace cacheSet("' . $key . '") failed: ' . $e->getMessage());
        }
    }

    /**
     * Upgrade existing databases without replacing stored measurements.
     */
    private function addColumnIfMissing(PDO $db, string $table, string $column, string $definition): void
    {
        $columns = array_column(
            array: $db->query(query: 'PRAGMA table_info(' . $table . ')')->fetchAll(mode: PDO::FETCH_ASSOC),
            column_key: 'name'
        );
        if (in_array(needle: $column, haystack: $columns, strict: true)) {
            return;
        }
        $db->exec(statement: 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}
