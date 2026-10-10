<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Shared;

use Flarum\User\User;

/** Контракт административных справочников. Персонажи имеют отдельные операции. */
interface CatalogService
{
    public function list(User $actor, string $search = '', ?bool $enabled = null, int $page = 1, array $filters = []): array;
    public function save(?int $id, int $revision, array $input, User $actor): array;
    public function toggle(int $id, int $revision, bool $enabled, User $actor): array;
    public function remove(int $id, int $revision, User $actor): array;
}