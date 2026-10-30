<?php

namespace Maxcuso\Roleplay\Events;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class EventManagement
{
    private ConnectionInterface $database;
    private EventStarter $starter;
    private LoggerInterface $logger;

    public function __construct(
        ConnectionInterface $database,
        EventStarter $starter,
        LoggerInterface $logger
    ) {
        $this->database = $database;
        $this->starter = $starter;
        $this->logger = $logger;
    }

    public function assignLeader(User $actor, int $eventId, int $leaderId): void
    {
        $actor->assertCan('quest-events.manage');

        $this->database->transaction(function () use ($actor, $eventId, $leaderId) {
            $leader = User::where('id', $leaderId)->lockForUpdate()->first();
            if ($leader === null) {
                throw new DomainException('Ведущий не найден');
            }

            $event = QuestEvent::where('id', $eventId)->lockForUpdate()->first();
            if ($event === null) {
                throw new DomainException('Событие не найдено');
            }
            EventAccess::assertView($event, $actor);

            $event->leader_id = $leader->id;
            $event->save();

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($event->status === 'recruiting'
                && $event->discussion_id === null
                && $event->scheduled_start_at !== null
                && $event->scheduled_start_at <= $now
            ) {
                $this->database->afterCommit(function () use ($eventId) {
                    try {
                        $this->starter->startIfDue($eventId);
                    } catch (Throwable $exception) {
                        $this->logger->error('Не удалось запустить событие после назначения ведущего', [
                            'event_id' => $eventId,
                            'exception' => $exception,
                        ]);
                    }
                });
            }
        });
    }
}
