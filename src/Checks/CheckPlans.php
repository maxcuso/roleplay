<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Checks;

use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\{ApiProblem, OperationRequest};
use Maxcuso\Roleplay\Character\Operations\ActorOperationLock;
use Maxcuso\Roleplay\Creation\CreationCatalog;

/** Настройка не бросает кубики. Снимок возникает один раз в транзакции публикации. */
final class CheckPlans
{
    public function __construct(private ConnectionInterface $db, private CheckRead $reader) {}

    public function prepare(User $actor, array $body): array
    {
        $body = OperationRequest::body($body, ['operation_key', 'discussion_id', 'type', 'payload']);
        $id = OperationRequest::key($body['operation_key'] ?? null);
        $discussionId = ($body['discussion_id'] ?? null) === null ? null : \Maxcuso\Roleplay\Api\CatalogRequest::positive($body['discussion_id'], 'Тема');
        $type = $body['type'] ?? '';
        $payload = CheckInput::payload(is_string($type) ? $type : '', $body['payload'] ?? null);
        CheckAccess::discussion($discussionId, $actor);
        return (new CreationCatalog($this->db))->read(function () use ($actor, $id, $discussionId, $type, $payload): array {
            ActorOperationLock::acquire($this->db, (int) $actor->id);
            $reader = $this->reader;
            $reader->definition($payload);
            if ($type === 'request') $reader->validateRecipients($payload, $actor);
            else $this->rollSnapshot($payload, $discussionId, $actor);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $previous = $this->db->table('roleplay_checks')->where('id', $id)->first();
            if ($previous) {
                if ((int) $previous->actor_user_id !== (int) $actor->id || $previous->type !== $type
                    || ($previous->discussion_id === null ? null : (int) $previous->discussion_id) !== $discussionId || !hash_equals($previous->payload, $json)) {
                    throw new ApiProblem('check_key_conflict', 'Настройки проверки изменились. Откройте окно и вставьте новый код', 409);
                }
            } else {
                $this->db->table('roleplay_checks')->insert(['id' => $id, 'type' => $type, 'actor_user_id' => $actor->id,
                    'discussion_id' => $discussionId, 'payload' => $json, 'created_at' => gmdate('Y-m-d H:i:s')]);
            }
            return ['code' => '[check-'.$type.'='.$id.']'];
        });
    }

    public function publish(string $id, string $type, CommentPost $post, User $actor): void
    {
        if ($this->db->transactionLevel() === 0) throw new \LogicException('Checks require a post transaction');
        (new CreationCatalog($this->db))->read(function () use ($id, $type, $post, $actor): void {
            ActorOperationLock::acquire($this->db, (int) $actor->id);
            $plan = $this->db->table('roleplay_checks')->where('id', $id)->lockForUpdate()->first();
            if (!$plan || $plan->type !== $type || (int) $plan->actor_user_id !== (int) $actor->id
                || ($plan->discussion_id !== null && (int) $plan->discussion_id !== (int) $post->discussion_id)) {
                throw new ApiProblem('check_forbidden', 'Откройте проверку в нужной теме и вставьте код заново', 403);
            }
            if ($plan->post_id !== null) return;
            CheckAccess::discussion((int) $post->discussion_id, $actor);
            if ($post->hidden_at !== null || in_array($post->getAttribute('is_approved'), [false, 0], true)) {
                throw new ApiProblem('check_hidden_post', 'Опубликуйте проверку сообщением, доступным участникам');
            }
            $payload = CheckInput::payload($type, json_decode($plan->payload, true, flags: JSON_THROW_ON_ERROR));
            $reader = $this->reader;
            $definition = $reader->definition($payload);
            if ($type === 'request') {
                $reader->validateRecipients($payload, $actor);
                $result = $payload + ['name' => $definition['name']];
            } else {
                // CharacterStateReader блокирует заголовок и читает согласованную версию механики персонажа.
                $snapshot = $this->rollSnapshot($payload, (int) $post->discussion_id, $actor);
                // Два независимых d6 генерирует штатный MagicDice. Обычные posts.dice_rolls не затрагиваются.
                $dice = array_map('intval', resolve(\forumaker\MagicDice\Support\DiceRoller::class)->roll("1d6\n1d6"));
                $total = array_sum($dice) + $snapshot['bonus'] + $payload['modifier'];
                $result = $payload + $snapshot + ['dice' => $dice, 'total' => $total,
                    'success' => $payload['difficulty'] === null ? null : $total >= $payload['difficulty']];
            }
            $this->db->table('roleplay_checks')->where('id', $id)->whereNull('post_id')->update([
                'discussion_id' => $post->discussion_id, 'post_id' => $post->id,
                'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        });
    }

    private function rollSnapshot(array $payload, ?int $discussionId, User $actor): array
    {
        $character = $this->reader->character($payload['character_id'], $actor, $discussionId, $payload['request_id']);
        $section = $payload['kind'] === 'skill' ? 'skills' : 'attributes';
        $selected = null;
        foreach ($character[$section] as $row) if ($row['id'] === $payload['target_id']) {$selected = $row; break;}
        if (!$selected) throw new ApiProblem('check_definition', 'Обновите данные и выберите доступную проверку', 409);
        $attribute = $selected;
        if ($payload['kind'] === 'skill') {
            foreach ($character['attributes'] as $row) if ($row['id'] === $selected['attribute_id']) {$attribute = $row; break;}
        }
        return ['name' => $selected['name'], 'character_name' => $character['name'], 'character_revision' => $character['revision'],
            'bonus' => $selected['bonus'], 'level' => $selected['level'] ?? null, 'extra' => $selected['extra'],
            'attribute_name' => $attribute['name'], 'attribute_value' => $attribute['value']];
    }
}
