<?php
declare(strict_types=1);

/** Резервная копия только локального форума перед обновлением PHP.
 * Пароль базы не выводится и передаётся mariadb-dump через окружение процесса. */
try {
    $root = dirname(__DIR__, 3);
    if (getenv('ROLEPLAY_TEST_COPY') !== '1' || getenv('FLARUM_FORUM_URL') !== 'http://localhost:8081'
        || !is_file($root.'/ROLEPLAY_TEST_INSTALLED')) {
        throw new RuntimeException('Only the installed localhost:8081 test copy is allowed.');
    }
    $directory = '/tmp/questpost-php-update';
    if (file_exists($directory)) throw new RuntimeException('A pending backup already exists; do not overwrite it.');
    if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create backup directory.');
    $config = require $root.'/config.php';
    $database = $config['database'];
    $file = $directory.'/database.sql';
    $command = ['/usr/bin/mariadb-dump', '--single-transaction', '--quick', '--routines', '--triggers',
        '--hex-blob', '--default-character-set=utf8mb4', '--host='.$database['host'],
        '--port='.($database['port'] ?? 3306), '--user='.$database['username'], '--result-file='.$file,
        $database['database']];
    if (in_array($database['host'], ['localhost', '127.0.0.1'], true)) {
        $command[] = '--protocol=socket';
        $command[] = '--socket='.($database['unix_socket'] ?? '/run/mysqld/mysqld.sock');
    }
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, null, ['MYSQL_PWD' => $database['password']]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start database backup.');
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        $password = (string) $database['password'];
        throw new RuntimeException($password === '' ? $error : str_replace($password, '[hidden]', $error));
    }
    chmod($file, 0600);
    $site = require $root.'/site.php';
    $db = $site->bootApp()->getContainer()->make(Illuminate\Database\ConnectionInterface::class);
    $tables = ['users', 'discussions', 'posts', 'roleplay_characters', 'roleplay_races', 'roleplay_items',
        'roleplay_perks', 'roleplay_spells', 'roleplay_character_events'];
    $counts = [];
    foreach ($tables as $table) $counts[$table] = $db->table($table)->count();
    $modules = get_loaded_extensions(); sort($modules);
    $manifest = ['created_at' => gmdate(DATE_ATOM), 'url' => $config['url'], 'php_version' => PHP_VERSION,
        'modules' => $modules, 'database_bytes' => filesize($file), 'database_sha256' => hash_file('sha256', $file),
        'table_counts' => $counts];
    file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Local database backup created; manifest contains version, modules, counts and checksum.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n"); exit(1);
}
