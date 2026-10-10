<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Skills;

use Flarum\User\User;
use Maxcuso\Roleplay\Catalog\Shared\AttributeBoundCatalog;
use Maxcuso\Roleplay\Catalog\Shared\DefinitionStore;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Перк выбирается по ID. Переименование перка не меняет привязку к уровню. */
final class SkillCatalog extends AttributeBoundCatalog
{
    protected const TABLE = 'skills';

    public function __construct(DefinitionStore $store, private SkillLevelStore $levels)
    {
        parent::__construct($store);
    }

    public function validate(array $input): array
    {
        FieldValues::allowed($input, ['name', 'description', 'enabled', 'sort_order', 'attribute_id', 'levels']);
        $fields = $this->definitionFields($input);
        $levels = $this->validateLevels($input['levels'] ?? []);
        if ($fields['enabled']) {
            $this->requireComplete($levels);
        }
        return $fields + ['levels' => $levels];
    }

    public function save(?int $id, int $revision, array $input, User $actor): array
    {
        // Привязки не являются SQL-полем навыка. Извлекаем их после проверки под блокировкой.
        $levels = [];
        return $this->store->save(static::TABLE, $id, $revision, $input, $actor,
            function (array $input) use (&$levels): array {
                $fields = $this->validate($input);
                $levels = $fields['levels'];
                unset($fields['levels']);
                return $fields;
            },
            function (int $skillId) use (&$levels): void {
                $this->levels->replace($skillId, $levels);
            });
    }

    public function toggle(int $id, int $revision, bool $enabled, User $actor): array
    {
        return $this->store->toggle(static::TABLE, $id, $revision, $enabled, $actor,
            function () use ($id, $enabled): void {
                if ($enabled) {
                    $this->requireComplete($this->levels->forSkills([$id])[$id] ?? []);
                }
            });
    }

    public function presentMany(array $rows): array
    {
        $rows = parent::presentMany($rows);
        $levels = $this->levels->forSkills(array_column($rows, 'id'));
        foreach ($rows as &$row) {
            $row['levels'] = $levels[$row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    private function validateLevels(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > 3) {
            FieldValues::invalid('У навыка должны быть уровни I, II и III.');
        }
        $selected = [];
        foreach ($input as $row) {
            if (!is_array($row)) {
                FieldValues::invalid('Некорректная привязка уровня навыка.');
            }
            FieldValues::allowed($row, ['level', 'perk_id']);
            $level = FieldValues::integer($row['level'] ?? null, 'Уровень навыка', 1, 3);
            if (array_key_exists($level, $selected)) {
                FieldValues::invalid('Уровень навыка повторяется.');
            }
            $selected[$level] = FieldValues::integer($row['perk_id'] ?? null, 'Перк', 1, PHP_INT_MAX, true);
        }
        $perkIds = array_values(array_filter($selected, fn ($value) => $value !== null));
        $this->store->requireIds('perks', $perkIds);
        // Выключенный навык можно сохранить незаполненным. API всегда возвращает все три уровня.
        $result = [];
        foreach ([1, 2, 3] as $level) {
            $result[] = ['level' => $level, 'perk_id' => $selected[$level] ?? null];
        }
        return $result;
    }

    private function requireComplete(array $levels): void
    {
        if (count($levels) !== 3 || in_array(null, array_column($levels, 'perk_id'), true)) {
            FieldValues::invalid('Перед включением навыка выбери перки для уровней I, II и III.');
        }
    }
}