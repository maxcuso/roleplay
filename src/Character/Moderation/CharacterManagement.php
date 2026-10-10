<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Character\Moderation;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Api\OperationRequest;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Character\Operations\CharacterOperation;
use Maxcuso\Roleplay\Character\Profile\CharacterVisibility;
use Maxcuso\Roleplay\Character\State\CharacterGrants;
use Maxcuso\Roleplay\Character\State\CharacterBalances;
use Maxcuso\Roleplay\Creation\CreationCatalog;

/** Выдачи/изъятия доступны персоналу через профиль. Каждая команда требует причины и пишется в историю. */
final class CharacterManagement
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function lookups(int $id, User $actor): array
    {
        return (new CreationCatalog($this->db))->read(function (array $catalog) use ($id, $actor): array {
            $character = $this->db->table('roleplay_characters')->where('id', $id)->first();
            if (!$character) throw new ApiProblem('character_not_found', 'Персонаж не найден.', 404);
            $this->authorize((array) $character, $actor);
            // Для выбора не передаём административные правила рас и механические определения эффектов.
            $lookups = [];
            foreach (['perks', 'spells', 'items', 'sources', 'races', 'skills', 'attributes', 'paths', 'item-types', 'ranks'] as $key) {
                $lookups[$key] = array_values(array_map(fn (array $row) =>
                    array_intersect_key($row, array_flip(['id', 'name', 'name_source', 'icon_source', 'level',
                        'image', 'icon', 'color', 'magic_path_id', 'item_type_id', 'enabled', 'letter', 'description'])),
                    array_filter($catalog['definitions'][$key], fn (array $row) => $row['enabled'])));
            }
            return ['revision' => $catalog['revision'], 'lookups' => $lookups];
        });
    }

    public function change(int $id, User $actor, mixed $input): array
    {
        $body = OperationRequest::body($input, ['operation_key', 'revision', 'catalog_revision', 'action', 'data', 'comment']);
        $action = $body['action'] ?? null;
        if (!in_array($action, ['grant_perk', 'remove_perk', 'grant_spell', 'remove_spell', 'grant_item', 'remove_item', 'balance', 'reward', 'set_rank'], true)
            || !is_array($body['data'] ?? null)) throw new ApiProblem('invalid_request', 'Неизвестная выдача.', 400);
        $comment = FieldValues::text($body['comment'] ?? null, 'Причина', 4000, true);
        return (new CharacterOperation($this->db))->run($id, $actor, 'character_management', $body,
            fn (array $character) => $this->authorize($character, $actor),
            function (array $character) use ($id, $action, $body, $comment): array {
                if ($character['status'] !== 'active') throw new ApiProblem('management_closed', 'Изменять выдачи можно у активного персонажа.', 409);
                return (new CreationCatalog($this->db))->read(function (array $catalog) use ($id, $action, $body, $comment, $character): array {
                    if ($action === 'set_rank') {
                        return (new CharacterRank())->change($character, $body['data'], $catalog['definitions']['ranks'], $comment);
                    }
                    $details = $this->apply($id, $action, $body['data'], $catalog['definitions']);
                    return ['fields' => [], 'event' => 'character_managed',
                        'payload' => ['action' => $action, 'comment' => $comment] + $details];
                });
            });
    }

    private function authorize(array $character, User $actor): void
    {
        (new CharacterVisibility())->assertVisible($character, $actor);
        if (!ReviewPermissions::staff($actor)) throw new ApiProblem('management_forbidden', 'Выдачи доступны только модератору.', 403);
    }

    private function apply(int $id, string $action, array $data, array $definitions): array
    {
        if ($action === 'reward') {
            FieldValues::allowed($data, ['experience', 'gold']);
            $experience = FieldValues::integer($data['experience'] ?? 0, 'Опыт', -9007199254740991, 9007199254740991);
            $gold = FieldValues::integer($data['gold'] ?? 0, 'Золото', -9007199254740991, 9007199254740991);
            if (($experience === 0 && $gold === 0) || ($experience > 0 && $gold < 0) || ($experience < 0 && $gold > 0)) {
                throw new ApiProblem('invalid_balance_change', 'Укажи начисление или списание хотя бы одного ресурса.');
            }
            // CharacterOperation уже открыл общую транзакцию: ошибка второго баланса откатит первый и историю.
            $balances = new CharacterBalances($this->db);
            $changes = [];
            if ($experience !== 0) $changes += $balances->adjust($id, 'experience_active', $experience);
            if ($gold !== 0) $changes += $balances->adjust($id, 'gold', $gold);
            return ['experience' => $experience, 'gold' => $gold, 'balances' => $changes];
        }
        if ($action === 'balance') {
            FieldValues::allowed($data, ['resource', 'delta']);
            $delta = FieldValues::integer($data['delta'] ?? null, 'Изменение', -9007199254740991, 9007199254740991);
            $resource = FieldValues::text($data['resource'] ?? null, 'Ресурс', 32, true);
            return ['resource' => $resource, 'delta' => $delta, 'balances' => (new CharacterBalances($this->db))->adjust($id, $resource, $delta)];
        }
        $kind = substr($action, strpos($action, '_') + 1);
        $grant = str_starts_with($action, 'grant_');
        FieldValues::allowed($data, $kind === 'perk' && $grant ? ['target_id', 'source_id', 'context']
            : ($kind === 'item' ? ['target_id', 'quantity'] : ['target_id']));
        $target = FieldValues::integer($data['target_id'] ?? null, 'Сущность', 1, PHP_INT_MAX);
        $quantity = $kind === 'item' ? FieldValues::integer($data['quantity'] ?? null, 'Количество', 1, 1000) : 1;
        $grants = new CharacterGrants($this->db);
        if ($grant) {
            $definition = $definitions[$kind.'s'][$target] ?? null;
            if (!$definition || !$definition['enabled']) throw new ApiProblem('grant_unavailable', 'Запись выключена или не найдена.', 409);
            if ($kind === 'item') $grants->items($id, $target, $quantity, $definition['max_stack']);
            else {
                $owned = $this->db->table('roleplay_character_'.$kind.'s')->where('character_id', $id)->where($kind.'_id', $target)->exists();
                if ($owned) throw new ApiProblem('already_owned', 'Персонаж уже имеет эту сущность.', 409);
                if ($kind === 'perk') {
                    $sourceId = FieldValues::integer($data['source_id'] ?? null, 'Источник', 1, PHP_INT_MAX);
                    $source = $definitions['sources'][$sourceId] ?? null;
                    if (!$source || !$source['enabled'] || !is_array($data['context'] ?? null)) {
                        throw new ApiProblem('source_context_missing', 'Выбери действующий источник и его параметры.');
                    }
                    $context = (new PerkGrantSource())->context($source, $data['context'], $definitions);
                    $grants->perk($id, $target, $sourceId, $context);
                    return ['kind' => $kind, 'target_id' => $target, 'name' => $definition['name'], 'source_id' => $sourceId, 'context' => $context];
                }
                if (!$definitions['paths'][$definition['magic_path_id']]['enabled']) {
                    throw new ApiProblem('magic_path_disabled', 'Путь выключен для новых заклинаний.', 409);
                }
                $grants->spell($id, $target);
            }
            return ['kind' => $kind, 'target_id' => $target, 'name' => $definition['name'], 'quantity' => $quantity];
        }
        $query = $this->db->table('roleplay_character_'.$kind.'s')->where('character_id', $id);
        // При изъятии вещи выбирается конкретная стопка, не ID определения.
        $query->where($kind === 'item' ? 'id' : $kind.'_id', $target);
        $row = $query->lockForUpdate()->first();
        if (!$row) throw new ApiProblem('grant_not_found', 'Сущность у персонажа не найдена.', 404);
        if ($kind === 'item' && $quantity > (int) $row->quantity) {
            throw new ApiProblem('insufficient_items', 'В выбранной стопке недостаточно предметов.', 409);
        }
        if ($kind === 'item' && $quantity < (int) $row->quantity) $query->decrement('quantity', $quantity);
        else $query->delete();
        return ['kind' => $kind, 'target_id' => $target, 'quantity' => $quantity, 'removed' => (array) $row];
    }
}
