<?php
use Illuminate\Database\Schema\Builder;
use Maxcuso\Roleplay\Rules\{DevelopmentConfiguration, DevelopmentDescriptions};

/** Переносим формулировки уровней в существующий JSON настроек развития. */
return [
    'up' => function (Builder $schema): void {
        $db = $schema->getConnection();
        $db->transaction(function () use ($db): void {
            $db->table('roleplay_catalog_state')->where('id', 1)->lockForUpdate()->first();
            $json = $db->table('roleplay_development_config')->where('id', 1)->value('configuration');
            if ($json === null) throw new RuntimeException('Настройки развития не инициализированы.');
            $configuration = new DevelopmentConfiguration(DevelopmentDescriptions::seed(json_decode($json, true, flags: JSON_THROW_ON_ERROR)));
            $db->table('roleplay_development_config')->where('id', 1)->update([
                'configuration' => json_encode($configuration->values, JSON_THROW_ON_ERROR), 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $db->table('roleplay_catalog_state')->where('id', 1)->increment('revision', 1, ['updated_at' => gmdate('Y-m-d H:i:s')]);
        });
    },
    // Тексты не удаляем при откате: это пользовательские настройки, не отдельная схема.
    'down' => function (Builder $schema): void {},
];
