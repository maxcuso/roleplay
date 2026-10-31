<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Operations;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Maxcuso\Roleplay\Api\Access;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Api\OperationRequest;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRevision;

/** Общая оболочка проверки/исправления: права, версии, мьютекс, повтор и журнал. Правила — в callback. */
final class CharacterOperation
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly \Maxcuso\Roleplay\Notification\CharacterNotifications $notifications,
    ) {}

    public function run(int $id, User $actor, string $type, array $body, callable $authorize, callable $change): array
    {
        Access::view($actor);
        if (!$actor->exists) throw new ApiProblem('operation_forbidden', 'Войдите в аккаунт, чтобы продолжить', 403);
        $key = OperationRequest::key($body['operation_key'] ?? null);
        $expected = OperationRequest::revision($body['revision'] ?? null);
        $expectedCatalog = OperationRequest::revision($body['catalog_revision'] ?? null);
        // Порядок JSON-полей не меняет смысл команды.
        $canonicalBody = $body;
        ksort($canonicalBody);
        $hash = hash('sha256', json_encode([$type, (int) $actor->id, $id, $canonicalBody], JSON_THROW_ON_ERROR));
        try {
            return (new CatalogRevision($this->db))->read(function (int $catalog) use (
                $id, $actor, $type, $authorize, $change, $key, $expected, $expectedCatalog, $hash
            ): array {
                ActorOperationLock::acquire($this->db, (int) $actor->id);
                $character = $this->db->table('roleplay_characters')->where('id', $id)->lockForUpdate()->first();
                if (!$character) throw new ApiProblem('character_not_found', 'Персонаж не найден. Попробуйте обновить страницу', 404);
                // Права проверяются и для повтора. Переход состояния проверяется только у новой команды.
                $authorize((array) $character);
                $previous = $this->db->table('roleplay_operations')->where('id', $key)->first();
                if ($previous) {
                    if ((int) $previous->actor_user_id !== (int) $actor->id || $previous->type !== $type
                        || !hash_equals($previous->request_hash, $hash)) {
                        throw new ApiProblem('operation_key_conflict', 'Не удалось выполнить действие: данные повторного запроса отличаются от исходных. Попробуйте обновить страницу', 409);
                    }
                    return json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR);
                }
                if ((int) $character->revision !== $expected || $catalog !== $expectedCatalog) {
                    throw new ApiProblem('character_revision_conflict', 'Данные анкеты или настроек изменились. Попробуйте обновить страницу', 409);
                }
                $changed = $change((array) $character);
                $now = gmdate('Y-m-d H:i:s');
                $this->db->table('roleplay_characters')->where('id', $id)->update(
                    $changed['fields'] + ['revision' => $expected + 1, 'updated_at' => $now]);
                $result = ['character_id' => $id, 'revision' => $expected + 1];
                $this->db->table('roleplay_operations')->insert([
                    'id' => $key, 'type' => $type, 'actor_user_id' => $actor->id, 'character_id' => $id,
                    'request_hash' => $hash, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => $now,
                ]);
                $eventId = $this->db->table('roleplay_character_events')->insertGetId([
                    'character_id' => $id, 'actor_user_id' => $actor->id, 'operation_id' => $key,
                    'type' => $changed['event'], 'payload' => json_encode($changed['payload'], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                ]);
                $this->notifications->afterEvent($this->db, (array) $character,
                    $changed['event'], $changed['payload'], $eventId, (int) $actor->id);
                return $result;
            });
        } catch (QueryException $error) {
            // Другой аккаунт мог одновременно занять тот же UUID. Уникальный ключ откатывает все изменения.
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new ApiProblem('operation_key_conflict', 'Этот запрос уже обработан. Попробуйте обновить страницу', 409);
            }
            throw $error;
        }
    }
}
