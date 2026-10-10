<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Application;

use Flarum\Api\Client;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Flarum\Tags\Tag;
use Maxcuso\Roleplay\Character\Moderation\ReviewPermissions;
use Maxcuso\Roleplay\Api\ApiProblem;

/** API установленного Flarum 2 сохраняет права, formatter, теги и события первого поста. */
final class FlarumApplicationTopics implements ApplicationTopics
{
    public function __construct(private readonly Client $client) {}

    public function create(User $actor, int $tagId, string $title, string $content): int
    {
        $tag = Tag::query()->find($tagId);
        if (!$tag) throw new ApiProblem('application_tag_missing', 'Тег литературных анкет больше не существует.');
        // При выборе дочернего тега Flarum ожидает также его родительский основной тег.
        $tagIds = $tag->parent_id ? [(int) $tag->parent_id, $tagId] : [$tagId];
        $response = $this->client->withActor($actor)->withBody([
            'data' => [
                'type' => 'discussions',
                'attributes' => ['title' => $title, 'content' => $content],
                'relationships' => ['tags' => ['data' => array_map(fn (int $id) => ['type' => 'tags', 'id' => (string) $id], $tagIds)]],
            ],
        ])->post('/discussions');
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if ($response->getStatusCode() !== 201) {
            $detail = $body['errors'][0]['detail'] ?? 'Не удалось создать тему. Проверь доступ к тегу анкет.';
            throw new ApiProblem('application_topic_failed', (string) $detail, 422);
        }
        $id = $body['data']['id'] ?? null;
        if (!is_string($id) || !ctype_digit($id) || (int) $id < 1) {
            throw new \RuntimeException('Flarum не вернул ID созданной темы.');
        }
        return (int) $id;
    }

    public function inspect(User $actor, int $discussionId): array
    {
        $discussion = Discussion::whereVisibleTo($actor)->where('id', $discussionId)->with('firstPost')->sharedLock()->first();
        $post = $discussion?->firstPost;
        if (!$discussion || !$post || $discussion->hidden_at || $post->hidden_at) {
            return ['available' => false, 'hash' => null];
        }
        // Блокировка поста защищает проверку и активацию от одновременного сохранения другого текста.
        $post = $post->newQuery()->where('id', $post->id)->sharedLock()->first();
        if (!$post || $post->hidden_at) return ['available' => false, 'hash' => null];
        // Служебный пост о закрытии не заменяет последнее обычное сообщение.
        $last = $discussion->posts()->where('type', 'comment')->whereNull('hidden_at')
            ->whereVisibleTo($actor)->orderByDesc('number')->sharedLock()->first();
        $author = $last ? User::find($last->user_id) : null;
        $reviewer = $author && (ReviewPermissions::staff($author) || $author->can('editPosts', $discussion));
        $accepted = (bool) $discussion->is_locked && $last && $reviewer
            && ($last->getAttribute('is_approved') === null || (bool) $last->getAttribute('is_approved')) && LiteratureAcceptance::matches((string) $last->content);
        return ['available' => true, 'accepted' => (bool) $accepted,
            'acceptance_post_id' => $accepted ? (int) $last->id : null,
            'acceptance_user_id' => $accepted ? (int) $author->id : null,
            'hash' => hash('sha256', json_encode([
            $discussionId, (int) $post->id, $post->getRawOriginal('content'), (string) $post->edited_at,
        ], JSON_THROW_ON_ERROR))];
    }
}