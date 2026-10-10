<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Moderation;

use Flarum\User\User;

final class ReviewPermissions
{
    public static function staff(User $actor): bool
    {
        return $actor->exists && ($actor->isAdmin() || $actor->can('roleplay.moderate'));
    }

    public static function owner(array $character, User $actor): bool
    {
        return $actor->exists && (int) $character['user_id'] === (int) $actor->id;
    }

    /** Весь стартовый выбор можно менять, пока техническая часть не одобрена. */
    public static function canEditMechanics(array $character, User $actor): bool
    {
        return self::owner($character, $actor) && $character['status'] === 'creating'
            && in_array($character['mechanics_status'], ['draft', 'review', 'changes_requested'], true);
    }

    public static function describe(array $character, User $actor, string $literatureStatus): array
    {
        $creating = $character['status'] === 'creating';
        $owner = self::owner($character, $actor);
        return [
            'can_edit_mechanics' => self::canEditMechanics($character, $actor),
            'can_moderate' => $creating && self::staff($actor),
            'can_edit_identity' => $creating && $owner
                && in_array($character['mechanics_status'], ['draft', 'changes_requested'], true),
            'can_submit_mechanics' => $creating && $owner
                && in_array($character['mechanics_status'], ['draft', 'changes_requested'], true),
            'can_submit_literature' => $creating && $owner
                && in_array($literatureStatus, ['draft', 'changes_requested'], true),
            'can_withdraw_literature' => $creating && $owner && $literatureStatus === 'review',
        ];
    }
}
