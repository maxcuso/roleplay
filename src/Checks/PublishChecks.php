<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Checks;

use DOMDocument;
use Flarum\Foundation\ValidationException;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\{ApiProblem, OperationRequest};

final class PublishChecks
{
    public function __construct(private ConnectionInterface $db, private CheckPlans $plans) {}

    public function handle(Saving $event): void
    {
        $post = $event->post;
        if (!$post instanceof CommentPost || !$post->isDirty('content') || !str_contains($post->parsed_content, '<RP_CHECK_')) return;
        $document = new DOMDocument(); $document->loadXML($post->parsed_content, LIBXML_NONET); $pending = [];
        foreach (['request', 'roll'] as $type) foreach ($document->getElementsByTagName('RP_CHECK_'.strtoupper($type)) as $node) {
            for ($parent = $node->parentNode; $parent; $parent = $parent->parentNode) if (in_array($parent->nodeName, ['QUOTE', 'CODE'], true)) continue 2;
            try {$id = OperationRequest::key($node->getAttribute('check-'.$type));}
            catch (ApiProblem) {throw new ValidationException(['content' => 'Вставьте код проверки через панель редактора']);}
            $published = $this->db->table('roleplay_checks')->where('id', $id)->where('type', $type)->whereNotNull('post_id')->first();
            if ($published) {
                if ($post->exists && (int) $published->post_id === (int) $post->id) continue;
                throw new ValidationException(['content' => 'Эта проверка уже опубликована. Для новой попытки вставьте новый код через панель редактора']);
            }
            if ($post->exists) throw new ValidationException(['content' => 'Опубликуйте новую проверку отдельным сообщением. Редактирование не меняет бросок']);
            $pending[$id] = $type;
        }
        if (!$pending) return;
        if (count($pending) > 30) throw new ValidationException(['content' => 'Укажите не больше 30 проверок в одном сообщении']);
        $post->afterSave(function (CommentPost $saved) use ($pending, $event): void {
            try {foreach ($pending as $id => $type) $this->plans->publish($id, $type, $saved, $event->actor);}
            catch (ApiProblem $problem) {throw new ValidationException(['content' => $problem->getMessage()]);}
        });
    }
}
