<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Profile;

use Flarum\User\User;
use Illuminate\Database\Query\Builder;
use Maxcuso\Roleplay\Api\ApiProblem;

/** Один набор правил для списка и анкеты: угадывание ID не открывает незавершённого персонажа. */
final class CharacterVisibility
{
    public function apply(Builder $query, User $actor): Builder
    {
        if ($this->staff($actor)) return $query;
        return $query->where(function (Builder $visible) use ($actor): void {
            $visible->where(function (Builder $published): void {
                $published->whereIn('status', ['active', 'dead', 'archived'])->where('literature_approved', true)->where('mechanics_status', 'approved');
            });
            if ($actor->exists) $visible->orWhere('user_id', $actor->id);
        });
    }

    public function assertVisible(array $character, User $actor): void
    {
        $owner = $actor->exists && (int) $character['user_id'] === (int) $actor->id;
        $published = in_array($character['status'], ['active', 'dead', 'archived'], true)
            && (bool) $character['literature_approved'] && $character['mechanics_status'] === 'approved';
        if (!$owner && !$published && !$this->staff($actor)) {
            throw new ApiProblem('character_not_found', 'Персонаж не найден. Попробуйте обновить страницу', 404);
        }
    }

    public function canEquip(array $character, User $actor): bool
    {
        return $actor->exists && (int) $character['user_id'] === (int) $actor->id
            && $character['status'] === 'active';
    }

    private function staff(User $actor): bool
    {
        return $actor->exists && ($actor->isAdmin() || $actor->can('roleplay.moderate'));
    }
}