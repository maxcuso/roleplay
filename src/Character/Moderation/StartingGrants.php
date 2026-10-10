<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Character\Moderation;

use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Creation\CreationGrants;
use Maxcuso\Roleplay\Character\State\CharacterGrants;

/** Заменяет только стартовый выбор в транзакции редактирования.
 * Балансы и дополнительные награды не пересоздаются. Неизменённые вещи
 * сохраняют экземпляры и экипировку; при сокращении сначала убираем ненадетые.
 */
final class StartingGrants
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function replace(int $id, array $plan): void
    {
        foreach (['attributes', 'skills', 'weapon_proficiencies', 'choices'] as $table) {
            $this->db->table('roleplay_character_'.$table)->where('character_id', $id)->delete();
        }
        foreach (['perks' => 'perk_id', 'spells' => 'spell_id'] as $table => $column) {
            $query = $this->db->table('roleplay_character_'.$table)->where('character_id', $id);
            (clone $query)->where('is_starting', true)->delete();
            $additional = $query->pluck($column)->all();
            // Один перк/заклинание не дублируется. Дополнительная выдача сохраняет свой источник.
            $plan[$table] = array_values(array_filter($plan[$table],
                fn (array $row) => !in_array($row['id'], $additional)));
        }
        $this->items($id, $plan['items']);
        $plan['items'] = [];
        $plan['balances'] = []; // смена расы/набора не выдаёт стартовое золото повторно
        (new CreationGrants($this->db))->save($id, $plan, gmdate('Y-m-d H:i:s'));
    }

    private function items(int $id, array $desired): void
    {
        $targets = array_column($desired, null, 'id');
        $rows = $this->db->table('roleplay_character_items')->where('character_id', $id)
            ->where('is_starting', true)->orderBy('equipped')->orderBy('id')->get();
        $counts = [];
        foreach ($rows as $row) $counts[(int) $row->item_id] = ($counts[(int) $row->item_id] ?? 0) + (int) $row->quantity;
        $remove = [];
        foreach ($counts as $item => $count) $remove[$item] = max(0, $count - ($targets[$item]['quantity'] ?? 0));
        foreach ($rows as $row) {
            $item = (int) $row->item_id;
            $amount = min($remove[$item], (int) $row->quantity);
            if ($amount === 0) continue;
            $query = $this->db->table('roleplay_character_items')->where('id', $row->id);
            if ($amount === (int) $row->quantity) $query->delete();
            else $query->decrement('quantity', $amount);
            $remove[$item] -= $amount;
        }
        foreach ($targets as $item => $row) {
            $amount = $row['quantity'] - ($counts[$item] ?? 0);
            if ($amount > 0) (new CharacterGrants($this->db))->items($id, $item, $amount, $row['max_stack'], true);
        }
    }
}