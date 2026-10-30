<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Attributes;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\Access;
use Maxcuso\Roleplay\Catalog\Shared\{CatalogRegistry, CatalogRevision, FieldValues};

/** Общая строка настроек меняет только иконки; имена, ключи и игровые значения сохраняются. */
final class AttributeIcons
{
    public function __construct(private ConnectionInterface $db) {}

    public function read(User $actor): array
    {
        Access::admin($actor);
        return (new CatalogRevision($this->db))->read(fn (int $revision) => [
            'revision' => $revision,
            'rows' => array_map(fn ($row) => array_intersect_key($row, array_flip(['id', 'name', 'icon'])),
                (new CatalogRegistry($this->db))->definitionsFor('attributes')),
        ]);
    }

    public function save(User $actor, int $revision, array $rows): array
    {
        Access::admin($actor);
        return (new CatalogRevision($this->db))->write($revision, function () use ($rows): array {
            if (!array_is_list($rows) || count($rows) > 100) FieldValues::invalid('Не удалось сохранить иконки: ожидается список не более чем для 100 характеристик. Попробуйте обновить страницу');
            $changes = [];
            foreach ($rows as $row) {
                if (!is_array($row)) FieldValues::invalid('Не удалось обработать настройку иконки. Попробуйте обновить страницу');
                FieldValues::allowed($row, ['id', 'icon']);
                $id = FieldValues::integer($row['id'] ?? null, 'Характеристика', 1, PHP_INT_MAX);
                if (array_key_exists($id, $changes)) FieldValues::invalid('Характеристика указана несколько раз. Уберите повторную запись');
                $changes[$id] = FieldValues::icon($row['icon'] ?? null);
            }
            $found = $this->db->table('roleplay_attributes')->whereIn('id', array_keys($changes))->count();
            if ($found !== count($changes)) FieldValues::invalid('Одна из характеристик больше не существует');
            // Все значения проверены до первой записи; ошибочная иконка отменяет всю форму.
            foreach ($changes as $id => $icon) $this->db->table('roleplay_attributes')->where('id', $id)
                ->update(['icon' => $icon, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            return ['updated' => count($changes)];
        });
    }
}
