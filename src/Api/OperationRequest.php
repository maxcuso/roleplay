<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Общий контракт команд: версии и ключ повторного запроса не зависят от игрового сценария. */
final class OperationRequest
{
    public static function body(mixed $input, array $fields): array
    {
        if (!is_array($input) || array_diff(array_keys($input), $fields)) {
            throw new ApiProblem('invalid_request', 'Некорректные поля запроса.', 400);
        }
        return $input;
    }

    public static function revision(mixed $value, bool $allowZero = false): int
    {
        return FieldValues::integer($value, 'Версия', $allowZero ? 0 : 1, 4294967295);
    }

    public static function key(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
            throw new ApiProblem('invalid_request', 'Нужен уникальный UUID v4 запроса.', 400);
        }
        return $value;
    }
}