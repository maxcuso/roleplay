<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;

/** Проверки консолидации на MariaDB: сравнение структуры независимо от префикса и имён FK. */
final class SchemaSnapshot
{
    public static function tables(ConnectionInterface $db): array
    {
        $prefix = $db->getTablePrefix();
        $tables = [];
        foreach ($db->select('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?', ['BASE TABLE']) as $row) {
            if (!str_starts_with($row->name, $prefix)) continue;
            $name = substr($row->name, strlen($prefix));
            if (str_starts_with($name, 'roleplay_') || in_array($name, ['quest_events', 'quest_event_participants'], true)) $tables[] = $name;
        }
        sort($tables);
        return $tables;
    }

    public static function structure(ConnectionInterface $db): array
    {
        $schema = $db->getSchemaBuilder();
        $result = [];
        foreach (self::tables($db) as $name) {
            $table = $db->getQueryGrammar()->wrapTable($name);
            $columns = [];
            foreach ($db->select('SHOW FULL COLUMNS FROM '.$table) as $column) {
                $columns[$column->Field] = [
                    'type' => $column->Type, 'nullable' => $column->Null,
                    'default' => $column->Default, 'extra' => $column->Extra, 'collation' => $column->Collation,
                ];
            }
            ksort($columns);
            $indexes = [];
            foreach ($schema->getIndexes($name) as $index) {
                $indexes[] = ['columns' => $index['columns'], 'unique' => $index['unique'], 'primary' => $index['primary'], 'type' => $index['type']];
            }
            $foreign = [];
            foreach ($schema->getForeignKeys($name) as $key) {
                $foreign[] = [
                    'columns' => $key['columns'],
                    'table' => preg_replace('/^'.preg_quote($db->getTablePrefix(), '/').'/', '', $key['foreign_table']),
                    'foreign_columns' => $key['foreign_columns'], 'on_update' => $key['on_update'], 'on_delete' => $key['on_delete'],
                ];
            }
            $create = array_values((array) $db->selectOne('SHOW CREATE TABLE '.$table))[1];
            preg_match_all('/CHECK\s*\((.*)\),?\s*$/m', $create, $matches);
            $checks = $matches[1];
            foreach ([&$indexes, &$foreign, &$checks] as &$entries) {
                usort($entries, fn ($left, $right) => strcmp(json_encode($left), json_encode($right)));
            }
            unset($entries);
            $result[$name] = compact('columns', 'indexes', 'foreign', 'checks');
        }
        return $result;
    }

    public static function rows(ConnectionInterface $db, string $table): array
    {
        $query = $db->table($table);
        $indexes = $db->getSchemaBuilder()->getIndexes($table);
        $order = null;
        foreach ($indexes as $index) if ($index['primary']) $order = $index['columns'];
        if ($order === null) foreach ($indexes as $index) if ($index['unique']) { $order = $index['columns']; break; }
        foreach ($order ?? $db->getSchemaBuilder()->getColumnListing($table) as $column) $query->orderBy($column);
        return array_map(fn ($row) => (array) $row, $query->get()->all());
    }

    public static function hashes(ConnectionInterface $db): array
    {
        $result = [];
        foreach (self::tables($db) as $table) $result[$table] = hash('sha256', json_encode(self::rows($db, $table), JSON_THROW_ON_ERROR));
        return $result;
    }
}
