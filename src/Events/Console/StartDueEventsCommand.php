<?php

namespace Maxcuso\Roleplay\Events\Console;

use Flarum\Console\AbstractCommand;
use Maxcuso\Roleplay\Events\EventStarter;
use Maxcuso\Roleplay\Events\QuestEvent;
use Psr\Log\LoggerInterface;
use Throwable;

class StartDueEventsCommand extends AbstractCommand
{
    private EventStarter $starter;
    private LoggerInterface $logger;

    public function __construct(
        EventStarter $starter,
        LoggerInterface $logger
    ) {
        $this->starter = $starter;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('quest-events:start-due');
        $this->setDescription('Запустить события, время которых наступило');
    }

    protected function fire(): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $eventIds = QuestEvent::where('status', 'recruiting')
            ->whereNull('discussion_id')
            ->whereNotNull('scheduled_start_at')
            ->where('scheduled_start_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id');

        $failed = 0;

        foreach ($eventIds as $eventId) {
            try {
                $this->starter->startIfDue((int) $eventId);
            } catch (Throwable $exception) {
                $failed++;

                $this->logger->error('Не удалось запустить событие', [
                    'event_id' => $eventId,
                    'exception' => $exception,
                ]);

                $this->error('Ошибка запуска события #' . $eventId);
            }
        }

        $this->info(
            'Кандидатов: ' . $eventIds->count() . '. Ошибок: ' . $failed
        );

        return $failed === 0 ? 0 : 1;
    }
}