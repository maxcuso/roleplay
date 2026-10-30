<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Moderation;

use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** План смены ранга без SQL. Права, версии, запись и журнал обеспечивает CharacterOperation. */
final class CharacterRank
{
    public function change(array $character, array $data, array $ranks, string $comment): array
    {
        FieldValues::allowed($data, ['rank_id']);
        if (!array_key_exists('rank_id', $data)) FieldValues::invalid('Укажите ранг персонажа или выберите «Без ранга»');
        $id = $data['rank_id'] === null ? null : FieldValues::integer($data['rank_id'], 'Ранг', 1, PHP_INT_MAX);
        $beforeId = ($character['rank_id'] ?? null) === null ? null : (int) $character['rank_id'];
        $after = $id === null ? null : ($ranks[$id] ?? null);
        if ($id !== null && (!$after || !$after['enabled'])) {
            throw new ApiProblem('rank_unavailable', 'Выбранный ранг удалён или отключён. Попробуйте обновить страницу', 409);
        }
        if ($id === $beforeId) throw new ApiProblem('rank_unchanged', 'У персонажа уже установлен этот ранг', 409);
        $before = $beforeId === null ? null : ($ranks[$beforeId] ?? null);
        return ['fields' => ['rank_id' => $id], 'event' => 'character_managed', 'payload' => [
            'action' => 'set_rank', 'comment' => $comment, 'name' => $this->label($after),
            // Сохраняем названия на момент действия: последующая правка справочника не переписывает историю.
            'before' => $this->summary($before), 'after' => $this->summary($after),
        ]];
    }

    private function label(?array $rank): string
    {
        return $rank === null ? 'Без ранга' : $rank['letter'].' '.$rank['name'];
    }

    private function summary(?array $rank): ?array
    {
        return $rank === null ? null : array_intersect_key($rank, array_flip(['id', 'level', 'letter', 'name']));
    }
}
