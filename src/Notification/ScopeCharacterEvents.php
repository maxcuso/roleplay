<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Notification;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Maxcuso\Roleplay\Character\Moderation\ReviewPermissions;

final class ScopeCharacterEvents
{
    public function __invoke(User $actor, Builder $query): void
    {
        if (!$actor->exists || !$actor->can('roleplay.view')) { $query->whereRaw('1 = 0'); return; }
        $query->whereIn('roleplay_character_events.character_id', function ($characters) use ($actor): void {
            $characters->select('id')->from('roleplay_characters');
            if (!ReviewPermissions::staff($actor)) $characters->where('user_id', $actor->id);
        });
    }
}
