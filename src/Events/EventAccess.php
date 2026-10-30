<?php
namespace Maxcuso\Roleplay\Events;

use DomainException;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Event references never grant more access than their original post. */
class EventAccess
{
    /** Старое право сохраняет создание обоих типов, новое — только форумных событий. */
    public static function assertCreate(User $actor, string $type): void
    {
        if ($type === 'forum' && $actor->can('quest-events.createForum')) return;
        $actor->assertCan('quest-events.create');
    }

    public static function assertCreateAny(User $actor): void
    {
        if ($actor->can('quest-events.createForum')) return;
        $actor->assertCan('quest-events.create');
    }

    public static function scope(Builder $query, User $actor): Builder
    {
        if (!$actor->can('viewForum')) return $query->whereRaw('1=0');
        return $query->where(function ($q) use ($actor) {
            $q->whereIn('source_post_id', Post::whereVisibleTo($actor)->select('posts.id'));
            if ($actor->can('quest-events.manage')) $q->orWhereNull('source_post_id');
        });
    }

    public static function canView(QuestEvent $event, User $actor): bool
    {
        return self::scope(QuestEvent::query(), $actor)->whereKey($event->id)->exists();
    }

    public static function assertView(QuestEvent $event, User $actor): void
    {
        if (!self::canView($event, $actor)) throw (new ModelNotFoundException())->setModel(QuestEvent::class);
    }

    public static function isPublished(QuestEvent $event): bool
    {
        if ($event->source_post_id === null) return false;
        $post = Post::find($event->source_post_id);
        $discussion = $post?->discussion;
        return $post !== null && $discussion !== null
            && $post->hidden_at === null && $discussion->hidden_at === null
            && $post->getAttribute('is_approved') !== false && $post->getAttribute('is_approved') !== 0
            && $discussion->getAttribute('is_approved') !== false && $discussion->getAttribute('is_approved') !== 0;
    }

    public static function assertPublished(QuestEvent $event): void
    {
        if (!self::isPublished($event)) throw new DomainException('Исходное сообщение отсутствует, скрыто или ожидает одобрения. Набор и запуск приостановлены.');
    }
}
