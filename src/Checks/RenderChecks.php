<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Checks;

use DOMDocument;
use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;
use s9e\TextFormatter\Renderer;

/** HTML строится только по сохранённому снимку, без бросков и чтения текущих бонусов. */
final class RenderChecks
{
    public function __construct(private ConnectionInterface $db) {}
    public function __invoke(Renderer $renderer, mixed $context, string $xml, ?ServerRequestInterface $request = null): string
    {
        if (!str_contains($xml, '<RP_CHECK_')) return $xml;
        $actor = $request ? RequestUtil::getActor($request) : null;
        $document = new DOMDocument(); $document->loadXML($xml, LIBXML_NONET); $nodes = []; $ids = [];
        foreach (['request', 'roll'] as $type) foreach ($document->getElementsByTagName('RP_CHECK_'.strtoupper($type)) as $node) {
            $nodes[] = [$type, $node]; $ids[] = $node->getAttribute('check-'.$type);
        }
        $rows = $actor && $actor->can('viewForum') && $actor->can('roleplay.view') ? $this->db->table('roleplay_checks')->whereIn('id', array_unique($ids))
            ->whereIn('post_id', Post::whereVisibleTo($actor)->select('posts.id'))->get()->keyBy('id') : collect();
        foreach ($nodes as [$type, $node]) {
            $id = $node->getAttribute('check-'.$type);
            foreach (iterator_to_array($node->attributes) as $attribute) $node->removeAttributeNode($attribute);
            foreach (iterator_to_array($node->childNodes) as $child) $node->removeChild($child);
            $node->setAttribute('check-'.$type, $id);
            $row = $rows->get($id);
            if (!$row || $row->type !== $type) continue;
            $result = json_decode($row->result, true, flags: JSON_THROW_ON_ERROR);
            $node->setAttribute('available', '1');
            $node->setAttribute('name', $result['name']);
            if ($result['difficulty'] !== null) $node->setAttribute('difficulty', CheckInput::DIFFICULTIES[$result['difficulty']]);
            if ($type === 'request') {
                $node->setAttribute('description', $result['description']);
                $quoted = false;
                for ($parent = $node->parentNode; $parent; $parent = $parent->parentNode) if (in_array($parent->nodeName, ['QUOTE', 'CODE'], true)) $quoted = true;
                $discussion = \Flarum\Discussion\Discussion::whereVisibleTo($actor)->find($row->discussion_id);
                if (!$quoted && $actor->exists && $discussion && $actor->can('reply', $discussion)) $node->setAttribute('can_roll', '1');
                $node->setAttribute('discussion', (string) $row->discussion_id);
            } else {
                $node->setAttribute('outcome', $result['success'] === null ? 'neutral' : ($result['success'] ? 'success' : 'failure'));
                $node->setAttribute('first', (string) $result['dice'][0]); $node->setAttribute('second', (string) $result['dice'][1]);
                $bonus = $result['bonus'] + $result['modifier'];
                $node->setAttribute('bonus', ($bonus < 0 ? '− ' : '+ ').abs($bonus));
                $node->setAttribute('total', (string) $result['total']);
                $node->setAttribute('character', $result['character_name']);
                $node->setAttribute('hint', 'Бонус персонажа: '.$result['bonus'].($result['modifier'] ? '. '.$result['reason'].': '.($result['modifier'] > 0 ? '+' : '').$result['modifier'] : ''));
            }
        }
        return $document->saveXML($document->documentElement);
    }
}
