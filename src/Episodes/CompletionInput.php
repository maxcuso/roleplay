<?php

declare(strict_types=1);
namespace Maxcuso\Roleplay\Episodes;

use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

final class CompletionInput
{
    public static function payload(mixed $input): array
    {
        if (!is_array($input)) throw new ApiProblem('invalid_completion', 'Проверьте настройки наград');
        FieldValues::allowed($input, ['event_id', 'discussion_id', 'revision', 'recipients']);
        $revision = FieldValues::text($input['revision'] ?? null, 'Версия эпизода', 64, true);
        if (!preg_match('/^[a-f0-9]{64}$/D', $revision)) throw new ApiProblem('invalid_completion', 'Обновите данные эпизода');
        $recipients = self::rows($input['recipients'] ?? null, 100, 'Добавьте получателей награды');
        if (!$recipients) throw new ApiProblem('invalid_completion', 'Добавьте хотя бы одного получателя награды');
        $seen = []; $result = [];
        foreach ($recipients as $row) {
            if (!is_array($row)) throw new ApiProblem('invalid_completion', 'Проверьте получателей награды');
            FieldValues::allowed($row, ['character_id', 'experience', 'gold', 'items', 'perks', 'spells']);
            $id = FieldValues::integer($row['character_id'] ?? null, 'Персонаж', 1, 4294967295);
            if (isset($seen[$id])) throw new ApiProblem('invalid_completion', 'Укажите каждого получателя один раз');
            $seen[$id] = true; $items = []; $itemIds = [];
            foreach (self::rows($row['items'] ?? [], 100, 'Проверьте список предметов') as $item) {
                if (!is_array($item)) throw new ApiProblem('invalid_completion', 'Проверьте предметы награды');
                FieldValues::allowed($item, ['id', 'quantity']);
                $target = FieldValues::integer($item['id'] ?? null, 'Предмет', 1, 4294967295);
                if (isset($itemIds[$target])) throw new ApiProblem('invalid_completion', 'Объедините одинаковые предметы в одну строку');
                $itemIds[$target] = true;
                $items[] = ['id' => $target, 'quantity' => FieldValues::integer($item['quantity'] ?? null, 'Количество', 1, 1000)];
            }
            usort($items, fn ($a, $b) => $a['id'] <=> $b['id']);
            $result[] = ['character_id' => $id,
                'experience' => FieldValues::integer($row['experience'] ?? 0, 'Опыт', 0, 9007199254740991),
                'gold' => FieldValues::integer($row['gold'] ?? 0, 'Золото', 0, 9007199254740991),
                'items' => $items, 'perks' => self::ids($row['perks'] ?? []), 'spells' => self::ids($row['spells'] ?? [])];
        }
        usort($result, fn ($a, $b) => $a['character_id'] <=> $b['character_id']);
        return ['event_id' => ($input['event_id'] ?? null) === null ? null : FieldValues::integer($input['event_id'], 'Эпизод', 1, 4294967295),
            'discussion_id' => FieldValues::integer($input['discussion_id'] ?? null, 'Тема', 1, 4294967295),
            'revision' => $revision, 'recipients' => $result];
    }

    private static function rows(mixed $rows, int $max, string $message): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > $max) throw new ApiProblem('invalid_completion', $message);
        return $rows;
    }

    private static function ids(mixed $rows): array
    {
        $ids = array_map(fn ($id) => FieldValues::integer($id, 'Запись справочника', 1, 4294967295),
            self::rows($rows, 100, 'Укажите не больше 100 записей каждого вида награды'));
        if (count(array_unique($ids)) !== count($ids)) throw new ApiProblem('invalid_completion', 'Укажите каждую запись награды один раз');
        sort($ids); return $ids;
    }
}
