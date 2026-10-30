<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Checks;

use Maxcuso\Roleplay\Api\{ApiProblem, OperationRequest};
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

final class CheckInput
{
    public const DIFFICULTIES = [5 => 'Легко', 10 => 'Средне', 15 => 'Тяжело', 22 => 'Легендарно', 30 => 'Невозможно'];

    public static function payload(string $type, mixed $input): array
    {
        if (!is_array($input) || !in_array($type, ['request', 'roll'], true)) throw new ApiProblem('invalid_check', 'Проверьте настройки проверки');
        FieldValues::allowed($input, $type === 'request'
            ? ['kind', 'target_id', 'difficulty', 'description', 'target', 'characters']
            : ['kind', 'target_id', 'difficulty', 'character_id', 'request_id', 'modifier', 'reason']);
        $kind = $input['kind'] ?? null;
        if (!in_array($kind, ['attribute', 'skill'], true)) throw new ApiProblem('invalid_check', 'Выберите навык или характеристику');
        $difficulty = ($input['difficulty'] ?? null) === null ? null : FieldValues::integer($input['difficulty'], 'Сложность', 1, 100);
        if (($difficulty === null && $type === 'request') || ($difficulty !== null && !isset(self::DIFFICULTIES[$difficulty]))) {
            throw new ApiProblem('invalid_check', 'Выберите доступную сложность проверки');
        }
        $base = ['kind' => $kind, 'target_id' => FieldValues::integer($input['target_id'] ?? null, 'Проверка', 1, 4294967295), 'difficulty' => $difficulty];
        if ($type === 'request') {
            $target = $input['target'] ?? null;
            $ids = $input['characters'] ?? [];
            if (!in_array($target, ['all', 'selected'], true) || !is_array($ids) || !array_is_list($ids) || count($ids) > 100) throw new ApiProblem('invalid_check', 'Выберите получателей запроса');
            $ids = array_values(array_unique(array_map(fn ($id) => FieldValues::integer($id, 'Персонаж', 1, 4294967295), $ids)));
            sort($ids);
            if ($target === 'selected' && !$ids) throw new ApiProblem('invalid_check', 'Выберите хотя бы одного персонажа');
            return $base + ['description' => FieldValues::text($input['description'] ?? '', 'Описание действия', 2000) ?? '',
                'target' => $target, 'characters' => $target === 'all' ? [] : $ids];
        }
        $modifier = FieldValues::integer($input['modifier'] ?? 0, 'Дополнительный бонус или штраф', -10000, 10000);
        $reason = FieldValues::text($input['reason'] ?? '', 'Причина бонуса или штрафа', 500) ?? '';
        if ($modifier !== 0 && $reason === '') throw new ApiProblem('invalid_check', 'Укажите причину дополнительного бонуса или штрафа');
        return $base + ['character_id' => FieldValues::integer($input['character_id'] ?? null, 'Персонаж', 1, 4294967295),
            'request_id' => ($input['request_id'] ?? null) === null ? null : OperationRequest::key($input['request_id']),
            'modifier' => $modifier, 'reason' => $modifier === 0 ? '' : $reason];
    }
}
