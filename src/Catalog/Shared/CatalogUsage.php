<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Shared;

use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;

/** Новая зависимость для удаления добавляется в одном месте. Проверка идёт под блокировкой. */
final class CatalogUsage
{
    public function __construct(private ConnectionInterface $db) {}

    public function assertUnused(string $catalog, int $id): void
    {
        $references = [
            'races' => [['characters', 'race_id']],
            'ranks' => [['characters', 'rank_id']],
            'attributes' => [['skills', 'attribute_id'], ['weapon_proficiencies', 'attribute_id'], ['character_attributes', 'attribute_id'], ['character_choices', 'attribute_id']],
            'skills' => [['character_skills', 'skill_id']],
            'weapon_proficiencies' => [
                ['character_weapon_proficiencies', 'proficiency_id'],
                ['items', 'melee_proficiency_id'], ['items', 'ranged_proficiency_id'], ['items', 'magic_proficiency_id'],
            ],
            'magic_paths' => [['spells', 'magic_path_id']],
            'item_types' => [['items', 'item_type_id']],
            'perk_sources' => [['character_perks', 'source_id']],
            'perks' => [['character_perks', 'perk_id'], ['skill_levels', 'perk_id']],
            'spells' => [['character_spells', 'spell_id']],
            'items' => [['character_items', 'item_id']],
        ];
        foreach ($references[$catalog] ?? [] as [$table, $column]) {
            if ($this->db->table('roleplay_'.$table)->where($column, $id)->exists()) $this->used();
        }

        if ($catalog === 'spells') {
            // Сохраняем ссылки и у отключённой литературы, как у расовых выдач.
            foreach ($this->db->table('roleplay_items')->pluck('literature_contents') as $json) {
                foreach (json_decode($json ?? '[]', true, flags: JSON_THROW_ON_ERROR) as $entry) {
                    if ($entry['type'] === 'spell' && $entry['knowledge_id'] === $id) $this->used();
                }
            }
        }

        // Вложенные эффекты больше не имеют внешних ключей SQL. Защищаем их цели здесь.
        if (in_array($catalog, ['attributes', 'skills'], true)) {
            $column = $catalog === 'attributes' ? 'attribute_id' : 'skill_id';
            foreach (['perks', 'items'] as $owner) {
                foreach ($this->db->table('roleplay_'.$owner)->pluck('effects') as $json) {
                    foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR) as $effect) {
                        if (($effect[$column] ?? null) === $id) $this->used();
                    }
                }
            }
        }

        $creation = json_decode($this->db->table('roleplay_creation_config')->where('id', 1)->value('configuration'),
            true, flags: JSON_THROW_ON_ERROR);
        if ($catalog === 'perk_sources' && in_array($id, array_values($creation['perk_sources'] ?? []), true)) {
            $this->used();
        }
        if ($catalog === 'items') {
            foreach ($creation['loadout'] ?? [] as $group) {
                foreach ($group['options'] as $option) {
                    if (in_array($id, array_column($option['items'], 'item_id'), true)) $this->used();
                }
            }
        }

        $slug = $catalog === 'attributes'
            ? $this->db->table('roleplay_attributes')->where('id', $id)->value('slug') : null;
        foreach ($this->db->table('roleplay_races')->pluck('rules') as $json) {
            $rules = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $fields = match ($catalog) {
                'perks' => ['fixed_perk_ids', 'perk_choice_ids'],
                'spells' => ['fixed_spell_ids', 'spell_choice_ids'],
                'magic_paths' => ['spell_choice_path_ids'],
                'weapon_proficiencies' => ['proficiency_choice_ids'],
                default => [],
            };
            foreach ($fields as $field) {
                if (in_array($id, $rules[$field] ?? [], true)) $this->used();
            }
            if ($slug !== null && (array_key_exists($slug, $rules['fixed_bonuses'] ?? [])
                || in_array($slug, $rules['choice_attributes'] ?? [], true))) $this->used();
        }
        if ($slug !== null) {
            foreach ($this->db->table('roleplay_perk_sources')->pluck('attribute_icons') as $json) {
                if (array_key_exists($slug, json_decode($json, true, flags: JSON_THROW_ON_ERROR))) $this->used();
            }
        }
    }

    private function used(): never
    {
        throw new ApiProblem('catalog_entry_in_use', 'Эта запись используется, поэтому удалить её нельзя. Вы можете отключить её', 409);
    }
}
