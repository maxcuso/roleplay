<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\Access;
use Maxcuso\Roleplay\Catalog\Skills\SkillLevelStore;

/** Явное начальное наполнение: существующие определения и привязки не изменяются. */
final class SeedBaseCatalogs
{
    public function __construct(private ConnectionInterface $db) {}

    public function run(User $actor): int
    {
        Access::admin($actor);
        $definitions = require __DIR__.'/../../resources/defaults/base-catalogs.php';
        return $this->db->transaction(function () use ($definitions): int {
            $this->db->table('roleplay_catalog_state')->where('id', 1)->lockForUpdate()->first();
            $changed = 0;
            $now = gmdate('Y-m-d H:i:s');
            foreach ($definitions['attributes'] as $slug => $fields) {
                if ($this->db->table('roleplay_attributes')->where('slug', $slug)->exists()) {
                    continue;
                }
                $this->insert('attributes', $slug, $fields, $now);
                $changed++;
            }

            $attributes = $this->db->table('roleplay_attributes')->pluck('id', 'slug')->all();
            $levels = new SkillLevelStore($this->db);
            foreach (['skills', 'weapon_proficiencies'] as $table) {
                foreach ($definitions[$table] as $slug => $fields) {
                    if ($this->db->table('roleplay_'.$table)->where('slug', $slug)->exists()) {
                        continue;
                    }
                    $attribute = $fields['attribute'];
                    unset($fields['attribute']);
                    $fields['attribute_id'] = (int) $attributes[$attribute];
                    // Навыки выключены до заполнения трёх привязок; у владений такой подготовки нет.
                    $fields['enabled'] = $table !== 'skills';
                    $id = $this->insert($table, $slug, $fields, $now);
                    if ($table === 'skills') {
                        $levels->replace($id, [
                            ['level' => 1, 'perk_id' => null],
                            ['level' => 2, 'perk_id' => null],
                            ['level' => 3, 'perk_id' => null],
                        ]);
                    }
                    $changed++;
                }
            }
            if ($changed > 0) {
                $this->db->table('roleplay_catalog_state')->where('id', 1)
                    ->increment('revision', 1, ['updated_at' => $now]);
            }
            return $changed;
        });
    }

    /** Имя таблицы передаётся только из закрытого списка выше, не из пользовательского запроса. */
    private function insert(string $table, string $slug, array $fields, string $now): int
    {
        return (int) $this->db->table('roleplay_'.$table)->insertGetId($fields + [
            'slug' => $slug, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}