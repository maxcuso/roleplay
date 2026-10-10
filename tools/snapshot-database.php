<?php
declare(strict_types=1);
// Локальный согласованный снимок InnoDB. Пароль передаётся только дочернему процессу.
try {
    $root = dirname(__DIR__, 3);
    $config = require $root.'/config.php';
    $database = $config['database'];
    $directory = __DIR__.'/../tests/.runtime/transition';
    if (!is_dir($directory)) mkdir($directory, 0700, true);
    $file = $directory.'/legacy-'.gmdate('Ymd-His').'.sql';
    $command = ['/usr/bin/mariadb-dump', '--single-transaction', '--quick', '--routines', '--triggers',
        '--hex-blob', '--default-character-set=utf8mb4', '--host='.$database['host'],
        '--port='.($database['port'] ?? 3306), '--user='.$database['username'],
        '--result-file='.$file, $database['database']];
    if (in_array($database['host'], ['localhost', '127.0.0.1'], true)) {
        // В dev-образе MariaDB отключает TCP и доступна через локальный сокет.
        $command[] = '--protocol=socket';
        $command[] = '--socket='.($database['unix_socket'] ?? '/run/mysqld/mysqld.sock');
    }
    $pipes = [];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, null, ['MYSQL_PWD' => $database['password']]);
    if (!is_resource($process)) throw new RuntimeException('Не удалось запустить снимок.');
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        $password = (string) $database['password'];
        $safe = $password === '' ? $error : str_replace($password, '[hidden]', $error);
        throw new RuntimeException('Не удалось сохранить снимок базы: '.trim($safe));
    }
    chmod($file, 0600);
    $manifest = ['file' => basename($file), 'sha256' => hash_file('sha256', $file), 'bytes' => filesize($file),
        'created_at' => gmdate(DATE_ATOM), 'scope' => 'local-test-backup'];
    file_put_contents($directory.'/snapshot.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n"); exit(1);
}