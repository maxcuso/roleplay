<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Shared;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\Access;
use Maxcuso\Roleplay\Api\ApiProblem;

/** Хранение определений. Все имена таблиц приходят из закрытого списка кода. */
final class DefinitionStore
{
    public const TABLES = ['attributes', 'races', 'magic_paths', 'item_types', 'perk_sources', 'perks', 'spells', 'items', 'skills', 'weapon_proficiencies', 'ranks'];

    public function __construct(private ConnectionInterface $db, private CatalogRevision $revision) {}

    private function table(string $name): string
    {
        if (!in_array($name, self::TABLES, true)) throw new \LogicException('Неизвестный справочник.');
        return 'roleplay_'.$name;
    }

    public function list(string $name, User $actor, string $search = '', ?bool $enabled = null,
        int $page = 1, ?callable $filter = null): array
    {
        Access::admin($actor);
        return $this->revision->read(function (int $revision) use ($name, $search, $enabled, $page, $filter): array {
            $query = $this->db->table($this->table($name));
            if (trim($search) !== '') {
                // Спецсимволы поиска ищутся как текст, а не шаблон SQL.
                $literal = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($search), 0, 100));
                $query->where('name', 'like', '%'.$literal.'%');
            }
            if ($enabled !== null) $query->where('enabled', $enabled);
            if ($filter !== null) $filter($query);
            $page = max(1, min($page, 100000));
            return [
                'revision' => $revision,
                'total' => $query->count(),
                'page' => $page,
                'per_page' => 30,
                'rows' => $query->orderBy('sort_order')->orderBy('id')->offset(($page - 1) * 30)->limit(30)
                    ->get()->map(fn ($row) => (array) $row)->all(),
            ];
        });
    }

    public function save(string $name, ?int $id, int $revision, array $input, User $actor, callable $validate, ?callable $saveRelated = null): array
    {
        // Только создание трёх выдаваемых сущностей. Правка, удаление и переключение статуса остаются административными.
        if ($id === null && in_array($name, ['perks', 'spells', 'items'], true)) Access::catalogCreate($actor);
        else Access::admin($actor);
        return $this->revision->write($revision, function () use ($name, $id, $input, $validate, $saveRelated): array {
            $table = $this->table($name);
            if ($id !== null) $this->requireEntry($name, $id);
            // Ссылки проверяются под той же блокировкой, что и сама запись.
            $values = $validate($input);
            $now = gmdate('Y-m-d H:i:s');
            if ($id === null) {
                $base = DefinitionSlug::fromName($values['name'], 'entry');
                $slug = $base;
                $suffix = 2;
                while ($this->db->table($table)->where('slug', $slug)->exists()) $slug = $base.'-'.$suffix++;
                $id = $this->db->table($table)->insertGetId($values + [
                    'slug' => $slug, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                // Переименование не меняет стабильный системный ключ.
                $this->db->table($table)->where('id', $id)->update($values + ['updated_at' => $now]);
            }
            // Связанные строки сохраняются до завершения общей транзакции и публикации версии.
            if ($saveRelated !== null) $saveRelated((int) $id);
            return ['id' => (int) $id];
        });
    }

    public function toggle(string $name, int $id, int $revision, bool $enabled, User $actor, ?callable $beforeToggle = null): array
    {
        Access::admin($actor);
        return $this->revision->write($revision, function () use ($name, $id, $enabled, $beforeToggle): array {
            $this->requireEntry($name, $id);
            if ($beforeToggle !== null) $beforeToggle();
            $this->db->table($this->table($name))->where('id', $id)->update([
                'enabled' => $enabled, 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return ['id' => $id];
        });
    }

    public function remove(string $name, int $id, int $revision, User $actor): array
    {
        Access::admin($actor);
        return $this->revision->write($revision, function () use ($name, $id): array {
            $this->requireEntry($name, $id);
            (new CatalogUsage($this->db))->assertUnused($name, $id);
            $this->db->table($this->table($name))->where('id', $id)->delete();
            return ['id' => $id];
        });
    }

    /** Для проверок и селекторов; метод не является отдельным публичным API. */
    public function references(string $name, ?array $ids = null): array
    {
        $query = $this->db->table($this->table($name));
        if ($ids !== null) {
            foreach ($ids as $id) {
                FieldValues::integer($id, 'ID определения', 1, PHP_INT_MAX);
            }
            $query->whereIn('id', $ids);
        }
        return $query->orderBy('sort_order')->orderBy('id')
            ->get()->map(fn ($row) => (array) $row)->all();
    }

    public function requireEntry(string $name, int $id): void
    {
        if ($id < 1 || !$this->db->table($this->table($name))->where('id', $id)->exists()) {
            throw new ApiProblem('catalog_entry_not_found', 'Запись не найдена.', 404);
        }
    }

    public function requireIds(string $name, array $ids): array
    {
        if (!array_is_list($ids) || count($ids) > 1000) FieldValues::invalid('Некорректный список выбранных записей.');
        foreach ($ids as $id) FieldValues::integer($id, 'Выбранная запись', 1, PHP_INT_MAX);
        $ids = array_values(array_unique($ids));
        if ($this->db->table($this->table($name))->whereIn('id', $ids)->count() !== count($ids)) {
            FieldValues::invalid('Одна из выбранных записей больше не существует.');
        }
        return $ids;
    }
}
