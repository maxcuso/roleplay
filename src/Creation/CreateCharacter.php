<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Maxcuso\Roleplay\Api\Access;
use Maxcuso\Roleplay\Character\Application\ApplicationTopics;
use Maxcuso\Roleplay\Character\Application\ApplicationConfiguration;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Перенос атомарного создания из старой версии; правила, план и запись выдач разделены. */
final class CreateCharacter
{
    public function __construct(private readonly ConnectionInterface $db, private readonly ApplicationTopics $topics) {}

    public function create(User $actor, mixed $input): array
    {
        Access::create($actor);
        $body = CreationRequest::body($input, ['operation_key', 'draft_id', 'draft_revision', 'catalog_revision', 'rules_hash']);
        $key = CreationRequest::operationKey($body['operation_key'] ?? null);
        $draftId = FieldValues::integer($body['draft_id'] ?? null, 'Черновик', 1, PHP_INT_MAX);
        $draftRevision = CreationRequest::revision($body['draft_revision'] ?? null);
        $catalogRevision = CreationRequest::revision($body['catalog_revision'] ?? null);
        $rulesHash = CreationRequest::rulesHash($body['rules_hash'] ?? null);
        $hash = hash('sha256', json_encode(['create_character', (int) $actor->id, $draftId,
            $draftRevision, $catalogRevision, $rulesHash], JSON_THROW_ON_ERROR));
        try {
            return (new CreationCatalog($this->db))->read(function (array $catalog) use (
                $actor, $key, $draftId, $draftRevision, $catalogRevision, $rulesHash, $hash
            ): array {
                CreationActorLock::acquire($this->db, (int) $actor->id);
                $previous = $this->db->table('roleplay_operations')->where('id', $key)->first();
                if ($previous !== null) {
                    // Повтор того же запроса возвращает прежний результат даже после удаления черновика.
                    if ((int) $previous->actor_user_id !== (int) $actor->id
                        || $previous->type !== 'create_character' || !hash_equals($previous->request_hash, $hash)) {
                        throw new ApiProblem('operation_key_conflict', 'Не удалось создать персонажа: данные повторного запроса отличаются от исходных. Попробуйте обновить страницу', 409);
                    }
                    if ($previous->character_id === null) {
                        throw new ApiProblem('character_deleted', 'Этот персонаж уже удалён. Начните создание заново', 409);
                    }
                    return json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR);
                }
                if (!$catalog['configuration']['enabled']) {
                    throw new ApiProblem('creation_disabled', 'Создание персонажей временно отключено. Попробуйте позже', 403);
                }
                $owned = $this->db->table('roleplay_characters')->where('user_id', $actor->id);
                if ((clone $owned)->where('status', 'creating')->exists()
                    || (clone $owned)->whereIn('status', ['creating', 'active'])->count() >= $catalog['configuration']['max_characters']) {
                    throw new ApiProblem('character_limit_reached', 'Вы уже создали максимально допустимое количество персонажей', 409);
                }
                CreationRequest::current($catalog, $catalogRevision, $rulesHash);
                if ($catalog['configuration']['application']['tag_id'] === null) {
                    throw new ApiProblem('application_not_configured', 'Не удалось создать тему анкеты: тег для анкет не настроен. Обратитесь к администратору', 403);
                }
                $draft = $this->db->table('roleplay_creation_drafts')->where('user_id', $actor->id)->lockForUpdate()->first();
                if ($draft === null || (int) $draft->id !== $draftId || (int) $draft->revision !== $draftRevision
                    || (int) $draft->catalog_revision !== $catalogRevision || !hash_equals($draft->rules_hash, $rulesHash)) {
                    throw new ApiProblem('creation_draft_conflict', 'Черновик изменился. Проверьте актуальные данные перед созданием персонажа', 409);
                }
                $payload = (new CreationPayload())->normalize(json_decode($draft->payload, true, flags: JSON_THROW_ON_ERROR), (int) $actor->id);
                // Сервер заново вычисляет план; preview и выдачи из запроса игрока не принимаются.
                $plan = (new CreationPlanBuilder($catalog['development']))->build($payload, $catalog['definitions'], $catalog['configuration']);
                if (!$plan['valid']) {
                    throw new ApiProblem('creation_incomplete', 'Завершите все обязательные шаги мастера, прежде чем создавать персонажа');
                }
                $now = gmdate('Y-m-d H:i:s');
                $id = (int) $this->db->table('roleplay_characters')->insertGetId([
                    'user_id' => $actor->id, 'race_id' => $payload['race_id'],
                    'first_name' => $payload['first_name'], 'last_name' => $payload['last_name'] === '' ? null : $payload['last_name'],
                    'age' => $payload['age'], 'avatar' => $payload['avatar'], 'creation_catalog_revision' => $catalogRevision,
                    'status' => 'creating', 'literature_approved' => false, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->db->table('roleplay_operations')->insert([
                    'id' => $key, 'type' => 'create_character', 'actor_user_id' => $actor->id, 'character_id' => $id,
                    'request_hash' => $hash, 'created_at' => $now,
                ]);
                (new CreationGrants($this->db))->save($id, $plan, $now);
                // Внутренний API Flarum использует то же подключение и вложенную транзакцию.
                // Ошибка темы откатывает персонажа; повтор успешной команды возвращается до этого места.
                $application = $catalog['configuration']['application'];
                $text = (new ApplicationConfiguration())->render($application, $payload,
                    $catalog['definitions']['races'][$payload['race_id']]['name']);
                $discussionId = $this->topics->create($actor, $application['tag_id'], $text['title'], $text['content']);
                $this->db->table('roleplay_characters')->where('id', $id)->update([
                    // Технический выбор готов; литературный шаблон игрок ещё заполняет в теме.
                    'discussion_id' => $discussionId, 'mechanics_status' => 'review', 'literature_status' => 'draft',
                ]);
                $this->db->table('roleplay_character_events')->insert([
                    'character_id' => $id, 'actor_user_id' => $actor->id, 'operation_id' => $key, 'type' => 'character_created',
                    'payload' => json_encode(['catalog_revision' => $catalogRevision, 'rules_hash' => $rulesHash,
                        'choices' => $payload, 'plan' => $plan, 'discussion_id' => $discussionId], JSON_THROW_ON_ERROR), 'created_at' => $now,
                ]);
                $this->db->table('roleplay_creation_drafts')->where('id', $draftId)->delete();
                $result = ['character_id' => $id];
                $this->db->table('roleplay_operations')->where('id', $key)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR)]);
                return $result;
            });
        } catch (QueryException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new ApiProblem('operation_key_conflict', 'Не удалось обработать повторный запрос. Попробуйте обновить страницу', 409);
            }
            throw $error;
        }
    }
}
