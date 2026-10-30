<?php

namespace Maxcuso\Roleplay\Events\Listener;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DomainException;
use Flarum\Foundation\ValidationException;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use LogicException;
use Maxcuso\Roleplay\Events\EventCreator;
use Maxcuso\Roleplay\Events\EventAccess;
use Maxcuso\Roleplay\Events\QuestEvent;
use s9e\TextFormatter\Unparser;

class CreateEventsFromPost
{
    public function __construct(
        private EventCreator $creator,
        private ConnectionInterface $database
    ) {
    }

    public function handle(Saving $saving): void
    {
        $post = $saving->post;
        if (!$post instanceof CommentPost || !$post->isDirty('content')) {
            return;
        }

        $xml = $post->parsed_content;
        if (!str_contains($xml, '<QUEST_EVENT')) {
            return;
        }

        $document = new DOMDocument();
        $document->loadXML($xml, LIBXML_NONET);
        $nodes = iterator_to_array($document->getElementsByTagName('QUEST_EVENT'));
        $createdIds = [];

        foreach ($nodes as $node) {
            if ($node->hasAttribute('quest')) {
                // Existing references never update event parameters.
                if ($node->attributes->length !== 1) {
                    $this->invalid('Существующее событие редактируется только на странице управления. Используйте [quest=ID].');
                }
                continue;
            }

            EventAccess::assertCreate($saving->actor, $node->hasAttribute('type') ? $node->getAttribute('type') : 'forum');
            if ($this->database->transactionLevel() === 0) {
                throw new LogicException('Creating an event from a post requires a transaction.');
            }

            $description = $this->description($node, $document);
            $minimum = $this->positiveInteger($node, 'min');
            $maximum = $this->positiveInteger($node, 'max');
            $delay = $this->positiveInteger($node, 'delay');
            $leader = $node->hasAttribute('leader') ? $this->positiveInteger($node, 'leader') : null;
            $start = null;
            if ($node->hasAttribute('start')) {
                $value = $node->getAttribute('start');
                $start = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
                if ($start === false || $start->format('Y-m-d\TH:i:sP') !== $value) {
                    $this->invalid('Дата start должна иметь вид 2026-09-20T18:00:00+03:00.');
                }
            }
            $draft = $node->hasAttribute('draft') ? $this->positiveInteger($node, 'draft') : null;

            try {
                $event = $this->creator->create(
                    $saving->actor,
                    $node->getAttribute('title'),
                    $description,
                    $minimum,
                    $maximum,
                    $delay,
                    $start,
                    $leader,
                    $draft,
                    $node->hasAttribute('type') ? $node->getAttribute('type') : 'forum',
                    $node->hasAttribute('difficulty') ? $this->positiveInteger($node, 'difficulty') : null,
                    $node->hasAttribute('color') ? $node->getAttribute('color') : null,
                    [
                        'experience' => $node->hasAttribute('xp') ? $node->getAttribute('xp') : 0,
                        'gold' => $node->hasAttribute('gold') ? $node->getAttribute('gold') : 0,
                        'items' => $node->hasAttribute('items') ? $node->getAttribute('items') : 0,
                    ]
                );
            } catch (InvalidArgumentException | DomainException $exception) {
                $this->invalid($exception->getMessage());
            }

            $createdIds[] = $event->id;
            $replacement = $document->createElement('QUEST_EVENT');
            $replacement->setAttribute('quest', (string) $event->id);
            $replacement->appendChild($document->createTextNode('[quest=' . $event->id . ']'));
            $node->parentNode->replaceChild($replacement, $node);
        }

        if ($createdIds !== []) {
            $post->parsed_content = $document->saveXML($document->documentElement);
            // afterSave runs inside the surrounding transaction, once the post has an ID.
            $post->afterSave(function (CommentPost $saved) use ($createdIds) {
                QuestEvent::whereIn('id', $createdIds)->update(['source_post_id' => $saved->id]);
            });
        }
    }

    private function description(DOMElement $node, DOMDocument $document): string
    {
        $opening = null;
        $closing = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 's') {
                $opening = $child->textContent;
            }
            if ($child instanceof DOMElement && $child->tagName === 'e') {
                $closing = $child->textContent;
            }
        }
        if ($opening === null || $closing === null || $node->getElementsByTagName('QUEST_EVENT')->length > 0) {
            $this->invalid('Полный код события должен заканчиваться [/quest]. События нельзя вкладывать друг в друга.');
        }

        $source = Unparser::unparse('<r>' . $document->saveXML($node) . '</r>');
        $description = substr($source, strlen($opening), -strlen($closing));
        if (preg_match('/\[\/?quest(?:\s|=|\])/i', $description)) {
            $this->invalid('События нельзя вкладывать друг в друга.');
        }
        return $description;
    }

    private function positiveInteger(DOMElement $node, string $attribute): int
    {
        $value = $node->getAttribute($attribute);
        if (!preg_match('/^[1-9][0-9]*$/D', $value) || strlen($value) > 10 || (int) $value > 4294967295) {
            $this->invalid("Параметр $attribute должен быть целым числом от 1 до 4294967295.");
        }
        return (int) $value;
    }

    private function invalid(string $message): never
    {
        throw new ValidationException(['content' => $message]);
    }
}
