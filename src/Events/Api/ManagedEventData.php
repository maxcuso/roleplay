<?php

namespace Maxcuso\Roleplay\Events\Api;

use Flarum\User\User;
use Maxcuso\Roleplay\Events\EventEditor;
use Maxcuso\Roleplay\Events\QuestEvent;

class ManagedEventData extends EventData
{
    public function serialize(QuestEvent $event, User $actor): array
    {
        $actor->assertCan('quest-events.manage');
        return parent::serialize($event, $actor) + [
            'startError' => $event->start_error,
            'startFailedAt' => $event->start_failed_at?->format(DATE_ATOM),
            'canRetryStart' => $event->status === 'recruiting' && $event->discussion_id === null && $event->scheduled_start_at !== null && $event->scheduled_start_at <= new \DateTimeImmutable(),
            'sourcePublished' => \Maxcuso\Roleplay\Events\EventAccess::isPublished($event),
            'revision' => EventEditor::revision($event),
            'startDelayHours' => $event->start_delay_hours,
            'preferredStartAt' => $event->preferred_start_at?->format(DATE_ATOM),
            'sourceDraftId' => $event->source_draft_id,
        ];
    }
}

