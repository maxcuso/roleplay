<?php
declare(strict_types=1);

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Character\Application\ApplicationTopics;

/** Только тестовый адаптер: никаких записей в реальные discussions/posts/users. */
final class TemporaryApplicationTopics implements ApplicationTopics
{
    public bool $fail = false;
    public array $accepted = [];
    public function __construct(private readonly ConnectionInterface $db) {}
    public function create(User $actor, int $tagId, string $title, string $content): int
    {
        $id = (int) $this->db->table('roleplay_test_topics')->insertGetId([
            'user_id' => $actor->id, 'tag_id' => $tagId, 'title' => $title, 'content' => $content,
        ]);
        // Ошибка после записи проверяет откат темы вместе со всей анкетой.
        if ($this->fail) throw new \RuntimeException('Topic creation test failure');
        return $id;
    }
    public function inspect(User $actor, int $discussionId): array
    {
        $row = $this->db->table('roleplay_test_topics')->where('id', $discussionId)->sharedLock()->first();
        return !$row || $row->hidden ? ['available' => false, 'hash' => null]
            : ['available' => true, 'hash' => hash('sha256', $row->content),
                'accepted' => isset($this->accepted[$discussionId]),
                'acceptance_post_id' => isset($this->accepted[$discussionId]) ? 123 : null,
                'acceptance_user_id' => $this->accepted[$discussionId] ?? null];
    }
}