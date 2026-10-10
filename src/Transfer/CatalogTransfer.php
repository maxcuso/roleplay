<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Transfer;

use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRevision;
use Maxcuso\Roleplay\Catalog\Shared\DefinitionStore;
use Maxcuso\Roleplay\Catalog\Shared\EffectBoundCatalog;
use Maxcuso\Roleplay\Catalog\Skills\SkillLevelStore;
use Maxcuso\Roleplay\Creation\CreationConfiguration;

/**
 * Импорт в уже созданную ПУСТУЮ новую схему. Не удаляет и не заменяет существующие данные.
 * Все проверки и записи в одной транзакции; неверная связь отменяет весь импорт.
 */
final class CatalogTransfer
{
    private const TABLES = ['attributes' => 'attributes', 'paths' => 'magic_paths', 'item-types' => 'item_types',
        'sources' => 'perk_sources', 'perks' => 'perks', 'skills' => 'skills', 'proficiencies' => 'weapon_proficiencies',
        'spells' => 'spells', 'items' => 'items', 'races' => 'races'];

    public function __construct(private ConnectionInterface $db) {}

    public function run(array $plan): array
    {
        if (($plan['version'] ?? null) !== 1 || ($plan['scope'] ?? null) !== 'catalogs-only'
            || array_keys($plan['catalogs'] ?? []) !== array_keys(self::TABLES)) {
            throw new \DomainException('Неизвестный формат плана переноса.');
        }
        return $this->db->transaction(function () use ($plan): array {
            $revision = new CatalogRevision($this->db);
            $this->db->table('roleplay_catalog_state')->where('id', 1)->lockForUpdate()->first();
            foreach ([...array_values(self::TABLES), 'characters', 'operations', 'creation_drafts',
                'skill_levels', 'actor_states'] as $table) {
                if ($this->db->table('roleplay_'.$table)->exists()) throw new \DomainException('Импорт разрешён только в пустую схему: '.$table);
            }
            if ($this->db->table('roleplay_creation_config')->value('configuration') !== '{}') {
                throw new \DomainException('Настройки назначения уже изменены.');
            }
            $registry = new CatalogRegistry($this->db);
            $counts = [];
            $inline = [];
            foreach (self::TABLES as $key => $table) {
                foreach ($plan['catalogs'][$key] as $row) {
                    if (!is_int($row['id']) || $row['id'] < 1 || !is_string($row['slug'])
                        || !preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/D', $row['slug'])) {
                        throw new \DomainException('Неверная исходная идентичность справочника.');
                    }
                    $fields = $registry->get($key)->validate($row['input']);
                    // Навык может ссылаться на перк, а эффект перка — на навык. Сохраняем цели после всех определений.
                    if (in_array($key, ['perks', 'items'], true)) {
                        $fields['effects'] = '[]';
                        $inline[$key][$row['id']] = $row['input'];
                    }
                    $levels = $fields['levels'] ?? null;
                    unset($fields['levels']);
                    $this->db->table('roleplay_'.$table)->insert(['id' => $row['id'], 'slug' => $row['slug']] + $fields
                        + ['created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
                    if ($levels !== null) (new SkillLevelStore($this->db))->replace($row['id'], $levels);
                }
                $counts[$key] = count($plan['catalogs'][$key]);
            }
            $store = new DefinitionStore($this->db, $revision);
            foreach ($inline as $owner => $rows) foreach ($rows as $id => $input) {
                $catalog = $registry->get($owner);
                if (!$catalog instanceof EffectBoundCatalog) throw new \LogicException('Нет редактора эффектов.');
                $fields = $catalog->validateWithEffects($input);
                $this->db->table('roleplay_'.$owner)->where('id', $id)->update($fields);
            }
            $configuration = (new CreationConfiguration())->normalize($plan['configuration']);
            if ($configuration['enabled']) throw new \DomainException('Импорт не включает создание автоматически.');
            $store->requireIds('perk_sources', array_values($configuration['perk_sources']));
            foreach ($configuration['loadout'] as $group) foreach ($group['options'] as $option) {
                $store->requireIds('items', array_column($option['items'], 'item_id'));
            }
            $this->db->table('roleplay_creation_config')->where('id', 1)->update([
                'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR), 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->db->table('roleplay_catalog_state')->where('id', 1)->increment('revision');
            $effectCount = 0;
            foreach ($inline as $rows) foreach ($rows as $input) $effectCount += count($input['effects']);
            return ['counts' => $counts, 'effect_links' => $effectCount, 'characters' => 0, 'warnings' => $plan['warnings']];
        });
    }
}