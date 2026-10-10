<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Transfer;

use Illuminate\Database\ConnectionInterface;

/** Только чтение прежней схемы. Никаких ALTER, UPDATE и переключения расширения.
 * Отчёт намеренно содержит количество/ID проблем, без имён игроков, текстов анкет и секретов форума.
 */
final class LegacyInspector
{
    private const TABLES = [
        'catalog_state', 'attributes', 'races', 'race_attribute_bonuses', 'rules', 'skills', 'weapon_proficiencies',
        'magic_paths', 'spells', 'item_types', 'perk_sources', 'perks', 'items', 'currencies',
        'loadout_groups', 'loadout_options', 'loadout_option_items', 'characters', 'character_attributes',
        'character_skills', 'character_weapon_proficiencies', 'character_perks', 'character_spells', 'character_items',
        'character_currencies', 'character_choices', 'character_checks', 'calculated_stats', 'operations', 'history',
        'user_states', 'character_drafts',
    ];
    public function __construct(private readonly ConnectionInterface $db) {}

    public function inspect(): array
    {
        $schema = $this->db->getSchemaBuilder();
        $tables = [];
        $prefix = $this->db->getTablePrefix().'roleplay_';
        // Системная таблица не должна получать префикс игровых таблиц.
        $all = array_map(fn ($row) => (string) $row->table_name, $this->db->select(
            'SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [$this->db->getDatabaseName()]));
        $suffixes = array_map(fn (string $name) => substr($name, strlen($prefix)),
            array_filter($all, fn (string $name) => str_starts_with($name, $prefix)));
        sort($suffixes);
        foreach ($suffixes as $suffix) {
            $name = 'roleplay_'.$suffix;
            if (!$schema->hasTable($name)) continue;
            $tables[$suffix] = ['rows' => $this->db->table($name)->count(), 'columns' => $schema->getColumnListing($name)];
        }
        $issues = [];
        $unmapped = array_values(array_diff($suffixes, self::TABLES));
        if ($unmapped) $issues[] = ['code' => 'additional_legacy_tables', 'tables' => $unmapped,
            'message' => 'Дополнительные таблицы тоже входят в исходный архив. Для полного переноса требуется явная карта их данных.'];
        if (isset($tables['perk_sources'])) {
            $startingIds = $this->db->table('roleplay_perk_sources')->where('slug', 'starting')->pluck('id')->all();
            if ($startingIds) $issues[] = ['code' => 'obsolete_starting_source', 'ids' => $startingIds,
                'message' => 'Источник «Создание персонажа» не переносится как действующий. Выдачи должны получить расовый, навыковый или другой настоящий источник.'];
        }
        if (isset($tables['character_perks'])) {
            $duplicates = $this->db->table('roleplay_character_perks')->select('character_id', 'perk_id')
                ->groupBy('character_id', 'perk_id')->havingRaw('COUNT(*) > 1')->get()->map(fn ($row) => (array) $row)->all();
            if ($duplicates) $issues[] = ['code' => 'duplicate_character_perks', 'pairs' => $duplicates,
                'message' => 'У персонажа один перк должен иметь одну выдачу и один значимый источник.'];
        }
        if (isset($tables['character_items'])) {
            $columns = $tables['character_items']['columns'];
            if (in_array('custom_name', $columns, true)) {
                $ids = $this->db->table('roleplay_character_items')->whereNotNull('custom_name')->orWhereNotNull('custom_description')->pluck('id')->all();
                if ($ids) $issues[] = ['code' => 'custom_item_instances', 'ids' => $ids,
                    'message' => 'Индивидуальные подписи/описания предметов требуют сохранения отдельного контекста, их нельзя молча удалить.'];
            }
        }
        if (($tables['characters']['rows'] ?? 0) > 0) {
            $issues[] = ['code' => 'character_transfer_scope',
                'message' => 'До записи переноса требуется выбрать: только справочники или также персонажи, балансы, выдачи и история.'];
        }
        return ['generated_at' => gmdate(DATE_ATOM), 'read_only' => true, 'prefix' => $this->db->getTablePrefix(),
            'tables' => $tables, 'issues' => $issues,
            'strategies' => [
                'catalogs_only' => 'Перенести определения и стартовые настройки; прежние анкеты оставить в резервной копии.',
                'complete' => 'Дополнительно преобразовать анкеты, источники выдач, владения, стопки, балансы и права выбора; сохранить прежнюю историю.',
            ]];
    }
}