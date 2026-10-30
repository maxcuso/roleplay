<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\State;

use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRevision;

/**
 * Чтение перенесено из старых CharacterController, CharacterCards и CharacterStatCalculator.
 * Этот внутренний сервис не проверяет видимость профиля: будущий API обязан сделать это до вызова.
 */
final class CharacterStateReader
{
    // Раздел => [суффикс таблицы состояния, ключ справочника, колонка ссылки].
    // Имена таблиц задаются кодом; пользователь передаёт только числовой ID персонажа.
    private const SECTIONS = [
        'attributes' => ['attributes', 'attributes', 'attribute_id'],
        'skills' => ['skills', 'skills', 'skill_id'],
        'proficiencies' => ['weapon_proficiencies', 'proficiencies', 'proficiency_id'],
        'perks' => ['perks', 'perks', 'perk_id'],
        'spells' => ['spells', 'spells', 'spell_id'],
        'inventory' => ['items', 'items', 'item_id'],
    ];

    private readonly CatalogRevision $revision;
    private readonly CatalogRegistry $catalogs;

    public function __construct(private readonly ConnectionInterface $db)
    {
        $this->revision = new CatalogRevision($db);
        $this->catalogs = new CatalogRegistry($db);
    }

    public function read(int $characterId, ?callable $authorize = null): array
    {
        if ($characterId < 1) {
            throw new ApiProblem('character_not_found', 'Персонаж не найден. Попробуйте обновить страницу', 404);
        }
        return $this->revision->read(function (int $catalogRevision) use ($characterId, $authorize): array {
            // Порядок блокировок: справочники, затем персонаж. Будущие записи соблюдают тот же порядок.
            $character = $this->db->table('roleplay_characters')->where('id', $characterId)->sharedLock()->first();
            if ($character === null) {
                throw new ApiProblem('character_not_found', 'Персонаж не найден. Попробуйте обновить страницу', 404);
            }
            // Проверяем доступ под блокировкой заголовка, до чтения частных выдач.
            if ($authorize !== null) $authorize((array) $character);
            $result = ['catalog_revision' => $catalogRevision, 'character' => (array) $character, 'definitions' => []];
            foreach (self::SECTIONS as $section => [$table, $catalog, $column]) {
                // Экипировка не читается повторно: это флаг у принадлежащего персонажу экземпляра.
                $rows = $this->db->table('roleplay_character_'.$table)->where('character_id', $characterId)
                    ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
                $result[$section] = $rows;
                $result['definitions'][$catalog] = $this->definitions($catalog, array_column($rows, $column));
            }
            $result['balances'] = $this->db->table('roleplay_character_balances')->where('character_id', $characterId)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $result['choices'] = $this->db->table('roleplay_character_choices')->where('character_id', $characterId)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $result['definitions']['races'] = $this->definitions('races', [$character->race_id]);
            $result['definitions']['ranks'] = $this->definitions('ranks', $character->rank_id ? [(int) $character->rank_id] : []);
            $result['definitions']['sources'] = $this->definitions('sources', array_column($result['perks'], 'source_id'));
            $result['definitions']['item-types'] = $this->definitions('item-types',
                array_column($result['definitions']['items'], 'item_type_id'));
            $result['definitions']['paths'] = $this->definitions('paths',
                array_column($result['definitions']['spells'], 'magic_path_id'));
            return $result;
        });
    }

    /** Читаем только нужные определения; их SQL-типы и JSON нормализует существующий код каталогов. */
    private function definitions(string $catalog, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $result = [];
        foreach ($this->catalogs->definitionsFor($catalog, $ids) as $definition) {
            $result[$definition['id']] = $definition;
        }
        return $result;
    }
}
