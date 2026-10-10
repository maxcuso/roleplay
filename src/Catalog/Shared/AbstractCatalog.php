<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Shared;

use Flarum\User\User;
use Illuminate\Database\Query\Builder;

/** Общие операции. Потомки задают свою таблицу, поля и фильтры; игровых расчётов здесь нет. */
abstract class AbstractCatalog implements CatalogService
{
    protected const TABLE = '';
    protected const JSON_FIELDS = [];
    protected const INTEGER_FIELDS = [];
    protected const BOOLEAN_FIELDS = [];
    protected const DECIMAL_FIELDS = [];

    public function __construct(protected DefinitionStore $store) {}

    public function list(User $actor, string $search = '', ?bool $enabled = null, int $page = 1, array $filters = []): array
    {
        $result = $this->store->list(static::TABLE, $actor, $search, $enabled, $page,
            fn (Builder $query) => $this->applyFilters($query, $filters));
        $result['rows'] = $this->presentMany($result['rows']);
        return $result;
    }

    public function save(?int $id, int $revision, array $input, User $actor): array
    {
        return $this->store->save(static::TABLE, $id, $revision, $input, $actor, $this->validate(...));
    }

    public function toggle(int $id, int $revision, bool $enabled, User $actor): array
    {
        return $this->store->toggle(static::TABLE, $id, $revision, $enabled, $actor);
    }

    public function remove(int $id, int $revision, User $actor): array
    {
        return $this->store->remove(static::TABLE, $id, $revision, $actor);
    }

    /** Пакетная подготовка нужна, когда у справочника есть связанные строки. */
    public function presentMany(array $rows): array
    {
        return array_map($this->present(...), $rows);
    }

    public function present(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['enabled'] = (bool) $row['enabled'];
        $row['sort_order'] = (int) $row['sort_order'];
        foreach (static::INTEGER_FIELDS as $field) {
            if ($row[$field] !== null) $row[$field] = (int) $row[$field];
        }
        foreach (static::BOOLEAN_FIELDS as $field) $row[$field] = (bool) $row[$field];
        foreach (static::DECIMAL_FIELDS as $field) {
            if ($row[$field] !== null) $row[$field] = (float) $row[$field];
        }
        foreach (static::JSON_FIELDS as $field) {
            $row[$field] = json_decode($row[$field], true, flags: JSON_THROW_ON_ERROR);
        }
        return $row;
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        FieldValues::allowed($filters, []);
    }

    abstract public function validate(array $input): array;
}
