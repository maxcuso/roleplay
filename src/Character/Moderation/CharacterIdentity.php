<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Moderation;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Api\OperationRequest;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Character\Operations\CharacterOperation;

/** Владелец исправляет только имя/фамилию/возраст. Стартовые выдачи не пересоздаются. */
final class CharacterIdentity
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function correct(int $id, User $actor, mixed $input): array
    {
        $body = OperationRequest::body($input, ['operation_key', 'revision', 'catalog_revision', 'first_name', 'last_name', 'age']);
        $first = FieldValues::text($body['first_name'] ?? null, 'Имя', 100, true);
        $last = FieldValues::text($body['last_name'] ?? null, 'Фамилия', 100);
        $age = FieldValues::integer($body['age'] ?? null, 'Возраст', 0, 1000000);
        return (new CharacterOperation($this->db))->run($id, $actor, 'identity_correction', $body,
            function (array $character) use ($actor): void {
                if (!ReviewPermissions::owner($character, $actor)) throw new ApiProblem('character_not_found', 'Персонаж не найден. Попробуйте обновить страницу', 404);
            },
            function (array $character) use ($actor, $first, $last, $age): array {
                if (!ReviewPermissions::describe($character, $actor, $character['literature_status'])['can_edit_identity']) {
                    throw new ApiProblem('identity_forbidden', 'Исправление станет доступно после возврата технической части анкеты на доработку', 403);
                }
                $race = (new CatalogRegistry($this->db))->definitionsFor('races', [(int) $character['race_id']])[0];
                if ($race['max_age'] !== null && $age > $race['max_age']) {
                    throw new ApiProblem('invalid_age', 'Укажите возраст в пределах, допустимых для выбранной расы');
                }
                return [
                    'fields' => ['first_name' => $first, 'last_name' => $last, 'age' => $age, 'mechanics_status' => 'draft'],
                    'event' => 'identity_corrected',
                    'payload' => [
                        'before' => array_intersect_key($character, array_flip(['first_name', 'last_name', 'age'])),
                        'after' => ['first_name' => $first, 'last_name' => $last, 'age' => $age],
                    ],
                ];
            });
    }
}
