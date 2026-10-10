<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Character\State;

use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;

/** Балансы меняются под блокировкой персонажа в общей транзакции.
 * Полученный опыт увеличивает активный и накопленный. Расход/изъятие уменьшает только активный.
 */
final class CharacterBalances
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function adjust(int $id, string $resource, int $delta): array
    {
        if (!in_array($resource, ['gold', 'experience_active'], true) || $delta === 0) {
            throw new ApiProblem('invalid_balance_change', 'Выбери ресурс и ненулевое изменение.');
        }
        $changes = [$resource => $delta];
        if ($resource === 'experience_active' && $delta > 0) $changes['experience_total'] = $delta;
        $result = [];
        foreach ($changes as $key => $amount) {
            $query = $this->db->table('roleplay_character_balances')->where('character_id', $id)->where('resource_key', $key);
            $before = $query->value('amount');
            if ($before === null) throw new ApiProblem('balance_missing', 'Баланс персонажа не настроен.', 409);
            $after = (int) $before + $amount;
            if ($after < 0 || $after > 9007199254740991) {
                throw new ApiProblem('insufficient_balance', 'Недостаточно ресурса или превышен допустимый баланс.', 409);
            }
            $query->update(['amount' => $after, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            $result[$key] = ['before' => (int) $before, 'after' => $after];
        }
        if ($resource === 'experience_active' && $delta > 0) {
            $owner = (int) $this->db->table('roleplay_characters')->where('id', $id)->value('user_id');
            (new \Maxcuso\Roleplay\Character\Profile\RoleplayerStatistics($this->db))->earn($owner, $delta);
        }
        return $result;
    }
}
