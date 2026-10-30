<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Quests;

use DomainException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/** Подмодуль квестов: только персонаж участия. Набор, лимиты и запуск находятся в модуле Events. */
abstract class CharacterParticipation
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** Список предназначен только самому игроку; технические поля анкеты здесь не публикуются. */
    public function options(object $event, User $actor): array
    {
        $choices = $actor->exists && $actor->can('roleplay.view')
            ? $this->db->table('roleplay_characters')->where('user_id', $actor->id)->where('status', 'active')
                ->orderBy('id')->get(['id', 'first_name', 'last_name'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'label' => $this->name($row)])->all()
            : [];

        return ['field' => 'characterId', 'title' => 'Выбор персонажа', 'choices' => $choices,
            'error' => $choices ? null : 'Для записи в квест нужен активный персонаж. Завершите создание и принятие персонажа'];
    }

    public function register(object $event, User $actor, array $input): void
    {
        if (!$actor->can('roleplay.view')) throw new DomainException('У вас нет доступа к ролевым персонажам');
        $id = $input['characterId'] ?? null;
        if ($id === null) {
            // Один персонаж выбирается автоматически. Несколько никогда не подменяем текущим выбором профиля.
            $ids = $this->db->table('roleplay_characters')->where('user_id', $actor->id)
                ->where('status', 'active')->limit(2)->pluck('id');
            if ($ids->isEmpty()) throw new DomainException('Для записи в квест нужен активный персонаж. Завершите создание и принятие персонажа');
            if ($ids->count() > 1) throw new DomainException('Выберите персонажа для участия в квесте');
            $id = (int) $ids->first();
        }
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id)
            || (int) $id > 4294967295) throw new DomainException('Не удалось определить выбранного персонажа. Выберите персонажа заново');

        // Повторная проверка под блокировкой: список в открытом окне мог уже устареть.
        $character = $this->db->table('roleplay_characters')->where('id', (int) $id)
            ->where('user_id', $actor->id)->where('status', 'active')->lockForUpdate()->first();
        if (!$character) throw new DomainException('Выбранный персонаж недоступен. Выберите своего активного персонажа');

        $this->db->table('roleplay_quest_participants')->insert([
            'event_id' => $event->id, 'user_id' => $actor->id, 'character_id' => $character->id,
            'character_name' => $this->name($character), 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function remove(object $event, ?int $userId): void
    {
        $query = $this->db->table('roleplay_quest_participants')->where('event_id', $event->id);
        if ($userId !== null) $query->where('user_id', $userId);
        $query->delete();
    }

    public function participantData(object $event): array
    {
        $rows = $this->db->table('roleplay_quest_participants as p')
            ->leftJoin('roleplay_characters as c', 'c.id', '=', 'p.character_id')->where('p.event_id', $event->id)
            ->get(['p.user_id', 'p.character_id', 'p.character_name', 'c.first_name', 'c.last_name']);
        $result = [];
        foreach ($rows as $row) {
            // Удаление персонажа не стирает имя из истории участия; живое имя берётся из анкеты.
            $result[(int) $row->user_id] = ['character' => [
                'id' => $row->character_id === null ? null : (int) $row->character_id,
                'name' => $row->first_name === null ? $row->character_name : $this->name($row),
            ]];
        }
        return $result;
    }

    private function name(object $row): string { return trim($row->first_name.' '.($row->last_name ?? '')); }
}
