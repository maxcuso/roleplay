<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Effects;

use Maxcuso\Roleplay\Catalog\Shared\{DefinitionStore, FieldValues};
use Maxcuso\Roleplay\Rules\EffectRules;

/** Эффект целиком принадлежит перку/предмету: отдельного справочника и скрытых формул нет. */
final class InlineEffects
{
    public function __construct(private DefinitionStore $store) {}

    public function normalize(mixed $input, string $owner): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > 100) {
            FieldValues::invalid('Укажите эффекты списком не более чем из 100 записей');
        }
        return array_map(fn ($row) => $this->effect($row, $owner), $input);
    }

    private function effect(mixed $input, string $owner): array
    {
        if (!is_array($input)) FieldValues::invalid('Проверьте параметры эффекта');
        FieldValues::allowed($input, ['name', 'description', 'icon', 'mechanic', 'operation',
            'attribute_id', 'skill_id', 'stat', 'value', 'condition', 'condition_text', 'scope', 'stack_group']);
        $configuration = EffectRules::configuration();
        $mechanic = $input['mechanic'] ?? 'text';
        if (!is_string($mechanic) || !isset($configuration['mechanics'][$mechanic])) FieldValues::invalid('Выберите механику эффекта');
        $rule = $configuration['mechanics'][$mechanic];
        $operation = $input['operation'] ?? 'add';
        if (!in_array($operation, $rule['operations'], true)) FieldValues::invalid('Выберите операцию, доступную для этой механики эффекта');
        $row = [
            // Числовая подпись собирается из цели и величины. Название нужно только текстовому эффекту.
            'name' => FieldValues::text($input['name'] ?? null, 'Название эффекта', 100, $mechanic === 'text'),
            'description' => FieldValues::text($input['description'] ?? null, 'Описание эффекта', 20000),
            'icon' => FieldValues::icon($input['icon'] ?? null),
            'mechanic' => $mechanic, 'operation' => $operation,
            'attribute_id' => null, 'skill_id' => null, 'stat' => null,
            'value' => $input['value'] ?? null,
            'condition' => $input['condition'] ?? 'always', 'scope' => $input['scope'] ?? 'all',
            'stack_group' => FieldValues::text($input['stack_group'] ?? null, 'Группа нескладываемых бонусов', 100),
            'condition_text' => FieldValues::text($input['condition_text'] ?? null, 'Условие эффекта', 1000),
        ];
        foreach (['attribute' => 'attributes', 'skill' => 'skills'] as $target => $table) {
            $column = $target.'_id';
            if ($rule['target'] === $target) {
                $row[$column] = FieldValues::integer($input[$column] ?? null, 'Цель эффекта', 1, PHP_INT_MAX);
                $this->store->requireIds($table, [$row[$column]]);
            } elseif (($input[$column] ?? null) !== null) FieldValues::invalid('Для этого эффекта не нужна цель. Уберите её');
        }
        if ($rule['target'] === 'stat') {
            $stat = $input['stat'] ?? null;
            if (!is_string($stat) || !isset($configuration['stats'][$stat])) FieldValues::invalid('Выберите параметр эффекта');
            if ($operation === 'tiers' && !EffectRules::damageTarget($stat)) FieldValues::invalid('Ступени доступны только для урона и защиты. Измените параметр или способ расчёта эффекта');
            $row['stat'] = $stat;
        } elseif (($input['stat'] ?? null) !== null) FieldValues::invalid('Для этого эффекта не нужен параметр. Уберите его');
        foreach (['condition' => 'conditions', 'scope' => 'scopes'] as $field => $options) {
            if (!is_string($row[$field]) || !isset($configuration[$options][$row[$field]])) {
                FieldValues::invalid('Выберите доступное условие и область применения эффекта');
            }
        }
        try { EffectRules::validateLink($row, $row, $owner); }
        catch (\DomainException $error) { FieldValues::invalid($error->getMessage()); }
        if ($row['condition'] !== 'manual') $row['condition_text'] = null;
        return $row;
    }
}
