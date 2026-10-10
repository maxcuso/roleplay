<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Api\OperationRequest;

/** Создание добавляет к общему контракту операций проверку редакции игровых правил. */
final class CreationRequest
{
    public static function body(mixed $input, array $fields): array
    {
        return OperationRequest::body($input, $fields);
    }

    public static function revision(mixed $value, bool $newDraft = false): int
    {
        return OperationRequest::revision($value, $newDraft);
    }

    public static function operationKey(mixed $value): string
    {
        return OperationRequest::key($value);
    }

    public static function rulesHash(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9a-f]{64}$/D', $value)) {
            throw new ApiProblem('invalid_request', 'Некорректная версия правил.', 400);
        }
        return $value;
    }

    public static function current(array $catalog, int $revision, string $hash): void
    {
        if ($catalog['revision'] !== $revision || !hash_equals($catalog['rules_hash'], $hash)) {
            throw new ApiProblem('creation_rules_changed', 'Правила изменились. Обнови мастер и проверь выбор.', 409);
        }
    }
}