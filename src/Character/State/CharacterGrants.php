<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Character\State;

use Illuminate\Database\ConnectionInterface;

/** Общая запись выдач для мастера, развития и модератора.
 * Вызывается после проверки прав, внутри транзакции.
 * is_starting отличает стартовую выдачу от награды, но не заменяет источник перка.
 */
final class CharacterGrants
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function perk(int $characterId, int $perkId, int $sourceId, array $context, ?string $rarity = null, bool $starting = false): void
    {
        $this->db->table('roleplay_character_perks')->insert([
            'character_id' => $characterId, 'perk_id' => $perkId, 'source_id' => $sourceId,
            'source_context' => json_encode($context, JSON_THROW_ON_ERROR), 'rarity_override' => $rarity,
            'is_starting' => $starting,
        ]);
    }

    public function spell(int $characterId, int $spellId, bool $starting = false): void
    {
        $this->db->table('roleplay_character_spells')->insert([
            'character_id' => $characterId, 'spell_id' => $spellId, 'is_starting' => $starting,
        ]);
    }

    public function proficiency(int $characterId, int $proficiencyId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('roleplay_character_weapon_proficiencies')->insert([
            'character_id' => $characterId, 'proficiency_id' => $proficiencyId, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function items(int $characterId, int $itemId, int $quantity, int $maxStack, bool $starting = false): void
    {
        if ($quantity < 1 || $maxStack < 1) throw new \InvalidArgumentException('Некорректная стопка.');
        // Стопки выдаются отдельно: награда не смешивается со стартовым набором.
        while ($quantity > 0) {
            $size = min($quantity, $maxStack);
            $this->db->table('roleplay_character_items')->insert([
                'character_id' => $characterId, 'item_id' => $itemId, 'quantity' => $size,
                'equipped' => false, 'is_starting' => $starting,
            ]);
            $quantity -= $size;
        }
    }
}