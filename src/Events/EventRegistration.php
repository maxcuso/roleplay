<?php

namespace Maxcuso\Roleplay\Events;

use DomainException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class EventRegistration
{
    private ConnectionInterface $database;
    private EventStarter $starter;
    private LoggerInterface $logger;

    public function __construct(
        ConnectionInterface $database,
        EventStarter $starter,
        LoggerInterface $logger,
        private \Maxcuso\Roleplay\Events\Participation\ParticipationTypes $types
    ) {
        $this->database = $database;
        $this->starter = $starter;
        $this->logger = $logger;
    }

    private function checkEligibility(QuestEvent $event, User $user): void
    {   

        $eventId = $event->id;
        $userId = $user->id;

        if ($event->status !== 'recruiting') {
            throw new DomainException("Запись на событие закрыта");
        }

        $alreadyRegistered = $this->database->table('quest_event_participants')
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->exists();

        if ($alreadyRegistered) {
            throw new DomainException("Вы уже записаны");
        }

        $participantCount = $this->database->table('quest_event_participants')
            ->where('event_id', $eventId)
            ->count();

        $definition = new EventDefinition(
            $event->title,
            $event->min_participants,
            $event->max_participants
        );

        if (!$definition->hasAvailableSlot($participantCount)) {
            throw new DomainException("Нет свободных мест");
        }

        $occupiedSlots = $this->database->table('quest_event_participants as p')
            ->join('quest_events as e', 'e.id', '=', 'p.event_id')
            ->where('p.user_id', $userId)
            ->whereIn('e.status', ['recruiting', 'active'])
            ->count();


        if ($occupiedSlots >= 2) {
            throw new DomainException("Вы уже участвуете в двух событиях");
        }
    }

    public function register(int $eventId, int $userId, array $input = []): void
    {
        $this->database->transaction(function () use ($eventId, $userId, $input) {
            $user = User::where('id', $userId)
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                throw new DomainException('Пользователь не найден');
            }

            $event = QuestEvent::where('id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                throw new DomainException('Событие не найдено');
            }

            EventAccess::assertView($event, $user);
            EventAccess::assertPublished($event);
            $this->checkEligibility($event, $user);

            $this->database->table('quest_event_participants')->insert([
                'event_id' => $event->id,
                'user_id' => $user->id,
            ]);

            // Сохраняем данные дополнения в той же транзакции, до планирования запуска.
            $this->types->requireFor($event)?->register($event, $user, $input);

            $participantCount = $this->database->table('quest_event_participants')
                ->where('event_id', $event->id)
                ->count();

            $definition = new EventDefinition(
                $event->title,
                $event->min_participants,
                $event->max_participants
            );

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            $hasFutureDate = $event->preferred_start_at !== null
                && $event->preferred_start_at > $now;

            $isFull = !$definition->hasAvailableSlot($participantCount);

            if ($isFull && !$hasFutureDate) {
                $event->scheduled_start_at = $now;
            } elseif (
                $definition->hasMinimumParticipants($participantCount)
                && $event->scheduled_start_at === null
            ) {
                $event->scheduled_start_at = $definition->calculateStartAt(
                    $now,
                    $event->preferred_start_at,
                    $event->start_delay_hours
                );
            }

            if ($event->isDirty('scheduled_start_at')) {
                $event->save();
            }

            if ($event->scheduled_start_at !== null && $event->scheduled_start_at <= $now) {
                $this->database->afterCommit(function () use ($eventId) {
                    try {
                        $this->starter->startIfDue($eventId);
                    } catch (Throwable $exception) {
                        // Registration is committed; the scheduler can retry publication.
                        $this->logger->error('Не удалось запустить событие после записи участника', [
                            'event_id' => $eventId,
                            'exception' => $exception,
                        ]);
                    }
                });
            }
        });
    }

    public function leave(int $eventId, int $userId): void
    {
        $this->removeParticipation($eventId, $userId, false);
    }

    public function removeParticipant(User $actor, int $eventId, int $userId, ?string $revision = null): void
    {
        $actor->assertCan('quest-events.manage');

        $this->removeParticipation($eventId, $userId, true, $actor, $revision);
    }

    private function removeParticipation(int $eventId, int $userId, bool $allowActive, ?User $actor = null, ?string $revision = null): void
    {
        $this->database->transaction(function () use ($eventId, $userId, $allowActive, $actor, $revision) {
            $user = User::where('id', $userId)
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                throw new DomainException('Пользователь не найден');
            }

            $event = QuestEvent::where('id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                throw new DomainException('Событие не найдено');
            }

            EventAccess::assertView($event, $actor ?? $user);
            if ($revision !== null && !hash_equals(EventEditor::revision($event), $revision)) throw new DomainException('Состав изменился. Обнови список участников.');

            if (!$allowActive && $event->status !== 'recruiting') {
                throw new DomainException(
                    'Самостоятельный выход доступен только во время набора'
                );
            }

            if (!in_array($event->status, ['recruiting', 'active'], true)) {
                throw new DomainException('Нельзя изменять состав завершённого события');
            }

            $this->database->table('quest_event_participants')
                ->where('event_id', $eventId)
                ->where('user_id', $userId)
                ->delete();

            $this->types->remove($event, $userId);

            $participantCount = $this->database->table('quest_event_participants')
                ->where('event_id', $event->id)
                ->count();

            $definition = new EventDefinition(
                $event->title,
                $event->min_participants,
                $event->max_participants
            );

            if ($event->status === 'recruiting'
                && !$definition->hasMinimumParticipants($participantCount)
                && $event->scheduled_start_at !== null
            ) {
                $event->scheduled_start_at = null;
                $event->start_error = null;
                $event->start_failed_at = null;
                $event->save();
            }
        });
    }
}

