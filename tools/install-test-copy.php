<?php
declare(strict_types=1);

/**
 * Одноразовая подготовка КОПИИ. Очистка старой схемы запрещена без двух маркеров и отдельного URL.
 * Для рабочего форума нужен другой согласованный процесс переключения; этот инструмент его не делает.
 */
try {
    $root = dirname(__DIR__, 3);
    if (getenv('ROLEPLAY_TEST_COPY') !== '1' || getenv('FLARUM_FORUM_URL') !== 'http://localhost:8081'
        || trim(file_get_contents($root.'/ROLEPLAY_TEST_COPY')) !== 'questpost-roleplay-test'
        || !is_file($root.'/ROLEPLAY_TEST_RESTORED')) throw new RuntimeException('Это не восстановленная тестовая копия.');
    if (is_file($root.'/ROLEPLAY_TEST_INSTALLED')) throw new RuntimeException('Новая схема уже установлена.');
    $site = require $root.'/site.php';
    $container = $site->bootApp()->getContainer();
    $db = $container->make(\Illuminate\Database\ConnectionInterface::class);
    $schema = $db->getSchemaBuilder();
    if ($schema->hasTable('roleplay_creation_config') || $schema->hasTable('roleplay_skill_levels')) {
        throw new RuntimeException('Найдена новая схема: удаление запрещено.');
    }
    if (!$schema->hasTable('roleplay_rules')) throw new RuntimeException('Не найдена ожидаемая старая схема.');
    $prefix = $db->getTablePrefix();
    if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) throw new RuntimeException('Неожиданный префикс.');
    $oldTables = [];
    foreach ($db->select('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $row) {
        if (str_starts_with($row->name, $prefix.'roleplay_')) $oldTables[] = substr($row->name, strlen($prefix));
    }
    $manifest = json_decode(file_get_contents(__DIR__.'/../tests/.runtime/transition/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    if (!hash_equals($manifest['sha256'], trim(file_get_contents($root.'/ROLEPLAY_TEST_RESTORED')))) {
        throw new RuntimeException('Маркер восстановления не совпадает со снимком.');
    }
    // Полный старый журнал и данные уже находятся в проверенном SQL-снимке.
    $db->statement('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($oldTables as $table) $schema->drop($table);
    } finally {
        $db->statement('SET FOREIGN_KEY_CHECKS = 1');
    }
    $db->table('migrations')->where('extension', 'maxcuso-roleplay')->delete();
    // Копия получает ту же схему, что обычная установка через Flarum.
    foreach (glob(__DIR__.'/../migrations/*.php') as $file) {
        $migration = require $file;
        $migration['up']($schema);
        $db->table('migrations')->insert([
            'migration' => basename($file, '.php'), 'extension' => 'maxcuso-roleplay',
        ]);
    }
    $plan = json_decode(file_get_contents(__DIR__.'/../tests/.runtime/transfer-plan.json'), true, flags: JSON_THROW_ON_ERROR);
    $result = (new \Maxcuso\Roleplay\Transfer\CatalogTransfer($db))->run($plan);
    // Начальная шкала находится в файле настроек, а не в миграции. Правки администратора сохраняются.
    (new \Maxcuso\Roleplay\Catalog\SeedRanks($db))->run(\Flarum\User\User::findOrFail(1));

    // В изолированной копии тестовые действия не должны рассылать письма настоящим пользователям.
    $db->table('settings')->updateOrInsert(['key' => 'mail_driver'], ['value' => 'log']);
    file_put_contents($root.'/ROLEPLAY_TEST_INSTALLED', gmdate(DATE_ATOM));
    file_put_contents(__DIR__.'/../tests/.runtime/test-copy-import.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    echo "New schema installed on localhost:8081; catalogues imported; character count = 0\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n"); exit(1);
}
