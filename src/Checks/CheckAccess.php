<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Checks;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\{Access, ApiProblem};
use Maxcuso\Roleplay\Events\QuestEvent;

final class CheckAccess
{
    public static function enabled(): bool
    {
        return class_exists(\forumaker\MagicDice\Support\DiceRoller::class)
            && resolve(ExtensionManager::class)->isEnabled('forumaker-magicdice');
    }

    public static function discussion(?int $id, User $actor): ?Discussion
    {
        Access::view($actor);
        if (!$actor->exists) throw new ApiProblem('check_forbidden', 'Войдите в аккаунт, чтобы продолжить', 403);
        if (!self::enabled()) throw new ApiProblem('dice_unavailable', 'Кубики сейчас недоступны. Обратитесь к администратору', 409);
        if ($id === null) return null;
        $discussion = Discussion::whereVisibleTo($actor)->find($id);
        if (!$discussion) throw new ApiProblem('check_not_found', 'Тема не найдена или недоступна', 404);
        $actor->assertCan('reply', $discussion);
        return $discussion;
    }

    public static function request(ConnectionInterface $db, string $id, User $actor, ?int $discussionId): array
    {
        $row = $db->table('roleplay_checks')->where('id', $id)->where('type', 'request')->whereNotNull('post_id')->first();
        if (!$row || !Post::whereVisibleTo($actor)->where('id', $row->post_id)->exists()) throw new ApiProblem('check_not_found', 'Запрос проверки не найден или недоступен', 404);
        if ($discussionId === null || (int) $row->discussion_id !== $discussionId) throw new ApiProblem('check_topic_mismatch', 'Сделайте бросок в теме, где опубликован запрос');
        return json_decode($row->result, true, flags: JSON_THROW_ON_ERROR);
    }

    /** Выбранный в другом месте профиля персонаж не подменяет участника эпизода. */
    public static function eligible(ConnectionInterface $db, ?int $discussionId, int $characterId, ?array $request): bool
    {
        if ($request === null) return true;
        if ($request['target'] === 'selected') return in_array($characterId, $request['characters'], true);
        $event = $discussionId ? QuestEvent::where('discussion_id', $discussionId)->where('event_type', 'quest')->first() : null;
        return !$event || $db->table('roleplay_quest_participants')->where('event_id', $event->id)->where('character_id', $characterId)->exists();
    }
}
