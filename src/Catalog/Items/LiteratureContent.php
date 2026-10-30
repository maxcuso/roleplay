<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Items;

use Maxcuso\Roleplay\Catalog\Shared\{DefinitionStore, FieldValues};

/** Общая типизированная ссылка. Новые виды подключаются здесь вместе со своим справочником. */
final class LiteratureContent
{
    public const MAX_ENTRIES = 1000;

    public static function catalog(mixed $type): string
    {
        return match ($type) {
            'spell' => 'spells',
            default => FieldValues::invalid('Выберите доступный вид знания'),
        };
    }

    public static function normalize(mixed $contents, bool $consumed, DefinitionStore $store): array
    {
        if (!is_array($contents) || !array_is_list($contents) || count($contents) > self::MAX_ENTRIES) {
            FieldValues::invalid('Укажите содержимое литературы списком не более чем из 1000 знаний');
        }
        if ($consumed && count($contents) !== 1) {
            FieldValues::invalid('Для расходуемой литературы выберите ровно одно знание');
        }
        $clean = $seen = $references = [];
        foreach ($contents as $entry) {
            if (!is_array($entry)) FieldValues::invalid('Проверьте запись содержимого литературы');
            FieldValues::allowed($entry, ['type', 'knowledge_id']);
            $catalog = self::catalog($entry['type'] ?? null);
            $id = FieldValues::integer($entry['knowledge_id'] ?? null, 'Знание', 1, PHP_INT_MAX);
            $key = $catalog.':'.$id;
            if (isset($seen[$key])) FieldValues::invalid('Знание повторяется в содержимом литературы. Уберите повтор');
            $seen[$key] = true;
            $references[$catalog][] = $id;
            $clean[] = ['type' => $entry['type'], 'knowledge_id' => $id];
        }
        foreach ($references as $catalog => $ids) $store->requireIds($catalog, $ids);
        return $clean;
    }
}
