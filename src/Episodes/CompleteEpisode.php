<?php

declare(strict_types=1);
namespace Maxcuso\Roleplay\Episodes;

use Flarum\Api\Client;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Character\Moderation\PerkGrantSource;
use Maxcuso\Roleplay\Character\Operations\{ActorOperationLock, CharacterOperation};
use Maxcuso\Roleplay\Character\Profile\CharacterVisibility;
use Maxcuso\Roleplay\Character\State\{CharacterBalances, CharacterGrants};
use Maxcuso\Roleplay\Creation\CreationCatalog;

/** Выполняется только из сохранения нового поста внутри общей транзакции. */
final class CompleteEpisode
{
    public function __construct(private ConnectionInterface $db, private Client $api) {}

    public function publish(string $planId, CommentPost $post, User $actor): void
    {
        if ($this->db->transactionLevel() === 0) throw new \LogicException('Episode completion requires a post transaction');
        (new CreationCatalog($this->db))->read(function (array $catalog) use ($planId, $post, $actor): void {
            ActorOperationLock::acquire($this->db, (int) $actor->id);
            $plan = $this->db->table('roleplay_episode_plans')->where('id', $planId)->first();
            if (!$plan || (int) $plan->actor_user_id !== (int) $actor->id || (int) $plan->discussion_id !== (int) $post->discussion_id) {
                throw new ApiProblem('completion_forbidden', 'Откройте выдачу наград в нужной теме и вставьте код заново', 403);
            }
            $context = CompletionAccess::context((int) $post->discussion_id, $actor, true);
            $event = $context['event'];
            $eventId = $event ? (int) $event->id : null;
            $title = $event?->title ?? $context['discussion']->title;
            if ($this->db->table('roleplay_episode_completions')->where('plan_id', $planId)->exists()
                || ($event && $this->db->table('roleplay_episode_completions')->where('event_id', $eventId)->exists())) {
                throw new ApiProblem('episode_completed', 'Эта награда уже выдана', 409);
            }
            $payload = CompletionInput::payload(json_decode($plan->payload, true, flags: JSON_THROW_ON_ERROR));
            if ($eventId !== ($plan->event_id === null ? null : (int) $plan->event_id)
                || $payload['event_id'] !== $eventId || !hash_equals($context['revision'], $payload['revision'])) {
                throw new ApiProblem('episode_revision_conflict', 'Условия темы изменились. Откройте выдачу наград и вставьте новый код', 409);
            }
            if ($post->hidden_at !== null || $post->getAttribute('is_approved') === false || $post->getAttribute('is_approved') === 0) {
                throw new ApiProblem('completion_post_hidden', 'Опубликуйте завершение сообщением, доступным участникам');
            }
            $receipts = [];
            // CompletionInput отсортировал ID: все получатели блокируются в одном порядке.
            foreach ($payload['recipients'] as $recipient) {
                $id = $recipient['character_id'];
                $character = $this->db->table('roleplay_characters')->where('id', $id)->lockForUpdate()->first();
                if (!$character || $character->status !== 'active') throw new ApiProblem('recipient_unavailable', 'Один из получателей больше не активен. Обновите настройки наград', 409);
                (new CharacterVisibility())->assertVisible((array) $character, $actor);
                $bonuses = $event ? (new RewardExperience($this->db))->bonuses($id) : [];
                $experience = RewardExperience::calculate($recipient['experience'], $bonuses);
                $receipt = [];
                $operation = ['operation_key' => (string) Str::uuid(), 'revision' => (int) $character->revision,
                    'catalog_revision' => $catalog['revision'], 'plan_id' => $planId, 'recipient' => $recipient];
                (new CharacterOperation($this->db))->run($id, $actor, $event ? 'episode_completion' : 'topic_reward', $operation,
                    fn (array $row) => (new CharacterVisibility())->assertVisible($row, $actor),
                    function (array $row) use ($recipient, $experience, $bonuses, $catalog, $event, $title, $post, &$receipt): array {
                        $awards = $this->award($row, $recipient, $catalog['definitions'], $title, $event !== null);
                        $balances = new CharacterBalances($this->db); $changes = [];
                        if ($experience > 0) $changes += $balances->adjust((int) $row['id'], 'experience_active', $experience);
                        if ($recipient['gold'] > 0) $changes += $balances->adjust((int) $row['id'], 'gold', $recipient['gold']);
                        $receipt = ['character_id' => (int) $row['id'], 'name' => trim($row['first_name'].' '.$row['last_name']),
                            'experience' => $experience, 'base_experience' => $recipient['experience'], 'bonuses' => $bonuses,
                            'gold' => $recipient['gold']] + $awards;
                        return ['fields' => [], 'event' => 'character_managed', 'payload' => $receipt + [
                            'action' => $event ? 'episode_reward' : 'topic_reward', 'episode_id' => $event ? (int) $event->id : null, 'post_id' => (int) $post->id,
                            'comment' => ($event ? 'Эпизод' : 'Тема').' «'.$title.'»', 'episode_title' => $title, 'balances' => $changes]];
                    });
                $receipts[] = $receipt;
            }
            $this->db->table('roleplay_episode_completions')->insert(['event_id' => $eventId,
                'plan_id' => $planId, 'post_id' => $post->id, 'actor_user_id' => $actor->id,
                'result' => json_encode(['is_quest' => $event !== null, 'quest_title' => $event?->title, 'recipients' => $receipts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => gmdate('Y-m-d H:i:s')]);
            if (!$event) return;
            $event->status = 'completed'; $event->scheduled_start_at = null; $event->save();
            // Тот же штатный API закрытия, что кнопка Flarum. Любая ошибка откатывает пост и все выдачи.
            $response = $this->api->withActor($actor)->withBody(['data' => ['type' => 'discussions',
                'id' => (string) $post->discussion_id, 'attributes' => ['isLocked' => true]]])
                ->send('PATCH', '/discussions/'.$post->discussion_id);
            if ($response->getStatusCode() !== 200) throw new ApiProblem('episode_lock_failed', 'Не удалось закрыть тему. Награды не начислены, повторите публикацию', 409);
        });
    }

    private function award(array $character, array $recipient, array $definitions, string $title, bool $quest): array
    {
        $id = (int) $character['id']; $grants = new CharacterGrants($this->db);
        $given = []; $skipped = [];
        foreach ($recipient['items'] as $item) {
            $definition = $this->definition($definitions, 'items', $item['id']);
            $grants->items($id, $item['id'], $item['quantity'], (int) $definition['max_stack']);
            $given[] = ['kind' => 'item', 'id' => $item['id'], 'name' => $definition['name'], 'quantity' => $item['quantity']];
        }
        foreach (['perk' => 'perks', 'spell' => 'spells'] as $kind => $key) {
            foreach ($recipient[$key] as $target) {
                if ($this->db->table('roleplay_character_'.$key)->where('character_id', $id)->where($kind.'_id', $target)->exists()) {
                    $skipped[] = ['kind' => $kind, 'id' => $target, 'reason' => 'already_owned']; continue;
                }
                $definition = $this->definition($definitions, $key, $target);
                if ($kind === 'perk') {
                    $source = null;
                    foreach ($definitions['sources'] as $candidate) {
                        if ($candidate['enabled'] && $candidate['name_source'] === ($quest ? 'quest' : 'event')) {$source = $candidate; break;}
                    }
                    if (!$source) throw new ApiProblem('quest_source_missing', 'Настройте доступный источник перков «'.($quest ? 'Квест' : 'Событие').'» в справочнике', 409);
                    $name = mb_substr($title, 0, 100);
                    $context = (new PerkGrantSource())->context($source, ['quest_name' => $name, 'event_name' => $name, 'race_id' => $character['race_id']], $definitions);
                    $grants->perk($id, $target, $source['id'], $context);
                } else {
                    $this->definition($definitions, 'paths', $definition['magic_path_id']);
                    $grants->spell($id, $target);
                }
                $given[] = ['kind' => $kind, 'id' => $target, 'name' => $definition['name'], 'quantity' => 1,
                    'level' => $kind === 'spell' ? $definition['level'] : null];
            }
        }
        return ['grants' => $given, 'skipped' => $skipped];
    }

    private function definition(array $definitions, string $key, int $id): array
    {
        $row = $definitions[$key][$id] ?? null;
        if (!$row || !$row['enabled']) throw new ApiProblem('reward_unavailable', 'Одна из наград удалена или отключена. Обновите выбор', 409);
        return $row;
    }
}
