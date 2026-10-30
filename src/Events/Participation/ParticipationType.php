<?php

namespace Maxcuso\Roleplay\Events\Participation;

use Flarum\User\User;
use Maxcuso\Roleplay\Events\QuestEvent;

/** Дополнение к общей записи. Тип события знает свои правила, движок — набор и запуск. */
interface ParticipationType
{
    public function options(QuestEvent $event, User $actor): array;

    /** Ошибка отменяет и запись, и дополнительные данные: вызов внутри транзакции регистрации. */
    public function register(QuestEvent $event, User $actor, array $input): void;
    public function remove(QuestEvent $event, ?int $userId): void;

    /** Дополнительные публичные подписи участников, индексированные по ID аккаунта. */
    public function participantData(QuestEvent $event): array;
}
