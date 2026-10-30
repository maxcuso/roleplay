<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Episodes;

use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Maxcuso\Roleplay\Api\Access;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Events\EventAccess;
use Maxcuso\Roleplay\Events\QuestEvent;
use Maxcuso\Roleplay\Events\EventEditor;

/** Право закрытия именно этой темы; назначение ведущим не заменяет его. */
final class CompletionAccess
{
    public static function context(int $discussionId, User $actor, bool $lock = false): array
    {
        Access::view($actor);
        if (!$actor->exists) throw new ApiProblem('completion_forbidden', 'Войдите в аккаунт, чтобы продолжить', 403);
        $query = Discussion::whereVisibleTo($actor);
        if ($lock) $query->lockForUpdate();
        $discussion = $query->find($discussionId);
        if (!$discussion) throw new ApiProblem('episode_not_found', 'Тема не найдена или недоступна', 404);
        $actor->assertCan('lock', $discussion);
        $events = QuestEvent::where('discussion_id', $discussionId)->where('event_type', 'quest');
        if ($lock) $events->lockForUpdate();
        $event = $events->first();
        if ($event) EventAccess::assertView($event, $actor);
        if ($event && ($event->status !== 'active' || $discussion->is_locked)) {
            throw new ApiProblem('episode_completed', 'Эпизод уже завершён или закрыт', 409);
        }
        // Пустой event_id означает обычную выдачу. Тип темы проверяем заново при публикации.
        return ['event' => $event, 'discussion' => $discussion,
            'revision' => $event ? EventEditor::revision($event) : hash('sha256', 'topic-reward:'.$discussionId.':'.$discussion->title)];
    }
}
