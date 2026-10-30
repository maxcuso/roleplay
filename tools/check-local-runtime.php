<?php
declare(strict_types=1);

/** Проверка обновления без изменения игровых данных. Временная HTTP-проба
 * доступна только с loopback и удаляется в finally; phpinfo не публикуется. */
try {
    $root = dirname(__DIR__, 3);
    if (getenv('ROLEPLAY_TEST_COPY') !== '1' || getenv('FLARUM_FORUM_URL') !== 'http://localhost:8081'
        || !is_file($root.'/ROLEPLAY_TEST_INSTALLED')) throw new RuntimeException('Only the local test copy is allowed.');
    $manifest = json_decode(file_get_contents($argv[1] ?? '/tmp/php-before.json'), true, flags: JSON_THROW_ON_ERROR);
    if (PHP_VERSION !== '8.5.12') throw new RuntimeException('Unexpected CLI PHP version.');
    if (array_diff($manifest['modules'], get_loaded_extensions())) throw new RuntimeException('A previously loaded PHP module is missing.');
    $site = require $root.'/site.php';
    $container = $site->bootApp()->getContainer();
    set_exception_handler(function (Throwable $error): void {fwrite(STDERR, (string) $error."\n"); exit(1);});
    $db = $container->make(Illuminate\Database\ConnectionInterface::class);
    foreach ($manifest['table_counts'] as $table => $count) {
        if ($db->table($table)->count() !== $count) throw new RuntimeException('Record count changed: '.$table);
    }
    $client = $container->make(Flarum\Api\Client::class)->withActor(Flarum\User\User::findOrFail(1));
    foreach (['/roleplay/admin/catalogs/races', '/roleplay/admin/development'] as $path) {
        $response = $client->send('GET', $path);
        if ($response->getStatusCode() !== 200) throw new RuntimeException('API check failed: '.$path);
    }
    $probeName = 'php-check-'.bin2hex(random_bytes(12)).'.php';
    $probeFile = $root.'/public/'.$probeName;
    $probe = <<<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {http_response_code(404); exit;}
header('Content-Type: application/json');
echo json_encode(['version' => PHP_VERSION, 'sapi' => PHP_SAPI]);
PHP;
    try {
        if (file_put_contents($probeFile, $probe) === false) throw new RuntimeException('Cannot write temporary HTTP probe.');
        $curl = curl_init('http://127.0.0.1/'.$probeName);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $web = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || ($web['version'] ?? null) !== '8.5.12' || ($web['sapi'] ?? null) !== 'fpm-fcgi') {
            throw new RuntimeException('HTTP is not served by the expected PHP-FPM.');
        }
    } finally {
        if (is_file($probeFile)) unlink($probeFile);
    }
    echo "PHP 8.5.12 confirmed in CLI and HTTP/FPM; all modules, saved record counts and admin APIs passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, (string) $error."\n"); exit(1);
}
