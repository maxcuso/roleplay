<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Черновик хранит намерения, а не присланные игроком бонусы, цены или итоговые выдачи. */
final class CreationPayload
{
    public static function empty(): array
    {
        return [
            'first_name' => '', 'last_name' => '', 'age' => null, 'race_id' => null,
            'base_attributes' => [], 'racial_attributes' => [], 'skills' => [], 'proficiencies' => [],
            'perk_ids' => [], 'spell_ids' => [], 'racial_spell_ids' => [], 'racial_proficiency_ids' => [], 'loadout' => [],
        ];
    }

    /** Незавершённые поля допустимы при сохранении, но не при окончательном создании. */
    public function normalize(mixed $input): array
    {
        try {
            if (!is_array($input) || strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 65536) {
                FieldValues::invalid('Некорректный или слишком большой черновик.');
            }
            FieldValues::allowed($input, array_keys(self::empty()));
            $input += self::empty();
            $result = [
                'first_name' => FieldValues::text($input['first_name'], 'Имя') ?? '',
                'last_name' => FieldValues::text($input['last_name'], 'Фамилия') ?? '',
                'age' => FieldValues::integer($input['age'], 'Возраст', 0, 1000000, true),
                'race_id' => FieldValues::integer($input['race_id'], 'Раса', 1, PHP_INT_MAX, true),
                'base_attributes' => [], 'loadout' => [],
            ];
            if (!is_array($input['base_attributes']) || count($input['base_attributes']) > 4) {
                FieldValues::invalid('Нужны значения четырёх характеристик.');
            }
            foreach ($input['base_attributes'] as $slug => $value) {
                $this->key($slug);
                $result['base_attributes'][$slug] = FieldValues::integer($value, 'Характеристика', 1, 25, true);
            }
            $result['racial_attributes'] = $this->list($input['racial_attributes'], false);
            // Повтор навыка означает последовательное повышение; остальные списки уникальны.
            foreach (['skills', 'proficiencies', 'perk_ids', 'spell_ids', 'racial_spell_ids', 'racial_proficiency_ids'] as $field) {
                $result[$field] = $this->list($input[$field], true, $field !== 'skills');
            }
            if (!is_array($input['loadout']) || count($input['loadout']) > 100) {
                FieldValues::invalid('Некорректный выбор стартовых наборов.');
            }
            foreach ($input['loadout'] as $key => $selected) {
                $this->key($key);
                $result['loadout'][$key] = $this->list($selected, false);
            }
            return $result;
        } catch (ApiProblem $problem) {
            throw new ApiProblem('invalid_creation_payload', $problem->getMessage());
        }
    }

    private function list(mixed $value, bool $numeric, bool $unique = true): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            FieldValues::invalid('Выбор должен быть списком не более 100 записей.');
        }
        foreach ($value as $entry) {
            if ($numeric) {
                FieldValues::integer($entry, 'Выбранная запись', 1, PHP_INT_MAX);
            } else {
                $this->key($entry);
            }
        }
        if ($unique && count(array_unique($value)) !== count($value)) {
            FieldValues::invalid('Выбранная запись повторяется.');
        }
        return $value;
    }

    private function key(mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_-]{0,99}$/D', $value)) {
            FieldValues::invalid('Некорректный ключ выбора.');
        }
    }
}