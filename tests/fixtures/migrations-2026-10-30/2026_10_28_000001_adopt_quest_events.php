<?php

use Illuminate\Database\Schema\Builder;
use Maxcuso\Roleplay\Events\EventSchema;

return [
    'up' => fn (Builder $schema) => EventSchema::up($schema),
    // Форумные события существовали до Roleplay. Откат владения модулем
    // не удаляет их таблицы и данные; порядок переключения описан в docs/.
    'down' => function (Builder $schema): void {},
];
