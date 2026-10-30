<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Transfer;

use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Items\LiteratureContent;

/**
 * Единственное описание переносимых связей. В файле *_key / *_keys, в редакторе *_id / *_ids.
 * Звёздочка означает каждый элемент списка. Новую связь добавляй сюда, а не в UI импорта.
 */
final class CatalogJsonReferences
{
    private const PATHS = [
        'skills' => ['attribute_id' => 'attributes', 'levels.*.perk_id' => 'perks'],
        'proficiencies' => ['attribute_id' => 'attributes'],
        'perks' => ['effects.*.attribute_id' => 'attributes', 'effects.*.skill_id' => 'skills'],
        'spells' => ['magic_path_id' => 'paths'],
        'items' => ['item_type_id' => 'item-types', 'melee_proficiency_id' => 'proficiencies',
            'ranged_proficiency_id' => 'proficiencies', 'magic_proficiency_id' => 'proficiencies',
            'effects.*.attribute_id' => 'attributes', 'effects.*.skill_id' => 'skills'],
        'races' => ['rules.fixed_perk_ids.*' => 'perks', 'rules.perk_choice_ids.*' => 'perks',
            'rules.fixed_spell_ids.*' => 'spells', 'rules.spell_choice_ids.*' => 'spells',
            'rules.spell_choice_path_ids.*' => 'paths', 'rules.proficiency_choice_ids.*' => 'proficiencies'],
    ];

    public function convert(string $catalog, array $fields, bool $import, callable $resolve): array
    {
        foreach (self::PATHS[$catalog] ?? [] as $path => $target) {
            $this->walk($fields, explode('.', $path), $import, fn ($value) => $resolve($target, $value));
        }
        if ($catalog === 'items' && array_key_exists('literature_contents', $fields)) {
            if (!is_array($fields['literature_contents']) || !array_is_list($fields['literature_contents'])) {
                $this->invalid('Укажите содержимое литературы списком');
            }
            foreach ($fields['literature_contents'] as &$entry) {
                if (!is_array($entry)) $this->invalid('Проверьте запись содержимого литературы');
                $target = LiteratureContent::catalog($entry['type'] ?? null);
                $this->walk($entry, ['knowledge_id'], $import, fn ($value) => $resolve($target, $value));
            }
            unset($entry);
        }
        // Эти связи уже хранятся ключами, но нужны для включения зависимостей в экспорт.
        if (!$import) {
            $attributes = $catalog === 'races'
                ? [...array_keys((array) ($fields['rules']['fixed_bonuses'] ?? [])), ...($fields['rules']['choice_attributes'] ?? [])]
                : ($catalog === 'sources' ? array_keys((array) ($fields['attribute_icons'] ?? [])) : []);
            foreach (array_unique($attributes) as $key) $resolve('attributes', $key, true);
        }
        return $fields;
    }

    private function walk(array &$node, array $path, bool $import, callable $resolve): void
    {
        $segment = array_shift($path);
        if ($segment === '*') {
            if (!array_is_list($node)) $this->invalid('Укажите связи списком');
            foreach ($node as &$value) {
                if ($path) {
                    if (!is_array($value)) $this->invalid('Проверьте параметры записи связи');
                    $this->walk($value, $path, $import, $resolve);
                } elseif ($value !== null) $value = $resolve($value);
            }
            return;
        }
        $external = preg_replace('/_ids$/', '_keys', preg_replace('/_id$/', '_key', $segment));
        $source = $import ? $external : $segment;
        $destination = $import ? $segment : $external;
        if ($import && $source !== $destination && array_key_exists($destination, $node)) {
            $this->invalid('В поле '.$source.' укажите системный ключ записи вместо числового ID');
        }
        if (!array_key_exists($source, $node)) return;
        $value = $node[$source];
        if ($path) {
            if (!is_array($value)) $this->invalid('Укажите поле '.$source.' в виде объекта или списка');
            $this->walk($value, $path, $import, $resolve);
        } elseif ($value !== null) $value = $resolve($value);
        if ($source !== $destination) unset($node[$source]);
        $node[$destination] = $value;
    }

    private function invalid(string $message): never { throw new ApiProblem('invalid_catalog_json', $message); }
}
