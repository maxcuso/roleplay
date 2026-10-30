<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\Access;
use Maxcuso\Roleplay\Api\ApiProblem;

/** Перенос сохранения из DraftController: HTTP и игровые проверки вынесены из репозитория. */
final class CreationDraft
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function read(User $actor): array
    {
        Access::create($actor);
        return (new CreationCatalog($this->db))->read(function (array $catalog) use ($actor): array {
            $draft = $this->db->table('roleplay_creation_drafts')->where('user_id', $actor->id)->sharedLock()->first();
            return $this->result($actor, $draft, $catalog);
        });
    }

    public function save(User $actor, mixed $input): array
    {
        Access::create($actor);
        $body = CreationRequest::body($input, ['revision', 'catalog_revision', 'rules_hash', 'step', 'payload']);
        $expected = CreationRequest::revision($body['revision'] ?? null, true);
        $catalogRevision = CreationRequest::revision($body['catalog_revision'] ?? null);
        $hash = CreationRequest::rulesHash($body['rules_hash'] ?? null);
        $step = $body['step'] ?? null;
        if (!is_string($step) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $step)) {
            throw new ApiProblem('invalid_request', 'Не удалось определить шаг создания персонажа. Попробуйте обновить страницу', 400);
        }
        $payload = (new CreationPayload())->normalize($body['payload'] ?? null, (int) $actor->id);
        return (new CreationCatalog($this->db))->read(function (array $catalog) use ($actor, $expected, $catalogRevision, $hash, $step, $payload): array {
            // Единый порядок: справочники, аккаунт, затем черновик/персонаж.
            CreationActorLock::acquire($this->db, (int) $actor->id);
            CreationRequest::current($catalog, $catalogRevision, $hash);
            if ($this->db->table('roleplay_characters')->where('user_id', $actor->id)->where('status', 'creating')->exists()) {
                throw new ApiProblem('character_already_creating', 'Сначала завершите создание предыдущего персонажа', 409);
            }
            $draft = $this->db->table('roleplay_creation_drafts')->where('user_id', $actor->id)->lockForUpdate()->first();
            if ((int) ($draft->revision ?? 0) !== $expected) {
                throw new ApiProblem('creation_draft_conflict', 'Черновик изменён в другом окне. Попробуйте обновить страницу', 409);
            }
            $now = gmdate('Y-m-d H:i:s');
            $values = ['payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'payload_version' => 1,
                'current_step' => $step, 'revision' => $expected + 1, 'catalog_revision' => $catalogRevision,
                'rules_hash' => $hash, 'updated_at' => $now];
            if ($draft === null) {
                $id = $this->db->table('roleplay_creation_drafts')->insertGetId($values + [
                    'user_id' => $actor->id, 'created_at' => $now,
                ]);
            } else {
                $id = $draft->id;
                $this->db->table('roleplay_creation_drafts')->where('id', $id)->update($values);
            }
            return $this->result($actor, $this->db->table('roleplay_creation_drafts')->find($id), $catalog);
        });
    }

    private function result(User $actor, ?object $draft, array $catalog): array
    {
        $payload = (new CreationPayload())->normalize($draft === null ? [] : json_decode($draft->payload, true, flags: JSON_THROW_ON_ERROR));
        $plan = (new CreationPlanBuilder($catalog['development']))->build($payload, $catalog['definitions'], $catalog['configuration']);
        $plan += (new CreationOptions($catalog['development']))->build($payload, $catalog['definitions'], $plan);
        $plan['review'] = (new CreationReview($catalog['development']))->build($payload, $catalog['definitions'], $plan, $catalog['revision']);
        return [
            'catalog' => CreationCatalog::visible($catalog),
            'draft' => ['id' => (int) ($draft->id ?? 0), 'revision' => (int) ($draft->revision ?? 0),
                'catalog_revision' => (int) ($draft->catalog_revision ?? $catalog['revision']),
                'rules_hash' => $draft->rules_hash ?? $catalog['rules_hash'],
                'step' => $draft->current_step ?? 'basics', 'payload' => $payload],
            'existing_character_id' => $this->db->table('roleplay_characters')->where('user_id', $actor->id)
                ->where('status', 'creating')->value('id'),
            'preview' => $plan,
        ];
    }
}
