<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Transfer;

use Maxcuso\Roleplay\Api\ApiProblem;

/** Совместимость старых JSON: определения копируются в владельцев, новый экспорт уже встроенный. */
final class LegacyEffectDocument
{
    public function convert(array $catalogs): array
    {
        $definitions = array_column($catalogs['effects'] ?? [], null, 'key');
        foreach (['perks', 'items'] as $owner) {
            if (!isset($catalogs[$owner])) continue;
            foreach ($catalogs[$owner] as &$row) {
                if (!isset($row['effects'])) continue;
                if (!is_array($row['effects']) || !array_is_list($row['effects'])) $this->invalid('Укажите эффекты списком');
                foreach ($row['effects'] as &$effect) {
                    if (!is_array($effect)) $this->invalid('Проверьте параметры эффекта');
                    if (!array_key_exists('effect_key', $effect)) continue;
                    $key = $effect['effect_key'];
                    if (!is_string($key) || !isset($definitions[$key])) {
                        $this->invalid('Для старой привязки добавьте в раздел effects определение эффекта: '.(is_string($key) ? $key : 'пустой ключ'));
                    }
                    $definition = $definitions[$key];
                    unset($definition['key'], $definition['enabled'], $definition['sort_order'], $effect['effect_key']);
                    $effect += $definition;
                }
                unset($effect);
            }
            unset($row);
        }
        // Непривязанные определения не являются самостоятельными сущностями новой модели.
        if (isset($catalogs['effects']) && !isset($catalogs['perks']) && !isset($catalogs['items'])) {
            $this->invalid('Укажите эффекты внутри соответствующих перков или предметов. Отдельный импорт эффектов больше не поддерживается');
        }
        unset($catalogs['effects']);
        return $catalogs;
    }

    private function invalid(string $message): never { throw new ApiProblem('invalid_catalog_json', $message); }
}
