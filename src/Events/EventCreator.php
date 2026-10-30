<?php

namespace Maxcuso\Roleplay\Events;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Flarum\User\User;
use InvalidArgumentException;

class EventCreator
{
    public function __construct(private EventDrafts $drafts, private QuestSettings $settings)
    {
    }

    public function create(
        User $actor,
        string $title,
        ?string $description,
        int $minParticipants,
        int $maxParticipants,
        int $startDelayHours,
        ?DateTimeImmutable $preferredStartAt = null,
        ?int $leaderId = null,
        ?int $draftId = null,
        string $eventType = 'forum',
        ?int $difficulty = null,
        ?string $customColor = null,
        array $reward = []
    ): QuestEvent {
        EventAccess::assertCreate($actor, $eventType);
        $this->settings->assertTypeAllowed($eventType);
        $appearance = EventAppearance::validate($eventType, $difficulty, $customColor);

        $definition = new EventDefinition($title, $minParticipants, $maxParticipants);

        // The event title will also be used by Flarum's discussion API.
        $titleLength = mb_strlen($definition->title, 'UTF-8');
        if ($titleLength < 3 || $titleLength > 80) {
            throw new InvalidArgumentException('Название должно содержать от 3 до 80 символов');
        }

        $description = $description === null ? null : trim($description);
        if ($description === '') {
            $description = null;
        }

        if ($description !== null && mb_strlen($description, 'UTF-8') > 1000) {
            throw new InvalidArgumentException('Описание должно содержать не больше 1000 символов');
        }

        if ($startDelayHours <= 0) {
            throw new InvalidArgumentException('Интервал до старта должен быть больше нуля');
        }

        $leader = User::find($leaderId ?? $actor->id);
        if ($leader === null) {
            throw new DomainException('Ведущий не найден');
        }

        $event = new QuestEvent();
        foreach ($appearance + EventReward::columns($eventType, $reward) as $field => $value) {
            $event->$field = $value;
        }
        $event->title = $definition->title;
        $event->description = $description;
        $event->min_participants = $definition->minParticipants;
        $event->max_participants = $definition->maxParticipants;
        $event->start_delay_hours = $startDelayHours;
        $event->preferred_start_at = $preferredStartAt?->setTimezone(new DateTimeZone('UTC'));
        $event->leader_id = $leader->id;
        $event->status = 'recruiting';
        $event->scheduled_start_at = null;
        $event->discussion_id = null;
        if ($draftId !== null) {
            $draft = $this->drafts->read($actor, $draftId);
            $event->source_draft_id = $draft['id'];
            $event->draft_owner_id = $actor->id;
        }
        $event->save();

        return $event;
    }
}
