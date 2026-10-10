<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\State;

use Maxcuso\Roleplay\Rules\CalculatedValue;
use Maxcuso\Roleplay\Rules\DevelopmentRules;

/** Подготовка данных отделена от SQL. Сохранённые расовые бонусы не пересчитываются по новой редакции расы. */
final class CharacterSnapshotBuilder
{
    public function __construct(
        private readonly CharacterStateReader $reader,
        private readonly DevelopmentRules $rules,
    ) {}

    public function build(int $characterId): CharacterSnapshot
    {
        return $this->fromState($this->reader->read($characterId));
    }

    /** Внутренний формат reader, не пользовательский запрос. Отдельный вход позволяет проверить испорченный импорт. */
    public function fromState(array $state): CharacterSnapshot
    {
        $character = $this->character($state);
        $definitions = $state['definitions'];
        $id = $character['id'];
        $attributes = $this->attributes($state['attributes'], $definitions, $character);
        $skills = $this->skills($state['skills'], $definitions, $id);
        $proficiencies = $this->proficiencies($state['proficiencies'], $definitions, $id);
        $perks = $this->perks($state['perks'], $definitions, $id);
        $spells = $this->spells($state['spells'], $definitions, $id);
        $inventory = $this->inventory($state['inventory'], $definitions, $id);
        return new CharacterSnapshot(
            catalogRevision: $this->integer($state['catalog_revision'], 'Версия справочников', 1),
            character: $character,
            attributes: $attributes,
            skills: $skills,
            proficiencies: $proficiencies,
            perks: $perks,
            spells: $spells,
            inventory: $inventory,
            equipment: array_values(array_filter($inventory, fn (array $item) => $item['equipped'])),
            missingAttributes: array_values(array_diff($this->rules->attributeSlugs(), array_keys($attributes))),
            balances: $this->balances($state['balances'] ?? [], $id),
            choices: $this->choices($state['choices'] ?? [], $id),
        );
    }

    private function character(array $state): array
    {
        $row = $state['character'];
        foreach (['id', 'user_id', 'race_id', 'revision', 'lore_revision'] as $field) {
            $row[$field] = $this->integer($row[$field], $field, 1);
        }
        $row['discussion_id'] = $row['discussion_id'] === null ? null
            : $this->integer($row['discussion_id'], 'Тема анкеты', 1);
        $row['literature_approved'] = $this->boolean($row['literature_approved']);
        foreach (['age', 'creation_catalog_revision'] as $field) {
            $row[$field] = ($row[$field] ?? null) === null ? null : $this->integer($row[$field], $field, $field === 'age' ? 0 : 1);
        }
        $row['race'] = $this->definition($state['definitions'], 'races', $row['race_id']);
        $row['rank_id'] = ($row['rank_id'] ?? null) === null ? null : $this->integer($row['rank_id'], 'Ранг', 1);
        // Выключенный ранг остаётся видимым у уже имеющего его персонажа.
        $row['rank'] = $row['rank_id'] === null ? null : $this->definition($state['definitions'], 'ranks', $row['rank_id']);
        return $row;
    }

    private function attributes(array $rows, array $definitions, array $character): array
    {
        $result = [];
        foreach ($rows as $row) {
            $this->owned($row, $character['id']);
            $attributeId = $this->integer($row['attribute_id'], 'Характеристика', 1);
            $definition = $this->definition($definitions, 'attributes', $attributeId);
            $slug = $definition['slug'];
            if (!$this->rules->hasAttribute($slug) || isset($result[$slug])) {
                throw new \DomainException('Неизвестная или повторная характеристика персонажа.');
            }
            $base = $this->integer($row['base_value'], 'Базовая характеристика', 1);
            $racial = $this->integer($row['racial_bonus'], 'Расовая прибавка', PHP_INT_MIN);
            $level = CalculatedValue::number($base + $racial);
            if ($level < 1
                || $level > $this->rules->maximumAttributeLevel()) {
                throw new \DomainException('Сохранённый уровень характеристики выходит за пределы правил.');
            }
            $result[$slug] = [
                'id' => $attributeId, 'name' => $definition['name'], 'icon' => $definition['icon'],
                'base_value' => $base, 'racial_bonus' => $racial, 'level' => $level,
                'sources' => [
                    ['value' => $base, 'source' => ['kind' => 'attribute_base', 'label' => 'База']],
                    ['value' => $racial, 'source' => [
                        'kind' => 'race', 'id' => $character['race_id'], 'label' => $character['race']['name'],
                    ]],
                ],
            ];
        }
        return $result;
    }

    private function skills(array $rows, array $definitions, int $characterId): array
    {
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $this->owned($row, $characterId);
            $id = $this->integer($row['skill_id'], 'Навык', 1);
            $this->unique($seen, $id, 'Навык');
            $level = $this->integer($row['level'], 'Уровень навыка', 1);
            if ($level > $this->rules->maximumSkillLevel()) {
                throw new \DomainException('Сохранённый уровень навыка выходит за пределы правил.');
            }
            $definition = $this->definition($definitions, 'skills', $id);
            // Выключенная запись остаётся у уже обученного персонажа.
            $result[] = ['id' => $id, 'name' => $definition['name'], 'level' => $level, 'definition' => $definition];
        }
        return $result;
    }

    private function proficiencies(array $rows, array $definitions, int $characterId): array
    {
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $this->owned($row, $characterId);
            $id = $this->integer($row['proficiency_id'], 'Владение', 1);
            $this->unique($seen, $id, 'Владение');
            $definition = $this->definition($definitions, 'proficiencies', $id);
            // Владение бинарное; фиктивный уровень I не добавляется.
            $result[] = ['id' => $id, 'name' => $definition['name'], 'definition' => $definition];
        }
        return $result;
    }

    private function perks(array $rows, array $definitions, int $characterId): array
    {
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $this->owned($row, $characterId);
            $id = $this->integer($row['perk_id'], 'Перк', 1);
            $this->unique($seen, $id, 'Перк');
            $sourceId = $this->integer($row['source_id'], 'Источник перка', 1);
            $definition = $this->definition($definitions, 'perks', $id);
            $result[] = [
                'id' => $id, 'grant_id' => $this->integer($row['id'], 'Выдача перка', 1),
                'name' => $definition['name'], 'definition' => $definition,
                'rarity' => $row['rarity_override'] ?? $definition['rarity'],
                'source' => [
                    'id' => $sourceId, 'definition' => $this->definition($definitions, 'sources', $sourceId),
                    'context' => $this->context($row['source_context']),
                ],
            ];
        }
        return $result;
    }

    private function spells(array $rows, array $definitions, int $characterId): array
    {
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $this->owned($row, $characterId);
            $id = $this->integer($row['spell_id'], 'Заклинание', 1);
            $this->unique($seen, $id, 'Заклинание');
            $definition = $this->definition($definitions, 'spells', $id);
            $result[] = [
                'id' => $id, 'grant_id' => $this->integer($row['id'], 'Изученное заклинание', 1),
                'name' => $definition['name'], 'definition' => $definition,
                'path' => $this->definition($definitions, 'paths', $definition['magic_path_id']),
            ];
        }
        return $result;
    }

    private function inventory(array $rows, array $definitions, int $characterId): array
    {
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $this->owned($row, $characterId);
            $id = $this->integer($row['id'], 'Экземпляр предмета', 1);
            $this->unique($seen, $id, 'Экземпляр предмета');
            $itemId = $this->integer($row['item_id'], 'Предмет', 1);
            $definition = $this->definition($definitions, 'items', $itemId);
            $quantity = $this->integer($row['quantity'], 'Количество', 1);
            $equipped = $this->boolean($row['equipped']);
            if ($equipped && $quantity !== 1) {
                throw new \DomainException('Экипированный предмет должен быть отдельным экземпляром.');
            }
            $recordedWeight = $definition['weight'] ?? 0;
            $weight = CalculatedValue::number($recordedWeight, true);
            // Округление не должно скрыть небольшой отрицательный вес.
            if ($recordedWeight < 0) {
                throw new \DomainException('Отрицательный вес предмета.');
            }
            $result[] = [
                'id' => $id, 'item_id' => $itemId, 'name' => $definition['name'],
                'quantity' => $quantity, 'equipped' => $equipped, 'weight' => $weight,
                'definition' => $definition,
                'type' => $this->definition($definitions, 'item-types', $definition['item_type_id']),
            ];
        }
        return $result;
    }

    /** Балансы не участвуют в расчёте максимума маны/урона. Храним числа отдельно от выдач. */
    private function balances(array $rows, int $characterId): array
    {
        $result = [];
        foreach ($rows as $row) {
            $this->owned($row, $characterId);
            $key = $row['resource_key'];
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $key) || isset($result[$key])) {
                throw new \DomainException('Некорректный или повторный баланс.');
            }
            $amount = $this->integer($row['amount'], 'Баланс', 0);
            if ($amount > 9007199254740991) throw new \DomainException('Баланс превышает безопасный предел.');
            $result[$key] = $amount;
        }
        if (isset($result['experience_active'], $result['experience_total'])
            && $result['experience_active'] > $result['experience_total']) {
            throw new \DomainException('Активный опыт не может превышать накопленный.');
        }
        return $result;
    }

    /** Права привязаны к реальному порогу. Эффекты характеристик не добавляют новых строк. */
    private function choices(array $rows, int $characterId): array
    {
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $this->owned($row, $characterId);
            $row['id'] = $this->integer($row['id'], 'Право выбора', 1);
            $row['character_id'] = $characterId;
            $row['attribute_id'] = $this->integer($row['attribute_id'], 'Характеристика выбора', 1);
            $row['threshold'] = $this->integer($row['threshold'], 'Порог выбора', 1);
            if (!in_array($row['kind'], ['skill', 'proficiency'], true) || $row['threshold'] >= 25) {
                throw new \DomainException('Некорректный порог или тип выбора.');
            }
            $row['target_id'] = $row['target_id'] === null ? null : $this->integer($row['target_id'], 'Цель выбора', 1);
            $row['target_level'] = $row['target_level'] === null ? null : $this->integer($row['target_level'], 'Уровень выбора', 1);
            $key = $row['attribute_id'].':'.$row['kind'].':'.$row['threshold'];
            if (isset($seen[$key]) || ($row['target_id'] === null && $row['target_level'] !== null)
                || ($row['kind'] === 'proficiency' && $row['target_level'] !== null)
                || ($row['kind'] === 'skill' && $row['target_id'] !== null
                    && ($row['target_level'] === null || $row['target_level'] > 3))) {
                throw new \DomainException('Некорректный или повторный расход выбора.');
            }
            $seen[$key] = true;
            $result[] = $row;
        }
        return $result;
    }

    private function owned(array $row, int $characterId): void
    {
        if ($this->integer($row['character_id'], 'Владелец записи', 1) !== $characterId) {
            throw new \DomainException('В состояние попала запись другого персонажа.');
        }
    }

    private function definition(array $definitions, string $catalog, int $id): array
    {
        if (!isset($definitions[$catalog][$id])) {
            throw new \DomainException('Определение состояния не найдено: '.$catalog.'.');
        }
        return $definitions[$catalog][$id];
    }

    private function unique(array &$seen, int $id, string $label): void
    {
        if (isset($seen[$id])) {
            throw new \DomainException($label.' повторяется в состоянии персонажа.');
        }
        $seen[$id] = true;
    }

    /** PDO может вернуть целое как строку. Произвольный текст и переполнение не превращаем в ноль. */
    private function integer(mixed $value, string $label, int $minimum): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < $minimum || (!is_int($value) && !is_string($value))) {
            throw new \DomainException($label.': некорректное сохранённое число.');
        }
        return $parsed;
    }

    private function boolean(mixed $value): bool
    {
        if (!in_array($value, [true, false, 1, 0, '1', '0'], true)) {
            throw new \DomainException('Некорректный сохранённый переключатель.');
        }
        return (bool) $value;
    }

    private function context(mixed $value): array
    {
        $context = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value;
        if (!is_array($context)) {
            throw new \DomainException('Источник перка должен содержать структурированный контекст.');
        }
        return $context;
    }
}
