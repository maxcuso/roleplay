<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Application;

use Flarum\User\User;

/** Граница с форумом. Проверки используют временные темы, без публикации на рабочем форуме. */
interface ApplicationTopics
{
    public function create(User $actor, int $tagId, string $title, string $content): int;

    /** Возвращает available/hash и accepted с ID сообщения/модератора, если тема принята.
     * hash относится к первому посту; accepted — к последнему обычному сообщению и закрытию.
     * Литературный текст в Roleplay не копируется.
     */
    public function inspect(User $actor, int $discussionId): array;
}