<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Guide;

use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Catalog\Shared\{CatalogRegistry, CatalogRevision};
use Maxcuso\Roleplay\Creation\CreationConfigurationStore;
use Maxcuso\Roleplay\Rules\{DevelopmentConfigurationStore, DevelopmentDescriptions};

/** Публичные данные правил. Связи читаются по ID, без поиска перков по названию.
 * Не возвращает эффекты, настройки создания, персонажей и служебные поля записей.
 */
final class GuideCatalog
{
    public function __construct(private ConnectionInterface $db) {}

    public function read(): array
    {
        return (new CatalogRevision($this->db))->read(function (): array {
            $registry = new CatalogRegistry($this->db);
            $attributes = array_map(fn ($row) => $this->fields($row, ['id', 'name', 'slug', 'icon']),
                $registry->definitionsFor('attributes'));
            $attributesById = array_column($attributes, null, 'id');
            $perks = $this->db->table('roleplay_perks')->where('enabled', true)
                ->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'name', 'description', 'image', 'rarity'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name,
                    'description' => $row->description, 'image' => $row->image, 'rarity' => $row->rarity])->all();
            $perksById = array_column($perks, null, 'id');
            $sources = array_column($registry->definitionsFor('sources'), null, 'id');
            $sourceIds = (new CreationConfigurationStore($this->db))->read()['perk_sources'];
            $sourceFor = fn ($kind) => isset($sources[$sourceIds[$kind] ?? 0])
                ? $this->fields($sources[$sourceIds[$kind]], ['id', 'name', 'icon', 'name_source', 'icon_source', 'attribute_icons'])
                : null;

            $skills = [];
            foreach ($registry->definitionsFor('skills') as $skill) {
                if (!$skill['enabled']) continue;
                $levels = [];
                foreach ($skill['levels'] as $level) {
                    $levels[] = ['level' => $level['level'], 'perk' => $perksById[$level['perk_id'] ?? 0] ?? null];
                }
                $skills[] = ['slug' => $skill['slug'], 'name' => $skill['name'], 'description' => $skill['description'],
                    'attribute_slug' => $attributesById[$skill['attribute_id']]['slug'] ?? null,
                    'source' => $sourceFor('skill'), 'levels' => $levels];
            }

            $races = [];
            // Включённые определения; выключенный или удалённый перк не подменяется старым текстом.
            $selectPerks = fn ($ids) => array_values(array_intersect_key($perksById, array_flip($ids)));
            foreach ($registry->definitionsFor('races') as $race) {
                if (!$race['enabled']) continue;
                $rules = $race['rules'];
                $races[] = $this->fields($race, ['slug', 'name', 'description', 'quote', 'image', 'min_age', 'max_age',
                    'average_height', 'distinctive_traits', 'background_color']) + ['source' => $sourceFor('race'),
                    'attribute_bonuses' => $this->fields($rules, ['fixed_bonuses', 'choice_attributes', 'attribute_bonus', 'attribute_count']),
                    'fixed_perks' => $selectPerks($rules['fixed_perk_ids'] ?? []),
                    'choice_perks' => $selectPerks($rules['perk_choice_ids'] ?? []),
                    'perk_choice_count' => (int) ($rules['perk_choice_count'] ?? 0)];
            }
            $development = (new DevelopmentConfigurationStore($this->db))->read()->values;
            $proficiencies = array_column($registry->definitionsFor('proficiencies'), null, 'slug');
            $levels = [];
            foreach ($development['attributes'] as $slug => $attribute) {
                $rules = [];
                foreach ($attribute['rules'] as $rule) {
                    $rules[] = ['id' => $rule['id'], 'level' => $rule['level'], 'type' => $rule['type'], 'value' => $rule['value'],
                        'description' => $rule['description'] ?? DevelopmentDescriptions::rule($slug, $rule),
                        'proficiency' => $rule['type'] === 'automatic' ? ($proficiencies[$rule['value']]['name'] ?? $rule['value']) : null];
                }
                $levels[$slug] = ['initial_description' => $attribute['initial_description'],
                    'initial_stats' => array_map(fn ($growth) => $growth['initial'], $attribute['growth']), 'rules' => $rules];
            }
            $ranks = array_values(array_filter($registry->definitionsFor('ranks'), fn ($rank) => $rank['enabled']));
            usort($ranks, fn ($a, $b) => $a['level'] <=> $b['level']);
            $ranks = array_map(fn ($rank) => $this->fields($rank, ['slug', 'name', 'level', 'letter', 'description']), $ranks);
            return ['attributes' => $attributes, 'perks' => $this->perkExamples($perks, $skills, $races), 'ranks' => $ranks,
                'skills' => $skills, 'races' => $races, 'development' => $levels,
                'advancement' => $this->fields($development, ['maximum_level', 'skill_minimums', 'experience_costs'])];
        });
    }

    /** Случайные примеры с настоящим источником. Один перк не повторяется в тройке.
     * Только связи справочников: выдачи и личные данные персонажей здесь не читаются.
     */
    private function perkExamples(array $perks, array $skills, array $races): array
    {
        $sources = [];
        foreach ($skills as $skill) {
            foreach ($skill['levels'] as $level) {
                if (!$level['perk']) continue;
                $sources[$level['perk']['id']][] = [
                    'source' => $skill['source'] ?? ['name_source' => 'skill', 'icon_source' => 'skill'],
                    'source_context' => ['skill_name' => $skill['name'], 'attribute_slug' => $skill['attribute_slug']],
                ];
            }
        }
        foreach ($races as $race) {
            foreach ([...$race['fixed_perks'], ...$race['choice_perks']] as $perk) {
                $sources[$perk['id']][] = [
                    'source' => $race['source'] ?? ['name_source' => 'race'],
                    'source_context' => ['race_name' => $race['name']],
                ];
            }
        }
        // Не приписываем свободному перку выдуманный навык или расу.
        $examples = array_values(array_filter($perks, fn ($perk) => isset($sources[$perk['id']])));
        shuffle($examples);
        return array_map(function ($perk) use ($sources): array {
            $choices = $sources[$perk['id']];
            return $perk + $choices[array_rand($choices)];
        }, array_slice($examples, 0, 3));
    }

    private function fields(array $row, array $keys): array
    {
        return array_intersect_key($row, array_flip($keys));
    }
}
