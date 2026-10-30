<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\PerkSources;

use Maxcuso\Roleplay\Catalog\Shared\AbstractCatalog;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Описывает правила подписи источника; конкретная выдача будет храниться у персонажа. */
final class PerkSourceCatalog extends AbstractCatalog
{
    private const KINDS = ['race', 'skill', 'quest', 'event'];

    protected const TABLE = 'perk_sources';
    protected const JSON_FIELDS = ['attribute_icons'];

    public function validate(array $input): array
    {
        FieldValues::allowed($input, ['name', 'description', 'enabled', 'sort_order', 'icon',
            'name_source', 'icon_source', 'attribute_icons']);
        $common = FieldValues::common($input);
        // Процесс создания не является происхождением способности.
        if (in_array(mb_strtolower($common['name']), ['создание персонажа', 'starting'], true)) {
            FieldValues::invalid('Создание персонажа нельзя использовать как источник перка. Выберите другой источник');
        }
        $nameSource = $this->dependency($input['name_source'] ?? null);
        $iconSource = $this->dependency($input['icon_source'] ?? null);
        $icons = $input['attribute_icons'] ?? [];
        if (!is_array($icons)) FieldValues::invalid('Укажите настройки иконок характеристик списком');
        $allowed = array_column($this->store->references('attributes'), 'slug');
        if (array_diff(array_keys($icons), $allowed)) FieldValues::invalid('В настройках иконок указана неизвестная характеристика');
        foreach ($icons as $slug => $icon) {
            $value = FieldValues::icon($icon);
            if ($value === null) unset($icons[$slug]);
            else $icons[$slug] = $value;
        }
        return $common + [
            'icon' => FieldValues::icon($input['icon'] ?? null),
            'name_source' => $nameSource,
            'icon_source' => $iconSource,
            // Пустое переопределение удаляем: тогда иконка наследуется от характеристики.
            'attribute_icons' => json_encode((object) $icons, JSON_THROW_ON_ERROR),
        ];
    }

    private function dependency(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || !in_array($value, self::KINDS, true)) {
            FieldValues::invalid('Не удалось определить зависимость источника');
        }
        return $value;
    }
}