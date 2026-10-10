<?php
declare(strict_types=1);
// Работает только в отдельно помеченном контейнере. Не вызывается инструментами рабочего форума.
try {
    $root = dirname(__DIR__, 3);
    if (getenv('ROLEPLAY_TEST_COPY') !== '1' || trim(file_get_contents($root.'/ROLEPLAY_TEST_COPY')) !== 'questpost-roleplay-test'
        || getenv('FLARUM_FORUM_URL') !== 'http://localhost:8081') {
        throw new RuntimeException('Это не изолированная тестовая копия.');
    }
    $config = require $root.'/config.php';
    $database = $config['database'];
    $directory = __DIR__.'/../tests/.runtime/transition';
    $manifest = json_decode(file_get_contents($directory.'/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $name = $manifest['file'];
    if (basename($name) !== $name || !preg_match('/^legacy-[0-9-]+\.sql$/D', $name)) throw new RuntimeException('Неверный файл снимка.');
    $file = $directory.'/'.$name;
    if (!hash_equals($manifest['sha256'], hash_file('sha256', $file))) throw new RuntimeException('Контрольная сумма снимка не совпала.');
    $marker = $root.'/ROLEPLAY_TEST_RESTORED';
    if (is_file($marker)) throw new RuntimeException('Копия уже восстановлена; повторная перезапись запрещена.');
    $command = ['/usr/bin/mariadb', '--protocol=socket', '--socket=/run/mysqld/mysqld.sock',
        '--user='.$database['username'], $database['database']];
    $pipes = [];
    $process = proc_open($command, [0 => ['file', $file, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, null, ['MYSQL_PWD' => $database['password']]);
    if (!is_resource($process)) throw new RuntimeException('Не удалось запустить восстановление.');
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Восстановление завершилось ошибкой.');
    file_put_contents($marker, $manifest['sha256']);
    echo "Test database restored; checksum verified\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n"); exit(1);
}