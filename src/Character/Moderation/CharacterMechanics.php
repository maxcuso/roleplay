<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Character\Moderation;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\Access;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Api\OperationRequest;
use Maxcuso\Roleplay\Character\Operations\CharacterOperation;
use Maxcuso\Roleplay\Character\State\CharacterSnapshotBuilder;
use Maxcuso\Roleplay\Character\State\CharacterStateReader;
use Maxcuso\Roleplay\Rules\DevelopmentRules;
use Maxcuso\Roleplay\Character\Calculation\CharacterCalculationBuilder;
use Maxcuso\Roleplay\Creation\{CreationCatalog, CreationPayload, CreationPlanBuilder, CreationOptions, CreationReview, CreationRequest};

/** Полная правка стартового выбора через тот же план, что при создании.
 * Предпросмотр ничего не записывает. Сохранение атомарно заменяет стартовые
 * выдачи существующего персонажа, сохраняя награды, балансы и литературную тему.
 */
final class CharacterMechanics
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function read(int $id, User $actor): array
    {
        Access::view($actor);
        return (new CreationCatalog($this->db))->read(function (array $catalog) use ($id, $actor): array {
            $character = $this->character($id, $actor);
            $event = $this->db->table('roleplay_character_events')->where('character_id', $id)
                ->whereIn('type', ['character_created', 'mechanics_updated'])->orderByDesc('id')->first();
            if (!$event) throw new ApiProblem('creation_choices_missing', 'Не найден сохранённый стартовый выбор.', 409);
            $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR)['choices'];
            // Старое исправление имени/возраста не меняло событие стартового выбора.
            foreach (['first_name', 'last_name', 'age'] as $key) $payload[$key] = $character[$key] ?? ($key === 'age' ? null : '');
            return $this->view($character, $catalog, (new CreationPayload())->normalize($payload), 'basics', 0);
        });
    }

    public function preview(int $id, User $actor, mixed $input): array
    {
        Access::view($actor);
        $body = OperationRequest::body($input, ['revision', 'character_revision', 'catalog_revision', 'rules_hash', 'step', 'payload']);
        $revision = OperationRequest::revision($body['revision'] ?? null, true);
        $characterRevision = OperationRequest::revision($body['character_revision'] ?? null);
        $step = $body['step'] ?? null;
        if (!in_array($step, ['basics', 'attributes', 'training', 'perks', 'spells', 'loadout', 'review'], true)) {
            throw new ApiProblem('invalid_request', 'Неизвестный шаг редактора.', 400);
        }
        $payload = (new CreationPayload())->normalize($body['payload'] ?? null);
        return (new CreationCatalog($this->db))->read(function (array $catalog) use ($id, $actor, $body, $revision, $characterRevision, $step, $payload): array {
            $character = $this->character($id, $actor);
            CreationRequest::current($catalog, OperationRequest::revision($body['catalog_revision'] ?? null),
                CreationRequest::rulesHash($body['rules_hash'] ?? null));
            if ((int) $character['revision'] !== $characterRevision) {
                throw new ApiProblem('character_revision_conflict', 'Анкета изменена в другом окне. Открой редактор заново.', 409);
            }
            return $this->view($character, $catalog, $payload, $step, $revision + 1);
        });
    }

    public function save(int $id, User $actor, mixed $input): array
    {
        $body = OperationRequest::body($input, ['operation_key', 'revision', 'catalog_revision', 'rules_hash', 'payload']);
        $payload = (new CreationPayload())->normalize($body['payload'] ?? null);
        $hash = CreationRequest::rulesHash($body['rules_hash'] ?? null);
        return (new CharacterOperation($this->db))->run($id, $actor, 'mechanics_edit', $body,
            function (array $character) use ($actor): void {
                // Проверяем владельца и на повторе; проверку состояния — только у новой команды.
                if (!ReviewPermissions::owner($character, $actor)) throw new ApiProblem('character_not_found', 'Персонаж не найден.', 404);
            },
            function (array $character) use ($id, $actor, $body, $hash, $payload): array {
                $this->assertEditable($character, $actor);
                return (new CreationCatalog($this->db))->read(function (array $catalog) use ($id, $body, $hash, $payload, $character): array {
                    CreationRequest::current($catalog, OperationRequest::revision($body['catalog_revision']), $hash);
                    $plan = (new CreationPlanBuilder())->build($payload, $catalog['definitions'], $catalog['configuration']);
                    if (!$plan['valid']) throw new ApiProblem('creation_incomplete', 'Заверши все обязательные шаги редактора.');
                    (new StartingGrants($this->db))->replace($id, $plan);
                    $fields = array_intersect_key($payload, array_flip(['first_name', 'last_name', 'age', 'race_id']));
                    $fields['mechanics_status'] = 'review';
                    $fields['creation_catalog_revision'] = $catalog['revision'];
                    $this->db->table('roleplay_characters')->where('id', $id)->update($fields);
                    // До фиксации строим настоящий снимок: ошибка расчёта откатывает замену.
                    $snapshot = (new CharacterSnapshotBuilder(new CharacterStateReader($this->db), DevelopmentRules::defaults()))->build($id);
                    CharacterCalculationBuilder::defaults()->calculate($snapshot);
                    return ['fields' => $fields, 'event' => 'mechanics_updated',
                        'payload' => ['choices' => $payload, 'plan' => $plan, 'before' => $character['mechanics_status'], 'after' => 'review']];
                });
            });
    }

    private function character(int $id, User $actor): array
    {
        $row = $this->db->table('roleplay_characters')->where('id', $id)->sharedLock()->first();
        if (!$row || !ReviewPermissions::owner((array) $row, $actor)) throw new ApiProblem('character_not_found', 'Персонаж не найден.', 404);
        $this->assertEditable((array) $row, $actor);
        return (array) $row;
    }

    private function assertEditable(array $character, User $actor): void
    {
        if (!ReviewPermissions::canEditMechanics($character, $actor)) {
            throw new ApiProblem('mechanics_edit_closed', 'Техническая часть уже одобрена или персонаж закрыт.', 403);
        }
    }

    private function view(array $character, array $catalog, array $payload, string $step, int $revision): array
    {
        $plan = (new CreationPlanBuilder())->build($payload, $catalog['definitions'], $catalog['configuration']);
        $plan += (new CreationOptions())->build($payload, $catalog['definitions'], $plan);
        // Итог включает дополнительные награды и реальные балансы, но они не становятся стартовым выбором.
        $reviewPlan = $plan;
        $id = (int) $character['id'];
        foreach (['perks' => 'perk_id', 'spells' => 'spell_id'] as $kind => $column) {
            $entries = array_column($reviewPlan[$kind], null, 'id');
            foreach ($this->db->table('roleplay_character_'.$kind)->where('character_id', $id)->where('is_starting', false)->get() as $row) {
                $definition = $catalog['definitions'][$kind][(int) $row->$column];
                $entries[$definition['id']] = $kind === 'spells' ? $definition : [
                    'id' => $definition['id'], 'source_id' => (int) $row->source_id,
                    'rarity' => $row->rarity_override ?? $definition['rarity'],
                    'context' => json_decode($row->source_context, true, flags: JSON_THROW_ON_ERROR),
                ];
            }
            $reviewPlan[$kind] = array_values($entries);
        }
        foreach ($this->db->table('roleplay_character_items')->where('character_id', $id)->where('is_starting', false)->get() as $row) {
            $definition = $catalog['definitions']['items'][(int) $row->item_id];
            $reviewPlan['items'][] = ['id' => $definition['id'], 'name' => $definition['name'], 'quantity' => (int) $row->quantity];
        }
        $reviewPlan['balances'] = $this->db->table('roleplay_character_balances')->where('character_id', $id)->pluck('amount', 'resource_key')
            ->map(fn ($amount) => (int) $amount)->all();
        $plan['review'] = (new CreationReview())->build($payload, $catalog['definitions'], $reviewPlan, $catalog['revision']);
        $visible = CreationCatalog::visible($catalog);
        $visible['configuration']['enabled'] = true; // закрытие нового создания не закрывает правку существующей анкеты
        return ['catalog' => $visible, 'preview' => $plan, 'existing_character_id' => null,
            'character_revision' => (int) $character['revision'],
            'draft' => ['id' => $id, 'revision' => $revision, 'catalog_revision' => $catalog['revision'],
                'rules_hash' => $catalog['rules_hash'], 'step' => $step, 'payload' => $payload]];
    }
}