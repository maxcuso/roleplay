<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Application;

use Flarum\Post\Post;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/** Позволяет заполнять первый пост своей создаваемой анкеты после обычного срока редактирования. */
final class ApplicationPostPolicy extends AbstractPolicy
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function edit(User $actor, Post $post): ?string
    {
        if (!$actor->exists || (int) $post->number !== 1 || (int) $post->user_id !== (int) $actor->id
            || $actor->isAdmin()) return null;
        $character = $this->db->table('roleplay_characters')->where('discussion_id', $post->discussion_id)
            ->where('user_id', $actor->id)->first();
        if (!$character) return null;
        if ($character->status !== 'creating') return $this->deny();
        if ($post->hidden_at || !$actor->can('view', $post->discussion) || !$actor->can('reply', $post->discussion) || !$actor->can('roleplay.view')) return $this->deny();
        return $this->allow();
    }
}