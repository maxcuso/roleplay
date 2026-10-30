<?php

namespace Maxcuso\Roleplay\Events;

use DomainException;
use Illuminate\Database\ConnectionInterface;
use Flarum\User\User;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;

class EventStarter
{
    private ConnectionInterface $database;
    private JsonApi $api;

    public function __construct(ConnectionInterface $database, JsonApi $api, private EventDrafts $drafts)
    {
        $this->database = $database;
        $this->api = $api;
    }

    public function startIfDue(int $eventId, ?User $actor = null, ?string $revision = null): void
    {
        // Keep the event locked while publication rolls back and its failure is recorded.
        $failure = $this->database->transaction(function () use ($eventId, $actor, $revision) {
            $event = QuestEvent::whereKey($eventId)->lockForUpdate()->first();
            if ($event === null) throw new DomainException('Событие не найдено');
            if ($actor !== null) {
                $actor->assertCan('quest-events.manage');
                EventAccess::assertView($event, $actor);
                if ($revision === null || !hash_equals(EventEditor::revision($event), $revision)) throw new DomainException('Событие изменилось. Обнови список и повтори попытку.');
            }
            $due = $event->status === 'recruiting' && $event->discussion_id === null
                && $event->scheduled_start_at !== null && $event->scheduled_start_at <= new \DateTimeImmutable();
            if (!$due) {
                if ($actor !== null) throw new DomainException('Повторный запуск доступен только после наступления назначенного времени.');
                return null;
            }
            try {
                $this->attempt($eventId);
                $event->refresh();
                $event->start_error = null;
                $event->start_failed_at = null;
                $event->save();
                return null;
            } catch (\Throwable $error) {
                $event->refresh();
                $message = $error instanceof DomainException ? $error->getMessage()
                    : ($error instanceof \Flarum\User\Exception\PermissionDeniedException
                        ? 'Ведущий не имеет права создать тему с выбранными параметрами. Проверь права и теги черновика.'
                        : ($error instanceof \Flarum\Foundation\ValidationException
                            ? 'Данные будущей темы не прошли проверку. Проверь заголовок, текст и теги черновика.'
                            : 'Не удалось создать тему. Проверь журнал форума и повтори запуск.'));
                // Repeated scheduler failures should not invalidate open editing forms every minute.
                if ($event->start_error !== $message) {
                    $event->start_error = $message;
                    $event->start_failed_at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                    $event->save();
                }
                return $error;
            }
        });
        if ($failure !== null) throw $failure;
    }

    private function attempt(int $eventId): void
    {
        $this->database->transaction(function () use ($eventId) {
            $event = QuestEvent::where('id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                throw new DomainException('Событие не найдено');
            }


            if ($event->status !== 'recruiting') return;

            if ($event->discussion_id !== null) return;

            if ($event->scheduled_start_at === null) return;

            $now = new \DateTimeImmutable();
            if ($event->scheduled_start_at > $now) return;

            $participantCount = $this->database->table('quest_event_participants')
                ->where('event_id', $event->id)
                ->count();

            $definition = new EventDefinition(
                $event->title,
                $event->min_participants,
                $event->max_participants
            );

            if (!$definition->hasMinimumParticipants($participantCount)) {
                $event->scheduled_start_at = null;
                $event->save();

                return;
            }

            EventAccess::assertPublished($event);
            if ($event->leader_id === null) throw new DomainException('Ведущий не назначен. Назначь ведущего и повтори запуск.');

            $leader = User::find($event->leader_id);

            if ($leader === null) throw new DomainException('Ведущий удалён. Назначь другого ведущего.');
            if (!EventAccess::canView($event, $leader)) throw new DomainException('Ведущий не имеет доступа к исходному сообщению.');

            $participants = $this->database->table('quest_event_participants')
                ->where('event_id', $event->id)
                ->orderBy('user_id')
                ->get();

            $title = $event->title;
            $content = $event->description ?? '';
            $relationships = [];
            if ($event->source_draft_id !== null) {
                $owner = $event->draft_owner_id === null ? null : User::find($event->draft_owner_id);
                if ($owner === null) throw new DomainException('Владелец черновика не найден. Запуск события отложен.');
                $draft = $this->drafts->read($owner, (int) $event->source_draft_id);
                $title = $draft['title'];
                $content = $draft['content'];
                $relationships = $draft['relationships'];
            }

            if ($content !== '') {
                $content .= "\n\n";
            }

            $content .= "Участники события:\n";

            foreach ($participants as $participant) {
                $content .= '- @"Участник"#' . $participant->user_id . "\n";
            }

            $discussion = $this->api
                ->forResource(DiscussionResource::class)
                ->forEndpoint('create')
                ->process([
                    'data' => [
                        'attributes' => [
                            'title' => $title,
                            'content' => $content,
                        ],
                        'relationships' => $relationships,
                    ],
                ], [], ['actor' => $leader]);

            $event->discussion_id = $discussion->id;
            $event->status = 'active';
            $event->scheduled_start_at = null;
            $event->save();
        });
    }
}

