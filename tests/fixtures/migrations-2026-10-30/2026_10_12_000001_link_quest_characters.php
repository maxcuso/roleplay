<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Только дополнительные данные Roleplay. Таблица создаётся и без установленного QuestEvents.
// Запись участия event_id/user_id хранится в движке; подмодуль сохраняет выбранного персонажа.
return [
    'up' => function (Builder $schema): void {
        $prefix = 'rpq_'.substr(sha1($schema->getConnection()->getTablePrefix()), 0, 8).'_';
        $schema->create('roleplay_quest_participants', function (Blueprint $table) use ($prefix): void {
            $table->unsignedInteger('event_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('character_id')->nullable();
            $table->string('character_name', 201);
            $table->dateTime('created_at');
            $table->unique(['event_id', 'user_id'], $prefix.'participation');
            $table->foreign('character_id', $prefix.'character')->references('id')->on('roleplay_characters')->nullOnDelete();
        });
    },
    'down' => function (Builder $schema): void { $schema->dropIfExists('roleplay_quest_participants'); },
];
