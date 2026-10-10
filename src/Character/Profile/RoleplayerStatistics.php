<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Profile;

use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;

/** Данные игрока живут отдельно от персонажей и сохраняются после их удаления. */
final class RoleplayerStatistics
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function read(int $userId): array
    {
        $row = $this->db->table('roleplay_player_statistics')->where('user_id', $userId)->first();
        return [
            'earned_experience' => (int) ($row->earned_experience ?? 0),
            // Считаем весь список, независимо от пагинации и выбранной анкеты.
            'active_characters' => $this->db->table('roleplay_characters')->where('user_id', $userId)
                ->where('status', 'active')->where('mechanics_status', 'approved')->where('literature_approved', true)->count(),
            'started_at' => isset($row->started_at) ? str_replace(' ', 'T', $row->started_at).'Z' : null,
        ];
    }

    /** Вызывать в транзакции выдачи. Повтор команды отсекается CharacterOperation.
     * Стартовый опыт сюда не поступает; расход и изъятие не уменьшают заработанный опыт.
     */
    public function earn(int $userId, int $amount): void
    {
        if ($amount <= 0) return;
        $this->ensure($userId);
        $query = $this->db->table('roleplay_player_statistics')->where('user_id', $userId);
        // Блокировка общего счётчика защищает одновременные выдачи разным персонажам игрока.
        $before = (int) (clone $query)->lockForUpdate()->value('earned_experience');
        if ($amount > 9007199254740991 - $before) {
            throw new ApiProblem('roleplayer_experience_limit', 'Превышен допустимый общий опыт ролевика.', 409);
        }
        $query->update(['earned_experience' => $before + $amount]);
    }

    /** Первый момент принятия сохраняется один раз в той же транзакции, что и активация. */
    public function activated(int $userId, string $at): void
    {
        $this->ensure($userId);
        $this->db->table('roleplay_player_statistics')->where('user_id', $userId)->whereNull('started_at')
            ->update(['started_at' => $at]);
    }

    private function ensure(int $userId): void
    {
        $this->db->table('roleplay_player_statistics')->insertOrIgnore([
            'user_id' => $userId, 'earned_experience' => 0, 'started_at' => null,
        ]);
    }
}
