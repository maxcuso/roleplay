<?php

namespace Maxcuso\Roleplay\Events\Listener;

use Flarum\Discussion\Event\Deleting;
use Flarum\Discussion\Event\Hidden;
use Flarum\Lock\Event\DiscussionWasLocked;
use Maxcuso\Roleplay\Events\QuestEvent;

class CompleteEventOnDiscussionChange
{
    public function onLocked(DiscussionWasLocked $notification): void
    {
        $this->complete($notification->discussion->id);
    }

    public function onHidden(Hidden $notification): void
    {
        $this->complete($notification->discussion->id);
    }

    public function onDeleting(Deleting $notification): void
    {
        // Complete before ON DELETE SET NULL removes the discussion link.
        $this->complete($notification->discussion->id);
    }

    private function complete(int $discussionId): void
    {
        QuestEvent::where('discussion_id', $discussionId)
            ->where('status', 'active')
            ->update([
                'status' => 'completed',
                'scheduled_start_at' => null,
            ]);
    }
}
