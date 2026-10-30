<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Items;

use Illuminate\Database\Query\Builder;
use Maxcuso\Roleplay\Catalog\Shared\EffectBoundCatalog;
use Maxcuso\Roleplay\Catalog\Shared\AbilityValues;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Rules\ItemAttackRules;

final class ItemCatalog extends EffectBoundCatalog
{
    protected const TABLE = 'items';
    protected const INTEGER_FIELDS = ['item_type_id', 'damage_units', 'ignored_damage_units', 'max_stack', 'melee_proficiency_id', 'ranged_proficiency_id', 'magic_proficiency_id'];
    protected const BOOLEAN_FIELDS = ['is_magical', 'is_melee_weapon', 'is_ranged_weapon', 'is_magic_weapon', 'is_armor', 'is_ingredient', 'is_literature', 'consumed_on_learning'];
    protected const DECIMAL_FIELDS = ['weight', 'base_price'];
    protected const JSON_FIELDS = ['properties'];

    public function validate(array $input): array
    {
        FieldValues::allowed($input, [
            'name', 'description', 'enabled', 'sort_order', 'item_type_id', 'rarity', 'appearance',
            'is_magical', 'is_melee_weapon', 'is_ranged_weapon', 'is_magic_weapon', 'is_armor', 'is_ingredient', 'is_literature',
            'damage_units', 'ignored_damage_units', 'weight', 'base_price', 'max_stack', 'properties', 'effects',
            'melee_proficiency_id', 'ranged_proficiency_id', 'magic_proficiency_id',
            'literature_contents', 'consumed_on_learning',
        ]);
        $properties = $input['properties'] ?? [];
        if (!is_array($properties) || !array_is_list($properties) || count($properties) > 100) {
            FieldValues::invalid('Укажите свойства предмета списком не более чем из 100 записей');
        }
        $clean = [];
        foreach ($properties as $property) {
            if (!is_array($property)) FieldValues::invalid('Проверьте параметры свойства предмета');
            FieldValues::allowed($property, ['description', 'cooldown_value', 'cooldown_unit']);
            $clean[] = ['description' => FieldValues::text($property['description'] ?? null, 'Свойство', 20000, true)]
                + AbilityValues::time($property, 'cooldown');
        }
        $consumed = FieldValues::boolean($input['consumed_on_learning'] ?? false, 'Расходуется при изучении');
        $contents = LiteratureContent::normalize($input['literature_contents'] ?? [], $consumed, $this->store);
        return FieldValues::common($input) + [
            'item_type_id' => AbilityValues::reference($this->store, 'item_types', $input['item_type_id'] ?? null),
            'rarity' => AbilityValues::option($input['rarity'] ?? 'common', AbilityValues::RARITIES, 'Редкость'),
            'appearance' => FieldValues::text($input['appearance'] ?? null, 'Внешность', 20000),
            'is_magical' => FieldValues::boolean($input['is_magical'] ?? false, 'Магический'),
            // Магический плащ не становится оружием из-за чар. Способы атаки проверяем отдельно.
            'is_armor' => FieldValues::boolean($input['is_armor'] ?? false, 'Броня'),
            // Независимые признаки сохраняются сейчас, игровые действия появятся позже.
            'is_ingredient' => FieldValues::boolean($input['is_ingredient'] ?? false, 'Ингредиент'),
            'is_literature' => FieldValues::boolean($input['is_literature'] ?? false, 'Литература'),
            'consumed_on_learning' => $consumed,
            'literature_contents' => json_encode($contents, JSON_THROW_ON_ERROR),
            // Сохраняем величину при снятии галочки; расчёт должен применять её только к броне.
            'ignored_damage_units' => FieldValues::integer($input['ignored_damage_units'] ?? 0, 'Игнорируемый урон', 0, 1000000),
            'damage_units' => FieldValues::integer($input['damage_units'] ?? 0, 'Урон', 0, 1000000),
            'weight' => AbilityValues::number($input['weight'] ?? 0, 'Вес'),
            // До трёх знаков после запятой; дробную часть скрывает только представление.
            'base_price' => AbilityValues::number($input['base_price'] ?? 0, 'Стоимость', 9007199254740991),
            'max_stack' => FieldValues::integer($input['max_stack'] ?? 1, 'Максимум в стопке', 1, 1000000),
            // Свойства остаются текстом. Числовые эффекты не угадываются из описания.
            'properties' => json_encode($clean, JSON_THROW_ON_ERROR),
        ] + $this->attackUsage($input);
    }

    public function present(array $row): array
    {
        $row = parent::present($row);
        // Старые предметы без содержимого становятся пустыми источниками.
        $row['literature_contents'] = json_decode($row['literature_contents'] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        return $row;
    }

    /** ID задаёт администратор: переименование владения не меняет механику предмета. */
    private function attackUsage(array $input): array
    {
        $result = [];
        foreach (ItemAttackRules::modes() as $usage) {
            $flag = FieldValues::boolean($input[$usage['flag']] ?? false, $usage['label']);
            $value = $input[$usage['proficiency']] ?? null;
            if ($flag && $value === null) {
                FieldValues::invalid($usage['label'].': выберите требуемое владение');
            }
            // При снятии галочки можно сохранить прежнюю привязку, но в атаке она не участвует.
            $result[$usage['flag']] = $flag;
            $result[$usage['proficiency']] = $value === null ? null
                : AbilityValues::reference($this->store, 'weapon_proficiencies', $value);
        }
        return $result;
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        FieldValues::allowed($filters, ['type', 'magical']);
        if (isset($filters['type']) && $filters['type'] !== '') {
            if (!is_string($filters['type']) || !ctype_digit($filters['type']) || (int) $filters['type'] < 1) {
                FieldValues::invalid('Выберите доступный тип предмета');
            }
            $query->where('item_type_id', (int) $filters['type']);
        }
        if (isset($filters['magical']) && $filters['magical'] !== '') {
            if (!in_array($filters['magical'], ['0', '1'], true)) FieldValues::invalid('Не удалось применить фильтр магических предметов. Попробуйте обновить страницу');
            $query->where('is_magical', $filters['magical'] === '1');
        }
    }
}
