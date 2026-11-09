<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/healthspace-http-' . bin2hex(random_bytes(8));
mkdir($root . '/.data', 0700, true);
file_put_contents(
    $root . '/.data/.env',
    "AUTH_PASSWORD=fixture-password\nGOOGLE_HEALTH_CLIENT_ID=fixture\nGOOGLE_HEALTH_CLIENT_SECRET=fixture\n"
);
$source = dirname(__DIR__) . '/src/Healthspace.php';
file_put_contents(
    $root . '/index.php',
    '<?php require ' .
        var_export($source, true) .
        '; (new \\vielhuber\\healthspace\\Healthspace(rootDir: __DIR__))->run();'
);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$process = proc_open(
    [PHP_BINARY, '-S', $address, '-t', $root],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $root . '/server.log', 'a'],
        2 => ['file', $root . '/server.log', 'a']
    ],
    $pipes
);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start the HTTP fixture.');
}
fclose($pipes[0]);
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEFILE => '',
    CURLOPT_TIMEOUT => 5
]);
$headers = [];
curl_setopt($curl, CURLOPT_HEADERFUNCTION, static function ($handle, string $header) use (&$headers): int {
    $parts = explode(':', $header, 2);
    if (count($parts) === 2) {
        $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
    }
    return strlen($header);
});
$request = static function (string $path, ?array $post = null) use ($curl, $address, &$headers): array {
    $headers = [];
    curl_setopt($curl, CURLOPT_URL, 'http://' . $address . $path);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    if ($post === null) {
        curl_setopt($curl, CURLOPT_HTTPGET, true);
    }
    $body = curl_exec($curl);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => (string) $body];
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
try {
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $response = $request('/');
        if ($response['status'] !== 0) {
            break;
        }
        usleep(50000);
    }
    $assert(
        $response['status'] === 200 && str_contains($response['body'], 'healthspace — Login'),
        'Login gate missing.'
    );
    $assert($request('/?scrape=1&key=wrong')['status'] === 403, 'Unauthorised cron accepted.');
    $asset = $request('/?asset=css/healthspace.css');
    $assert(
        $asset['status'] === 200 && str_contains($asset['headers']['content-type'], 'text/css'),
        'Bundled CSS unavailable.'
    );
    $assert($request('/?asset=css/../../.data/.env')['status'] === 404, 'Asset traversal accepted.');
    $assert(
        str_contains($request('/', ['auth_password' => 'wrong'])['body'], 'Falsches Passwort'),
        'Wrong login accepted.'
    );
    $login = $request('/', ['auth_password' => 'fixture-password']);
    $assert(
        $login['status'] === 302 && str_contains($login['headers']['set-cookie'], 'healthspace_auth='),
        'Session missing.'
    );
    $assert(str_contains(strtolower($login['headers']['set-cookie']), 'httponly'), 'Session cookie lacks HttpOnly.');
    $dashboard = $request('/');
    $assert(($dashboard['headers']['cache-control'] ?? '') === 'no-store', 'Private health pages may be cached.');
    $assert(
        str_contains($dashboard['body'], 'healthWeight') && !str_contains($dashboard['body'], 'extrablatt'),
        'Standalone dashboard missing.'
    );
    $save = $request('/', ['health_action' => 'save', 'day' => '2026-11-01', 'weight_kg' => '104.5']);
    $assert(
        $save['status'] === 303 && str_contains($save['headers']['location'], 'health_status=saved'),
        'Measurement redirect failed.'
    );
    $assert(str_contains($request('/')['body'], '104,5 kg'), 'HTTP measurement did not persist.');
    $connect = $request('/?health=connect');
    parse_str((string) parse_url($connect['headers']['location'], PHP_URL_QUERY), $oauth);
    $assert($connect['status'] === 302 && strlen($oauth['state']) === 64, 'OAuth state missing.');
    $assert($oauth['redirect_uri'] === 'http://' . $address . '/', 'OAuth callback points at another app.');
    $assert($request('/?state=invalid&error=access_denied')['status'] === 400, 'OAuth state mismatch accepted.');
    $denied = $request('/?state=' . $oauth['state'] . '&error=access_denied');
    $assert(str_contains($denied['body'], 'Freigabe abgelehnt'), 'OAuth denial not handled.');
    $sync = $request('/?scrape=1');
    $assert(
        $sync['status'] === 200 && str_contains($sync['body'], 'Synchronisierung abgeschlossen'),
        'Manual sync failed.'
    );
    $lock = fopen($root . '/.logs/scrape.lock', 'c+');
    flock($lock, LOCK_EX);
    try {
        $assert($request('/?scrape=1')['status'] === 409, 'Concurrent sync accepted.');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    $request('/?logout=1');
    $assert(str_contains($request('/')['body'], 'healthspace — Login'), 'Logout failed.');
    $assert($request('/?scrape=1&key=fixture-password')['status'] === 200, 'Authenticated cron failed.');
    $assert($request('/?scrape=1&key=wrong')['status'] === 403, 'Cron bypassed auth.');
    $database = new PDO('sqlite:' . $root . '/.data/database.sqlite');
    $tables = $database->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    $assert(!in_array('articles', $tables, true), 'Healthspace creates news tables.');
    $manifest = json_decode($request('/?asset=pwa/manifest.json')['body'], true, flags: JSON_THROW_ON_ERROR);
    $assert($manifest['name'] === 'healthspace', 'PWA still named Extrablatt.');
    $installer = dirname(__DIR__) . '/bin/healthspace-init';
    $before = file_get_contents($root . '/.data/.env');
    $setup = proc_open(
        [PHP_BINARY, $installer],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $setupPipes,
        $root
    );
    fclose($setupPipes[0]);
    $setupOutput = stream_get_contents($setupPipes[1]);
    $setupErrors = stream_get_contents($setupPipes[2]);
    fclose($setupPipes[1]);
    fclose($setupPipes[2]);
    $assert(proc_close($setup) === 0, 'Installer failed: ' . $setupErrors);
    $assert(file_get_contents($root . '/.data/.env') === $before, 'Installer overwrote configuration.');
    $assert(
        (int) $database->query('SELECT COUNT(*) FROM health_measurements')->fetchColumn() === 1,
        'Installer lost measurements.'
    );
    $assert(!str_contains($setupOutput, 'fixture-password'), 'Installer printed credentials.');
    $assert(is_file($root . '/.htaccess'), 'Consumer Apache rules missing.');
} finally {
    proc_terminate($process);
    proc_close($process);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
echo "HTTP, auth, OAuth, cron, assets and installer tests passed\n";
