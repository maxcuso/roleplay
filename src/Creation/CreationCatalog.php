<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRevision;
use Maxcuso\Roleplay\Rules\DevelopmentConfigurationStore;

/** Использует нормализаторы новых каталогов, а не второй SQL-загрузчик игровых определений. */
final class CreationCatalog
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function read(callable $action): mixed
    {
        return (new CatalogRevision($this->db))->read(function (int $revision) use ($action): mixed {
            $registry = new CatalogRegistry($this->db);
            $definitions = [];
            foreach (['attributes', 'races', 'skills', 'proficiencies', 'perks', 'sources',
                'spells', 'paths', 'items', 'item-types', 'ranks'] as $key) {
                $definitions[$key] = array_column($registry->definitionsFor($key), null, 'id');
            }
            $configuration = (new CreationConfigurationStore($this->db))->read();
            $development = (new DevelopmentConfigurationStore($this->db))->read();
            // Редактирование баланса инвалидирует старое подтверждение мастера и повышения.
            $hash = hash('sha256', json_encode([
                $configuration,
                $development->values,
            ], JSON_THROW_ON_ERROR));
            return $action(['revision' => $revision, 'rules_hash' => $hash,
                'configuration' => $configuration, 'development' => $development, 'definitions' => $definitions]);
        });
    }

    /** Игроку возвращаем доступные определения; отключённые нужны только внутренней проверке ссылок. */
    public static function visible(array $catalog): array
    {
        $definitions = [];
        foreach ($catalog['definitions'] as $key => $rows) {
            $definitions[$key] = array_values(array_filter($rows, fn (array $row) => $row['enabled']));
        }
        return ['revision' => $catalog['revision'], 'rules_hash' => $catalog['rules_hash'],
            'configuration' => $catalog['configuration'], 'definitions' => $definitions];
    }
}
