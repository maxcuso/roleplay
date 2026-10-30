<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Equipment;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Maxcuso\Roleplay\Api\Access;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Api\OperationRequest;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRevision;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Character\Operations\ActorOperationLock;
use Maxcuso\Roleplay\Character\Profile\CharacterVisibility;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;

/** Только операция имущества. Сила атаки, эффекты и вес вычисляются общим калькулятором. */
final class CharacterEquipment
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function change(int $characterId, User $actor, mixed $input): array
    {
        Access::view($actor);
        if (!$actor->exists) throw new ApiProblem('equipment_forbidden', 'Вы можете изменять экипировку только своего персонажа', 403);
        $body = OperationRequest::body($input, ['operation_key', 'revision', 'catalog_revision', 'instance_id', 'equipped']);
        $key = OperationRequest::key($body['operation_key'] ?? null);
        $revision = OperationRequest::revision($body['revision'] ?? null);
        $catalogRevision = OperationRequest::revision($body['catalog_revision'] ?? null);
        $instanceId = FieldValues::integer($body['instance_id'] ?? null, 'Экземпляр предмета', 1, 4294967295);
        if (!is_bool($body['equipped'] ?? null)) throw new ApiProblem('invalid_request', 'Не удалось определить состояние экипировки. Попробуйте обновить страницу', 400);
        $equipped = $body['equipped'];
        $hash = hash('sha256', json_encode(['equipment', (int) $actor->id, $characterId, $revision,
            $catalogRevision, $instanceId, $equipped], JSON_THROW_ON_ERROR));

        try {
            return (new CatalogRevision($this->db))->read(function (int $currentCatalog) use (
                $characterId, $actor, $key, $revision, $catalogRevision, $instanceId, $equipped, $hash
            ): array {
                // Тот же порядок, что у создания: справочники -> аккаунт -> персонаж.
                ActorOperationLock::acquire($this->db, (int) $actor->id);
                $character = $this->db->table('roleplay_characters')->where('id', $characterId)->lockForUpdate()->first();
                if ($character === null || (int) $character->user_id !== (int) $actor->id) {
                    throw new ApiProblem('character_not_found', 'Персонаж не найден. Попробуйте обновить страницу', 404);
                }
                $previous = $this->db->table('roleplay_operations')->where('id', $key)->first();
                if ($previous !== null) {
                    if ((int) $previous->actor_user_id !== (int) $actor->id || $previous->type !== 'equipment'
                        || !hash_equals($previous->request_hash, $hash)) {
                        throw new ApiProblem('operation_key_conflict', 'Не удалось выполнить действие: данные повторного запроса отличаются от исходных. Попробуйте обновить страницу', 409);
                    }
                    return json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR);
                }
                if (!(new CharacterVisibility())->canEquip((array) $character, $actor)) {
                    throw new ApiProblem('equipment_forbidden', 'Экипировку можно менять только у активного персонажа', 403);
                }
                if ((int) $character->revision !== $revision || $currentCatalog !== $catalogRevision) {
                    throw new ApiProblem('equipment_revision_conflict', 'Данные анкеты или справочников изменились. Попробуйте обновить страницу', 409);
                }
                $instance = $this->db->table('roleplay_character_items')->where('character_id', $characterId)
                    ->where('id', $instanceId)->lockForUpdate()->first();
                if ($instance === null) throw new ApiProblem('item_not_found', 'Предмет не найден в инвентаре. Попробуйте обновить страницу', 404);
                if ((bool) $instance->equipped === $equipped) {
                    throw new ApiProblem('equipment_unchanged', 'Состояние предмета уже изменилось. Попробуйте обновить страницу', 409);
                }
                $registry = new CatalogRegistry($this->db);
                $definition = $registry->definitionsFor('items', [(int) $instance->item_id])[0];
                $type = $registry->definitionsFor('item-types', [$definition['item_type_id']])[0];
                if ($equipped) $this->checkLimit($characterId, $type);

                $now = gmdate('Y-m-d H:i:s');
                $equippedId = $instanceId;
                if ($equipped && (int) $instance->quantity > 1) {
                    // Один предмет выделяется из стопки. Общий вес и количество сохраняются.
                    $this->db->table('roleplay_character_items')->where('id', $instanceId)
                        ->update(['quantity' => (int) $instance->quantity - 1]);
                    $equippedId = (int) $this->db->table('roleplay_character_items')->insertGetId([
                        'character_id' => $characterId, 'item_id' => $instance->item_id, 'quantity' => 1,
                        'equipped' => true, 'is_starting' => (bool) $instance->is_starting,
                    ]);
                } else {
                    $this->db->table('roleplay_character_items')->where('id', $instanceId)
                        ->update(['equipped' => $equipped]);
                }
                $this->db->table('roleplay_characters')->where('id', $characterId)
                    ->update(['revision' => $revision + 1, 'updated_at' => $now]);
                // Некорректное состояние/расчёт откатывает всё действие, включая выделение из стопки.
                (new CharacterProfile($this->db))->read($characterId, $actor);
                $result = ['character_id' => $characterId, 'instance_id' => $equippedId, 'revision' => $revision + 1];
                $this->db->table('roleplay_operations')->insert([
                    'id' => $key, 'type' => 'equipment', 'actor_user_id' => $actor->id, 'character_id' => $characterId,
                    'request_hash' => $hash, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => $now,
                ]);
                $this->db->table('roleplay_character_events')->insert([
                    'character_id' => $characterId, 'actor_user_id' => $actor->id, 'operation_id' => $key,
                    'type' => $equipped ? 'item_equipped' : 'item_unequipped',
                    'payload' => json_encode(['original_instance_id' => $instanceId, 'instance_id' => $equippedId,
                        'item_id' => (int) $instance->item_id, 'catalog_revision' => $currentCatalog], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                ]);
                return $result;
            });
        } catch (QueryException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new ApiProblem('operation_key_conflict', 'Не удалось обработать повторное действие. Попробуйте обновить страницу', 409);
            }
            throw $error;
        }
    }

    private function checkLimit(int $characterId, array $type): void
    {
        $limit = $type['equipment_limit'];
        if ($limit === -1) throw new ApiProblem('item_not_equippable', 'Предметы этого типа нельзя экипировать', 422);
        if ($limit === 0) return;
        $count = $this->db->table('roleplay_character_items as inventory')->join('roleplay_items as definition',
            'definition.id', '=', 'inventory.item_id')->where('inventory.character_id', $characterId)
            ->where('inventory.equipped', true)->where('definition.item_type_id', $type['id'])->count();
        if ($count >= $limit) throw new ApiProblem('equipment_limit_reached', 'Достигнут лимит экипировки этого типа. Снимите другой предмет этого типа', 422);
    }
}