<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

use Illuminate\Database\ConnectionInterface;

/** Только запись уже проверенного плана. Вызывается внутри транзакции создания или замены стартового выбора. */
final class CreationGrants
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function save(int $characterId, array $plan, string $now): void
    {
        $timed = ['character_id' => $characterId, 'created_at' => $now, 'updated_at' => $now];
        foreach ($plan['attributes'] as $attribute) {
            $this->db->table('roleplay_character_attributes')->insert($timed + [
                'attribute_id' => $attribute['id'], 'base_value' => $attribute['base_value'], 'racial_bonus' => $attribute['racial_bonus'],
            ]);
        }
        foreach ($plan['skills'] as $skill) {
            $this->db->table('roleplay_character_skills')->insert($timed + ['skill_id' => $skill['id'], 'level' => $skill['level']]);
        }
        foreach ($plan['proficiencies'] as $proficiency) {
            $this->db->table('roleplay_character_weapon_proficiencies')->insert($timed + ['proficiency_id' => $proficiency['id']]);
        }
        $grants = new \Maxcuso\Roleplay\Character\State\CharacterGrants($this->db);
        foreach ($plan['perks'] as $perk) {
            $grants->perk($characterId, $perk['id'], $perk['source_id'], $perk['context'], $perk['rarity'], true);
        }
        foreach ($plan['spells'] as $spell) {
            $grants->spell($characterId, $spell['id'], true);
        }
        foreach ($plan['choices'] as $choice) {
            $this->db->table('roleplay_character_choices')->insert(['character_id' => $characterId] + $choice);
        }
        foreach ($plan['balances'] as $key => $amount) {
            $this->db->table('roleplay_character_balances')->insert([
                'character_id' => $characterId, 'resource_key' => $key, 'amount' => $amount, 'updated_at' => $now,
            ]);
        }
        foreach ($plan['items'] as $item) {
            $grants->items($characterId, $item['id'], $item['quantity'], $item['max_stack'], true);
        }
    }
}