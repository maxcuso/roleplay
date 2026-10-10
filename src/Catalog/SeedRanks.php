<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\Access;

/** Явное наполнение после миграции: изменения администратора никогда не перезаписываются. */
final class SeedRanks
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function run(User $actor): int
    {
        Access::admin($actor);
        $defaults = require __DIR__.'/../../resources/defaults/ranks.php';
        return $this->db->transaction(function () use ($defaults): int {
            $this->db->table('roleplay_catalog_state')->where('id', 1)->lockForUpdate()->first();
            $changed = 0;
            $now = gmdate('Y-m-d H:i:s');
            foreach ($defaults as $slug => $fields) {
                if ($this->db->table('roleplay_ranks')->where('slug', $slug)->orWhere('level', $fields['level'])->exists()) continue;
                $this->db->table('roleplay_ranks')->insert($fields + [
                    'slug' => $slug, 'enabled' => true, 'sort_order' => $fields['level'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $changed++;
            }
            if ($changed) $this->db->table('roleplay_catalog_state')->where('id', 1)->increment('revision', 1, ['updated_at' => $now]);
            return $changed;
        });
    }
}
