<?php

namespace Maxcuso\Roleplay\Events\Api;

use Flarum\User\User;
use Flarum\Http\SlugManager;
use Maxcuso\Roleplay\Events\QuestEvent;
use Maxcuso\Roleplay\Events\EventAppearance;
use Maxcuso\Roleplay\Events\EventReward;
use Maxcuso\Roleplay\Events\EventDescription;

class EventData
{
    public function __construct(private SlugManager $slugManager, private \Maxcuso\Roleplay\Events\Participation\ParticipationTypes $types)
    {
    }

    public function serialize(QuestEvent $event, User $actor): array
    {
        \Maxcuso\Roleplay\Events\EventAccess::assertView($event, $actor);
        $participants = [];
        $rules = $this->types->get($event->event_type);
        $details = $rules?->participantData($event) ?? [];
        foreach ($event->participants as $participant) {
            $participants[] = $this->user($participant) + ($details[(int) $participant->id] ?? []);
        }
        $joined = $actor->exists && $event->participants->contains('id', $actor->id);

        return [
            'id' => (int) $event->id,
            'title' => $event->title,
            'discussionId' => $event->discussion_id === null ? null : (int) $event->discussion_id,
            'description' => $event->description,
            'descriptionHtml' => EventDescription::html($event->description),
            'reward' => EventReward::data($event),
            'status' => $event->status,
            'eventType' => $event->event_type,
            'difficulty' => $event->difficulty,
            'cardColor' => EventAppearance::color($event),
            'leader' => $event->leader === null ? null : $this->user($event->leader),
            'minParticipants' => $event->min_participants,
            'maxParticipants' => $event->max_participants,
            'participantCount' => (int) $event->participants_count,
            'participants' => $participants,
            'isParticipating' => $joined,
            'registrationOptions' => $event->event_type === 'quest' && !$joined && $event->status === 'recruiting'
                ? ($rules?->options($event, $actor) ?? ['choices' => [], 'error' => 'Запись в квест доступна при подключённом Roleplay.']) : null,
            'registrationOpen' => \Maxcuso\Roleplay\Events\EventAccess::isPublished($event) && $event->status === 'recruiting' && (int) $event->participants_count < $event->max_participants,
            'canLeave' => $joined && $event->status === 'recruiting',
            'scheduledStartAt' => $event->scheduled_start_at?->format(DATE_ATOM),
        ];
    }

    private function user(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => $user->username,
            'slug' => $this->slugManager->forResource(User::class)->toSlug($user),
            'displayName' => $user->display_name,
            'avatarUrl' => $user->avatar_url,
        ];
    }
}

