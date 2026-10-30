<?php

declare(strict_types=1);
namespace Maxcuso\Roleplay\Episodes;

use DOMDocument;
use Flarum\Foundation\ValidationException;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;

/** Новая публикация исполняет намерение один раз. Чтение и правка не являются командами. */
final class PublishCompletion
{
    public function __construct(private ConnectionInterface $db, private CompleteEpisode $completion) {}

    public function handle(Saving $saving): void
    {
        $post = $saving->post;
        if (!$post instanceof CommentPost || !$post->isDirty('content') || !str_contains($post->parsed_content, '<RP_EPISODE_END')) return;
        $document = new DOMDocument(); $document->loadXML($post->parsed_content, LIBXML_NONET);
        $pending = [];
        foreach ($document->getElementsByTagName('RP_EPISODE_END') as $node) {
            $quoted = false;
            for ($parent = $node->parentNode; $parent; $parent = $parent->parentNode) {
                if (in_array($parent->nodeName, ['QUOTE', 'CODE'], true)) {$quoted = true; break;}
            }
            if ($quoted) continue;
            $id = $node->getAttribute('episode-end');
            if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id)) {
                throw new ValidationException(['content' => 'Вставьте корректный код выдачи наград через панель редактора']);
            }
            if ($this->db->table('roleplay_episode_completions')->where('plan_id', $id)->exists()) continue;
            if ($post->exists) throw new ValidationException(['content' => 'Опубликуйте награды новым сообщением. Редактирование не выдаёт награды']);
            $pending[$id] = true;
        }
        if (!$pending) return;
        if (count($pending) !== 1) throw new ValidationException(['content' => 'Вставьте в сообщение только один код выдачи наград']);
        if ($this->db->transactionLevel() === 0) throw new \LogicException('Completion requires a post transaction');
        $id = array_key_first($pending);
        $post->afterSave(function (CommentPost $saved) use ($id, $saving): void {
            // Чужое скопированное намерение не становится командой от имени создателя.
            try {$this->completion->publish($id, $saved, $saving->actor);}
            catch (ApiProblem $problem) {throw new ValidationException(['content' => $problem->getMessage()]);}
        });
    }
}
