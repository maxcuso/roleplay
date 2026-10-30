<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// По умолчанию модератору открыт просмотр. Создание, изменение и удаление
// администратор назначает группам отдельно в штатной таблице разрешений.
return Migration::addPermissions([
    'roleplay.workspace' => Group::MODERATOR_ID,
    'roleplay.catalog.view' => Group::MODERATOR_ID,
]);
