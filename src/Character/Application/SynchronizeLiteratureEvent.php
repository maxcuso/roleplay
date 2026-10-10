<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Character\Application;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/** Адаптер событий: правила признания плашки находятся вне классов Flarum. */
final class SynchronizeLiteratureEvent
{
    public function __construct(private readonly LiteratureSynchronizer $synchronizer, private readonly ConnectionInterface $db) {}
    public function handle(object $event): void
    {
        $discussionId = (int) ($event->discussion->id ?? $event->post->discussion_id ?? 0);
        $actor = $event->actor ?? $event->user ?? null;
        // Hidden/Restored могут не содержать актора; для чтения берём автора поста.
        if (!$actor && isset($event->post)) $actor = User::find($event->post->user_id);
        if (!$actor && isset($event->discussion)) $actor = User::find($event->discussion->user_id);
        if ($discussionId > 0 && $actor) {
            // Сначала форум завершает запись поста/закрытия; затем берём блокировки каталога и анкеты.
            $this->db->afterCommit(fn () => $this->synchronizer->synchronize($discussionId, $actor));
        }
    }
}