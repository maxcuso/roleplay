<?php

declare(strict_types=1);
namespace Maxcuso\Roleplay\Episodes;

use DOMDocument;
use Flarum\Http\RequestUtil;
use Flarum\Http\{SlugManager, UrlGenerator};
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;
use s9e\TextFormatter\Renderer;
use Maxcuso\Roleplay\Character\Profile\CharacterVisibility;
use Maxcuso\Roleplay\Presentation\CharacterAvatar;

/** Отображает сохранённый факт. Никогда не исполняет награды при просмотре. */
final class RenderCompletion
{
    public function __construct(private ConnectionInterface $db) {}

    public function __invoke(Renderer $renderer, mixed $context, string $xml, ?ServerRequestInterface $request = null): string
    {
        if (!str_contains($xml, '<RP_EPISODE_END')) return $xml;
        $document = new DOMDocument(); $document->loadXML($xml, LIBXML_NONET);
        $nodes = $document->getElementsByTagName('RP_EPISODE_END'); $ids = [];
        foreach ($nodes as $node) $ids[] = $node->getAttribute('episode-end');
        $actor = $request ? RequestUtil::getActor($request) : null;
        $rows = $actor && $actor->can('viewForum') && $actor->can('roleplay.view')
            ? $this->db->table('roleplay_episode_completions')->whereIn('plan_id', array_unique($ids))
                ->whereIn('post_id', Post::whereVisibleTo($actor)->select('posts.id'))->get()->keyBy('plan_id') : collect();
        $results = $rows->map(fn ($row) => json_decode($row->result, true, flags: JSON_THROW_ON_ERROR));
        $characterIds = $results->flatMap(fn ($result) => array_column($result['recipients'], 'character_id'))->unique()->all();
        // Аватар берём из актуальной анкеты, в том числе для плашек, опубликованных до этой правки.
        $characters = $actor && $characterIds ? (new CharacterVisibility())->apply($this->db->table('roleplay_characters'), $actor)
            ->whereIn('id', $characterIds)->get(['id', 'user_id', 'avatar'])->keyBy('id') : collect();
        $owners = User::whereIn('id', $characters->pluck('user_id')->unique()->all())->get()->keyBy('id');
        $profiles = [];
        $slugs = resolve(SlugManager::class)->forResource(User::class);
        foreach ($characters as $character) {
            if ($owner = $owners->get($character->user_id)) {
                $profiles[$character->id] = resolve(UrlGenerator::class)->to('forum')
                    ->path('u/'.rawurlencode($slugs->toSlug($owner)).'/roleplay').'?character='.$character->id;
            }
        }
        $titles = $this->db->table('quest_events')->whereIn('id', $rows->pluck('event_id')->filter()->all())->pluck('title', 'id');
        foreach ($nodes as $node) {
            $id = $node->getAttribute('episode-end');
            foreach (iterator_to_array($node->attributes) as $attribute) $node->removeAttributeNode($attribute);
            $node->setAttribute('episode-end', $id);
            foreach (iterator_to_array($node->childNodes) as $child) $node->removeChild($child);
            $row = $rows->get($id);
            if (!$row) continue;
            $node->setAttribute('available', '1');
            $result = $results->get($id);
            // Название карточки квеста не содержит служебного оформления заголовка темы.
            $title = $result['quest_title'] ?? $titles->get($row->event_id);
            if ($title && ($result['is_quest'] ?? $row->event_id !== null)) $node->setAttribute('quest_title', $title);
            foreach ($result['recipients'] as $recipient) {
                $person = $document->createElement('RP_RECIPIENT');
                foreach (['name', 'experience', 'base_experience', 'gold'] as $field) $person->setAttribute($field, (string) $recipient[$field]);
                $person->setAttribute('initial', mb_strtoupper(mb_substr($recipient['name'], 0, 1)));
                $character = $characters->get($recipient['character_id']);
                $avatar = CharacterAvatar::url($character?->avatar);
                if ($avatar) $person->setAttribute('avatar', $avatar);
                if (isset($profiles[$recipient['character_id']])) $person->setAttribute('profile', $profiles[$recipient['character_id']]);
                $person->setAttribute('experience_hint', RewardExperience::tooltip((int) $recipient['base_experience'], $recipient['bonuses']));
                $person->setAttribute('gold_hint', $recipient['gold'].' золота');
                // skipped сохраняется в журнале, но не попадает в опубликованную плашку.
                foreach ($recipient['grants'] as $grant) {
                    $child = $document->createElement('RP_GRANT');
                    foreach (['kind', 'name', 'quantity'] as $field) $child->setAttribute($field, (string) $grant[$field]);
                    if ($grant['kind'] === 'spell') $child->setAttribute('level', [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V'][$grant['level']] ?? (string) $grant['level']);
                    $person->appendChild($child);
                }
                $node->appendChild($person);
            }
        }
        return $document->saveXML($document->documentElement);
    }
}
