<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema): void {
        $schema->create('roleplay_checks', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('type', 12);
            $t->unsignedInteger('actor_user_id')->index();
            $t->unsignedInteger('discussion_id')->nullable()->index();
            $t->unsignedInteger('post_id')->nullable()->index();
            $t->longText('payload');
            $t->longText('result')->nullable();
            $t->dateTime('created_at');
            // Опубликованный снимок неизменяем: цитата, правка и просмотр не бросают кубики заново.
        });
    },
    'down' => fn (Builder $schema) => $schema->dropIfExists('roleplay_checks'),
];
