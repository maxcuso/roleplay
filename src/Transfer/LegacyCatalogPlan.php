<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Transfer;

/**
 * Чистое преобразование: ни SQL, ни побочных записей.
 * Исходный экспорт остаётся архивом полей, для которых в новой модели нет аналога.
 */
final class LegacyCatalogPlan
{
    public function build(array $legacy): array
    {
        $rulePlan = (new LegacyRules())->build($legacy);
        $warnings = $rulePlan['warnings'];
        $catalogs = [];
        $attributes = array_column($legacy['attributes'], null, 'id');
        $attributeIds = array_column($legacy['attributes'], 'id', 'slug');
        $proficiencyIds = array_column($legacy['weapon_proficiencies'], 'id', 'slug');
        $defaults = require __DIR__.'/../../resources/defaults/base-catalogs.php';
        $itemUsage = require __DIR__.'/../../resources/transfer/legacy-items.php';

        // Порядок соответствует внешним ключам. Связи эффектов записываются после всех определений.
        foreach (['attributes' => 'attributes', 'paths' => 'magic_paths', 'item-types' => 'item_types',
            'sources' => 'perk_sources', 'perks' => 'perks', 'skills' => 'skills',
            'proficiencies' => 'weapon_proficiencies', 'spells' => 'spells', 'items' => 'items', 'races' => 'races'] as $key => $table) {
            $catalogs[$key] = [];
            foreach ($legacy[$table] ?? [] as $row) {
                $input = [
                    'name' => $row['name'], 'description' => $row['description'] ?? null,
                    'enabled' => (bool) $row['enabled'], 'sort_order' => (int) $row['sort_order'],
                ];
                switch ($key) {
                    case 'attributes': $input['icon'] = $row['icon']; break;
                    case 'paths': $input += ['icon' => $row['icon'], 'color' => $row['color']]; break;
                    case 'item-types':
                        $input += ['icon' => $row['icon'], 'equipment_limit' => (int) $row['equipment_limit']];
                        break;
                    case 'sources':
                        if ($row['slug'] === 'starting' || mb_strtolower($row['name']) === 'создание персонажа') {
                            $warnings[] = 'Источник '.$row['id'].' «Создание персонажа» исключён по согласованному правилу.';
                            continue 2;
                        }
                        $icons = $row['attribute_icons'] === null ? [] : json_decode($row['attribute_icons'], true, flags: JSON_THROW_ON_ERROR);
                        // Равные переопределения наследуем, чтобы дальнейшая смена иконки не требовала двух правок.
                        foreach ($attributes as $attribute) {
                            if (($icons[$attribute['slug']] ?? null) === $attribute['icon']) unset($icons[$attribute['slug']]);
                        }
                        $input += ['icon' => $row['icon'], 'name_source' => $row['name_source'],
                            'icon_source' => $row['icon_source'], 'attribute_icons' => $icons];
                        break;
                    case 'perks':
                        $image = $row['image'];
                        // Локальные изображения должны работать и на другом адресе тестовой копии.
                        if (is_string($image) && preg_match('~^http://localhost:8080(/assets/roleplay/[^?#]+)$~D', $image, $match)) {
                            $image = $match[1];
                            $warnings[] = 'Изображение перка '.$row['id'].' стало относительным адресом того же файла.';
                        }
                        $input += ['image' => $image, 'rarity' => $row['rarity']];
                        break;
                    case 'skills':
                        $levels = [];
                        foreach ([1, 2, 3] as $level) $levels[] = [
                            'level' => $level, 'perk_id' => $rulePlan['levels'][$row['id']][$level] ?? null,
                        ];
                        if (in_array(null, array_column($levels, 'perk_id'), true)) {
                            $input['enabled'] = false;
                            $warnings[] = 'Навык '.$row['id'].' «'.$row['name'].'» выключен: не заданы все три перка. Известные привязки сохранены.';
                        }
                        $input += ['attribute_id' => (int) $row['attribute_id'], 'levels' => $levels];
                        break;
                    case 'proficiencies':
                        $slug = $defaults['weapon_proficiencies'][$row['slug']]['attribute'] ?? null;
                        $id = $row['attribute_id'] ?? $attributeIds[$slug ?? ''] ?? null;
                        if ($id === null) throw new \DomainException('Неизвестная характеристика владения '.$row['slug']);
                        $input['attribute_id'] = (int) $id;
                        break;
                    case 'spells':
                        $input += ['magic_path_id' => (int) $row['magic_path_id'], 'level' => (int) $row['level']];
                        foreach (['distance_mode' => 'self', 'cast_mode' => 'instant',
                            'duration_unit' => 'turn', 'cast_unit' => 'turn', 'cooldown_unit' => 'turn'] as $field => $default) {
                            $input[$field] = $row[$field] ?? $default;
                        }
                        $input['distance_value'] = $row['distance_value'] === null ? null : (float) $row['distance_value'];
                        $input['cast_value'] = $row['cast_value'] === null ? null : (int) $row['cast_value'];
                        foreach (['radius_value', 'duration_value', 'cooldown_value', 'damage_units', 'mana_cost', 'stamina_cost'] as $field) {
                            $input[$field] = (int) ($row[$field] ?? 0);
                        }
                        $input['other_cost'] = $row['other_cost'];
                        if ($row['distance_mode'] === null) $warnings[] = 'Заклинание '.$row['id'].' не имело структурированной дистанции: задано «На себя», требуется проверка.';
                        break;
                    case 'items':
                        $input += ['item_type_id' => (int) $row['item_type_id'], 'rarity' => $row['rarity'],
                            'appearance' => $row['appearance'], 'is_magical' => (bool) $row['is_magical'],
                            'damage_units' => (int) ($row['damage_units'] ?? 0), 'ignored_damage_units' => 0,
                            'weight' => (float) ($row['weight'] ?? 0), 'base_price' => (float) ($row['base_price'] ?? 0),
                            'max_stack' => (int) ($row['max_stack'] ?? 1), 'properties' => []];
                        $usage = $itemUsage[$row['slug']] ?? [];
                        foreach (['melee', 'ranged', 'magic'] as $mode) {
                            $input['is_'.$mode.'_weapon'] = isset($usage[$mode]);
                            $input[$mode.'_proficiency_id'] = isset($usage[$mode])
                                ? ($proficiencyIds[$usage[$mode]] ?? throw new \DomainException('Неизвестное владение предмета.')) : null;
                        }
                        $input['is_armor'] = (bool) ($usage['armor'] ?? false);
                        foreach ($legacy['item_properties'] ?? [] as $property) {
                            if ($property['item_id'] !== $row['id']) continue;
                            $input['properties'][] = ['description' => $property['description'],
                                'cooldown_value' => (int) ($property['cooldown_value'] ?? 0),
                                'cooldown_unit' => $property['cooldown_unit'] ?? 'turn'];
                        }
                        if ($row['damage_units'] === null || ($row['type'] === 'weapon' && !$usage)) {
                            $warnings[] = 'Предмет '.$row['id'].' требует проверки урона/назначения; отсутствующие величины не придуманы.';
                        }
                        break;
                    case 'races':
                        $input += ['min_age' => $row['min_age'], 'max_age' => $row['max_age'],
                            'rules_url' => $row['rules_url'] ?? null, 'rules' => $rulePlan['races'][$row['id']]];
                        break;
                }
                $catalogs[$key][] = ['id' => (int) $row['id'], 'slug' => $row['slug'], 'input' => $input];
            }
        }
        $effects = (new LegacyEffects())->build($legacy);
        $definitions = array_column($effects['definitions'], 'input', 'id');
        foreach (['perks', 'items'] as $owner) foreach ($catalogs[$owner] as &$row) {
            $row['input']['effects'] = [];
            foreach ($effects['links'] as $record) {
                if ($record['owner'] !== $owner || $record['id'] !== $row['id']) continue;
                $definition = $definitions[$record['link']['effect_id']];
                unset($definition['enabled'], $definition['sort_order']);
                $link = $record['link'];
                unset($link['effect_id']);
                $row['input']['effects'][] = $link + $definition;
            }
        }
        unset($row);
        $warnings[] = 'Прежние формулы stats и слоты экипировки заменены согласованными новыми правилами; исходные строки остаются в экспорте.';
        return ['version' => 1, 'scope' => 'catalogs-only', 'catalogs' => $catalogs,
            'configuration' => $rulePlan['configuration'],
            'warnings' => $warnings, 'excluded' => ['characters', 'grants', 'balances', 'history', 'drafts']];
    }
}
