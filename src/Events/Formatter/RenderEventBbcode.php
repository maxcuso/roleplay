<?php

namespace Maxcuso\Roleplay\Events\Formatter;

use DOMDocument;
use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Events\QuestEvent;
use Maxcuso\Roleplay\Events\EventDescription;
use Maxcuso\Roleplay\Events\EventReward;
use Psr\Http\Message\ServerRequestInterface;
use s9e\TextFormatter\Renderer;

class RenderEventBbcode
{
    public function __invoke(
        Renderer $renderer,
        mixed $context,
        string $xml,
        ?ServerRequestInterface $request = null
    ): string {
        if (!str_contains($xml, '<QUEST_EVENT')) {
            return $xml;
        }

        // This XML comes from Flarum's parser. Never load external XML entities.
        $document = new DOMDocument();
        $document->loadXML($xml, LIBXML_NONET);
        $nodes = $document->getElementsByTagName('QUEST_EVENT');
        $ids = [];

        foreach ($nodes as $node) {
            $ids[] = (int) $node->getAttribute('quest');
        }

        // Apply the same source-post visibility as the API.
        $canView = $request !== null && RequestUtil::getActor($request)->can('viewForum');
        $events = $canView
            ? \Maxcuso\Roleplay\Events\EventAccess::scope(QuestEvent::query(), RequestUtil::getActor($request))->whereIn('id', array_unique($ids))->with('leader')->withCount('participants')->get()->keyBy('id')
            : collect();

        foreach ($nodes as $node) {
            // Display values are derived from the database, never from BBCode attributes.
            foreach (['available', 'title', 'description', 'minimum', 'maximum', 'count', 'leader', 'status', 'reward_experience', 'reward_gold', 'reward_items'] as $attribute) {
                $node->removeAttribute($attribute);
            }
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child->nodeName === 'QUEST_DESCRIPTION') $node->removeChild($child);
            }

            $event = $events->get((int) $node->getAttribute('quest'));
            if ($event === null) {
                continue;
            }

            $values = [
                'available' => '1',
                'title' => $event->title,
                'description' => $event->description ?? '',
                'minimum' => $event->min_participants,
                'maximum' => $event->max_participants,
                'count' => $event->participants_count,
                'leader' => $event->leader?->display_name ?? 'не назначен',
                'status' => $event->status,
            ];

            foreach ($values as $attribute => $value) {
                $node->setAttribute($attribute, (string) $value);
            }
            foreach (EventReward::data($event) as $key => $value) $node->setAttribute('reward_' . $key, (string) $value);
            $description = $document->createElement('QUEST_DESCRIPTION');
            $fragment = $document->createDocumentFragment();
            $formatted = EventDescription::xml($event->description);
            if ($formatted !== '') {
                $fragment->appendXML($formatted);
                $description->appendChild($fragment);
            }
            $node->appendChild($description);
        }

        return $document->saveXML($document->documentElement);
    }
}

