<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\Access;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRevision;
use Maxcuso\Roleplay\Catalog\Shared\DefinitionStore;

/** Настройки меняются под той же версией, что расы и способности. */
final class CreationConfigurationStore
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** Внутренний вызов: вызывающая операция уже держит блокировку CatalogRevision. */
    public function read(): array
    {
        $json = $this->db->table('roleplay_creation_config')->where('id', 1)->value('configuration');
        if ($json === null) throw new \RuntimeException('Настройки создания не инициализированы.');
        return (new CreationConfiguration())->normalize(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    public function save(User $actor, int $expectedRevision, array $input): array
    {
        Access::admin($actor);
        $revision = new CatalogRevision($this->db);
        return $revision->write($expectedRevision, function () use ($input): array {
            $values = (new CreationConfiguration())->normalize($input);
            $store = new DefinitionStore($this->db, new CatalogRevision($this->db));
            $store->requireIds('perk_sources', array_values($values['perk_sources']));
            $ids = [];
            foreach ($values['loadout'] as $group) {
                foreach ($group['options'] as $option) {
                    $ids = array_merge($ids, array_column($option['items'], 'item_id'));
                }
            }
            $store->requireIds('items', array_values(array_unique($ids)));
            $this->db->table('roleplay_creation_config')->where('id', 1)->update([
                'configuration' => json_encode($values, JSON_THROW_ON_ERROR), 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return ['configuration' => $values];
        });
    }
}