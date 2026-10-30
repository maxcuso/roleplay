<?php

namespace Maxcuso\Roleplay\Events;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestEvent extends AbstractModel
{
    protected $table = 'quest_events';

    protected $casts = [
    'start_failed_at' => 'immutable_datetime',
    'reward_experience' => 'integer',
    'reward_gold' => 'integer',
    'reward_items' => 'integer',
    'difficulty' => 'integer',
    'min_participants' => 'integer',
    'max_participants' => 'integer',
    'start_delay_hours' => 'integer',
    'preferred_start_at' => 'immutable_datetime',
    'scheduled_start_at' => 'immutable_datetime',
];

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'quest_event_participants',
            'event_id',
            'user_id'
        );
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }
}

