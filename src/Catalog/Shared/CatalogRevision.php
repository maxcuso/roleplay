<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Shared;

use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;

/** Версия защищает весь согласованный набор определений, а не отдельную строку. */
final class CatalogRevision
{
    public function __construct(private ConnectionInterface $db) {}

    public function read(callable $action): mixed
    {
        return $this->db->transaction(function () use ($action) {
            $revision = (int) $this->db->table('roleplay_catalog_state')
                ->where('id', 1)->sharedLock()->value('revision');
            return $action($revision);
        });
    }

    public function write(int $expected, callable $action): mixed
    {
        return $this->db->transaction(function () use ($expected, $action) {
            $revision = (int) $this->db->table('roleplay_catalog_state')
                ->where('id', 1)->lockForUpdate()->value('revision');
            if ($revision !== $expected) {
                throw new ApiProblem('catalog_revision_conflict', 'Справочник изменился. Обнови список.', 409);
            }
            // Если действие завершится ошибкой, запись и изменение версии откатятся вместе.
            $result = $action();
            $this->db->table('roleplay_catalog_state')->where('id', 1)
                ->increment('revision', 1, ['updated_at' => gmdate('Y-m-d H:i:s')]);
            return $result;
        });
    }
}