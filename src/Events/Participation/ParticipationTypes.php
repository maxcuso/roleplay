<?php

namespace Maxcuso\Roleplay\Events\Participation;

use DomainException;
use Maxcuso\Roleplay\Events\QuestEvent;

/** Реестр подключаемых правил. Движок не обращается к таблицам или классам Roleplay. */
final class ParticipationTypes
{
    private array $types = [];
    public function add(string $type, ParticipationType $rules): void { $this->types[$type] = $rules; }
    public function has(string $type): bool { return isset($this->types[$type]); }
    public function get(string $type): ?ParticipationType { return $this->types[$type] ?? null; }

    public function requireFor(QuestEvent $event): ?ParticipationType
    {
        if ($event->event_type === 'forum') return null;
        return $this->get($event->event_type)
            ?? throw new DomainException('Запись в квест доступна при подключённом Roleplay.');
    }

    /** Чистим связи и после смены типа события: старый тип мог оставить дополнительные данные. */
    public function remove(QuestEvent $event, ?int $userId = null): void
    {
        foreach ($this->types as $rules) $rules->remove($event, $userId);
    }
}
