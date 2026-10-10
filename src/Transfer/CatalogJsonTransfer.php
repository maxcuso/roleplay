<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Transfer;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\{Access, ApiProblem};
use Maxcuso\Roleplay\Catalog\Shared\{CatalogRegistry, CatalogRevision, FieldValues};

/**
 * Регулярный обмен справочниками, в отличие от разового LegacyCatalogPlan.
 * Предпросмотр выполняет те же сохранения, но всегда откатывается. Применение — одна транзакция.
 * Персонажи, выдачи, конфигурация создания и история никогда не входят в этот формат.
 */
final class CatalogJsonTransfer
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const MAX_RECORDS = 5000;
    // Сначала резервируем идентичности. Это позволяет переносить цикл навык → перк → эффект → навык.
    private const RESERVE_ORDER = ['attributes', 'paths', 'item-types', 'sources', 'perks', 'skills',
        'proficiencies', 'spells', 'items', 'races', 'ranks'];
    private const SAVE_ORDER = ['attributes', 'paths', 'item-types', 'sources', 'proficiencies',
        'perks', 'skills', 'spells', 'items', 'races', 'ranks'];

    private CatalogRegistry $registry;
    private CatalogJsonReferences $references;

    public function __construct(private ConnectionInterface $db)
    {
        $this->registry = new CatalogRegistry($db);
        $this->references = new CatalogJsonReferences();
    }

    public function export(User $actor, string $catalog, string $scope, array $params = []): array
    {
        Access::admin($actor);
        $this->registry->get($catalog);
        if (!in_array($scope, ['section', 'filtered', 'all'], true)) $this->invalid('Неизвестный объём экспорта.');
        return (new CatalogRevision($this->db))->read(function () use ($actor, $catalog, $scope, $params): array {
            $snapshot = $this->snapshot();
            $selected = [];
            $queue = [];
            if ($scope === 'all') {
                foreach ($snapshot as $type => $rows) foreach ($rows as $row) $queue[] = [$type, $row['id']];
            } elseif ($scope === 'section') {
                foreach ($snapshot[$catalog] as $row) $queue[] = [$catalog, $row['id']];
            } else {
                [$search, $enabled, , $filters] = \Maxcuso\Roleplay\Api\CatalogRequest::listing($params);
                // Не выгружаем только открытую страницу: обходим весь результат фильтра на сервере.
                for ($page = 1; ; ++$page) {
                    $result = $this->registry->get($catalog)->list($actor, $search, $enabled, $page, $filters);
                    foreach ($result['rows'] as $row) $queue[] = [$catalog, $row['id']];
                    if ($page * $result['per_page'] >= $result['total']) break;
                }
            }
            $byKey = [];
            foreach ($snapshot as $type => $rows) foreach ($rows as $row) $byKey[$type][$row['slug']] = $row['id'];
            for ($index = 0; $index < count($queue); ++$index) {
                [$type, $id] = $queue[$index];
                if (isset($selected[$type][$id])) continue;
                $row = $snapshot[$type][$id] ?? null;
                if (!$row) $this->invalid('Не найдена зависимость '.$type.'.');
                $fields = $this->fields($row);
                $fields = $this->references->convert($type, $fields, false,
                    function (string $target, mixed $value, bool $alreadyKey = false) use ($snapshot, $byKey, &$queue): string {
                        $id = $alreadyKey ? ($byKey[$target][$value] ?? null) : $value;
                        $entry = $snapshot[$target][$id] ?? null;
                        if (!$entry) $this->invalid('Не найдена связь '.$target.': '.(string) $value);
                        $queue[] = [$target, $entry['id']];
                        return $entry['slug'];
                    });
                $selected[$type][$id] = ['key' => $row['slug']] + $fields;
                // Расовый выбор из пути подразумевает весь его пул заклинаний, а не только сам путь.
                if ($type === 'races') foreach ($snapshot['spells'] as $spell) {
                    if (in_array($spell['magic_path_id'], $row['rules']['spell_choice_path_ids'] ?? [], true)) {
                        $queue[] = ['spells', $spell['id']];
                    }
                }
            }
            $catalogs = [];
            foreach (CatalogRegistry::keys() as $type) if (isset($selected[$type])) {
                // Сохраняем исходный порядок раздела, независимо от порядка обхода зависимостей.
                $catalogs[$type] = array_values(array_intersect_key($snapshot[$type], $selected[$type]));
                $catalogs[$type] = array_map(fn ($row) => $selected[$type][$row['id']], $catalogs[$type]);
            }
            if (array_sum(array_map('count', $catalogs)) > self::MAX_RECORDS) $this->invalid('В одном файле допускается не более 5000 записей.');
            $document = ['format' => 'roleplay-catalogs', 'version' => 1, 'catalogs' => (object) $catalogs];
            if (strlen(json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) > self::MAX_BYTES) {
                $this->invalid('Экспорт превышает 10 МБ. Выгрузи разделы отдельно.');
            }
            return $document;
        });
    }

    public function import(User $actor, array $document, string $mode, int $revision, bool $apply): array
    {
        Access::admin($actor);
        if (!in_array($mode, ['skip', 'update'], true)) $this->invalid('Неизвестный режим импорта.');
        $catalogs = $this->document($document);
        try {
            return $this->db->transaction(function () use ($actor, $catalogs, $mode, $revision, $apply): array {
                $current = (int) $this->db->table('roleplay_catalog_state')->where('id', 1)->lockForUpdate()->value('revision');
                if ($current !== $revision) throw new ApiProblem('catalog_revision_conflict', 'Справочники изменились. Проверь файл ещё раз.', 409);
                $snapshot = $this->snapshot();
                $ids = [];
                foreach ($snapshot as $type => $rows) foreach ($rows as $row) $ids[$type][$row['slug']] = $row['id'];
                $report = ['revision' => $revision, 'applied' => false, 'new' => 0, 'updated' => 0, 'skipped' => 0,
                    'errors' => [], 'warnings' => [], 'rows' => []];
                $actions = [];
                foreach ($catalogs as $type => $rows) foreach ($rows as $row) {
                    $existing = isset($ids[$type][$row['key']]);
                    $action = $existing ? ($mode === 'update' ? 'update' : 'skip') : 'create';
                    $actions[$type][$row['key']] = $action;
                    ++$report[match ($action) {'create' => 'new', 'update' => 'updated', default => 'skipped'}];
                    $report['rows'][] = ['catalog' => $type, 'key' => $row['key'], 'name' => $row['name'], 'action' => $action];
                    if (!$existing) foreach ($snapshot[$type] as $old) {
                        if (mb_strtolower($old['name']) === mb_strtolower($row['name'])) {
                            $report['warnings'][] = $type.' / '.$row['key'].': название уже есть у '.$old['slug'].', будет создана отдельная запись.';
                            break;
                        }
                    }
                }
                // Временные строки видны только этой транзакции. Ни одна неполная запись не публикуется.
                foreach (self::RESERVE_ORDER as $type) foreach ($catalogs[$type] ?? [] as $row) {
                    if ($actions[$type][$row['key']] !== 'create') continue;
                    try {
                        $ids[$type][$row['key']] = $this->reserve($type, $row, $ids);
                    } catch (ApiProblem $error) { $this->recordError($report, $type, $row, $error); }
                }
                foreach (self::SAVE_ORDER as $type) foreach ($catalogs[$type] ?? [] as $row) {
                    if ($actions[$type][$row['key']] === 'skip' || !isset($ids[$type][$row['key']])) continue;
                    try {
                        $fields = $row;
                        unset($fields['key']);
                        $fields = $this->references->convert($type, $fields, true,
                            fn (string $target, mixed $key) => $this->resolve($ids, $target, $key));
                        $service = $this->registry->get($type);
                        $id = $ids[$type][$row['key']];
                        $current = (int) $this->db->table('roleplay_catalog_state')->where('id', 1)->value('revision');
                        $service->save($id, $current, $fields, $actor);
                    } catch (ApiProblem $error) { $this->recordError($report, $type, $row, $error); }
                }
                if (!$apply || $report['errors']) throw new CatalogJsonRollback($report);
                // Пакет публикует одну общую версию, даже если внутри сохранено много определений.
                $changed = $report['new'] + $report['updated'] > 0;
                $report['revision'] = $revision + (int) $changed;
                $this->db->table('roleplay_catalog_state')->where('id', 1)->update(['revision' => $report['revision']]);
                $report['applied'] = true;
                return $report;
            });
        } catch (CatalogJsonRollback $rollback) { return $rollback->report; }
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (CatalogRegistry::keys() as $key) $snapshot[$key] = array_column($this->registry->definitionsFor($key), null, 'id');
        return $snapshot;
    }

    private function fields(array $row): array
    {
        unset($row['id'], $row['slug'], $row['created_at'], $row['updated_at']);
        return $row;
    }

    private function document(array $document): array
    {
        FieldValues::allowed($document, ['format', 'version', 'catalogs']);
        if (($document['format'] ?? null) !== 'roleplay-catalogs' || ($document['version'] ?? null) !== 1) {
            $this->invalid('Нужен файл roleplay-catalogs версии 1.');
        }
        $catalogs = $document['catalogs'] ?? null;
        if (!is_array($catalogs) || array_diff(array_keys($catalogs), [...CatalogRegistry::keys(), 'effects'])) $this->invalid('Неизвестные разделы в файле.');
        if (strlen(json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > self::MAX_BYTES) $this->invalid('Размер файла — не более 10 МБ.');
        $count = 0;
        foreach ($catalogs as $type => $rows) {
            if (!is_array($rows) || !array_is_list($rows)) $this->invalid($type.': нужен список записей.');
            $keys = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['key'] ?? null)
                    || !preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/D', $row['key'])) $this->invalid($type.': неверный системный ключ записи.');
                if (isset($keys[$row['key']])) $this->invalid($type.' / '.$row['key'].': ключ повторяется в файле.');
                FieldValues::text($row['name'] ?? null, $type.' / '.$row['key'].' / Название', 100, true);
                $keys[$row['key']] = true;
                if (++$count > self::MAX_RECORDS) $this->invalid('В одном файле допускается не более 5000 записей.');
            }
        }
        return (new LegacyEffectDocument())->convert($catalogs);
    }

    /** Минимальные строки соблюдают SQL-ограничения, полный набор полей проверяет обычный save(). */
    private function reserve(string $type, array $row, array $ids): int
    {
        if ($type === 'ranks') {
            $input = $row;
            unset($input['key']);
            $fields = $this->registry->get('ranks')->validate($input);
            if ($this->db->table('roleplay_ranks')->where('level', $fields['level'])->exists()) {
                throw new ApiProblem('rank_level_taken', 'Этот номер ранга уже занят. Для обновления сохраняй системный ключ записи.', 409);
            }
        }
        $extra = match ($type) {
            'skills', 'proficiencies' => ['attribute_id' => $this->resolve($ids, 'attributes', $row['attribute_key'] ?? null)],
            'sources' => ['attribute_icons' => '{}'],
            'spells' => ['magic_path_id' => $this->resolve($ids, 'paths', $row['magic_path_key'] ?? null)],
            'items' => ['item_type_id' => $this->resolve($ids, 'item-types', $row['item_type_key'] ?? null), 'properties' => '[]'],
            'races' => ['rules' => '{}'],
            'ranks' => ['level' => $fields['level'], 'letter' => $fields['letter']],
            default => [],
        };
        if (in_array($type, ['perks', 'items'], true)) $extra['effects'] = '[]';
        return (int) $this->db->table('roleplay_'.CatalogRegistry::tableFor($type))->insertGetId([
            'name' => $row['name'], 'slug' => $row['key'], 'enabled' => false,
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ] + $extra);
    }

    private function resolve(array $ids, string $type, mixed $key): int
    {
        if (!is_string($key) || !isset($ids[$type][$key])) $this->invalid('Не найдена связь '.$type.' / '.(is_scalar($key) ? (string) $key : 'пустой ключ').'.');
        return $ids[$type][$key];
    }

    private function recordError(array &$report, string $type, array $row, ApiProblem $error): void
    {
        $report['errors'][] = $type.' / '.$row['key'].' («'.$row['name'].'»): '.$error->getMessage();
    }

    private function invalid(string $message): never { throw new ApiProblem('invalid_catalog_json', $message); }
}
