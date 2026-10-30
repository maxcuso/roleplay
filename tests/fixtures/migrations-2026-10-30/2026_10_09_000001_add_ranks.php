<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Только структура. Названия и буквы заполняются отдельно из resources/defaults/ranks.php.
return [
    'up' => function (Builder $schema): void {
        $schema->create('roleplay_ranks', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('letter', 4);
            $table->unsignedTinyInteger('level')->unique();
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        $schema->table('roleplay_characters', function (Blueprint $table): void {
            $table->unsignedInteger('rank_id')->nullable();
            $table->foreign('rank_id')->references('id')->on('roleplay_ranks')->restrictOnDelete();
        });
    },
    'down' => function (Builder $schema): void {
        $schema->table('roleplay_characters', function (Blueprint $table): void {
            $table->dropForeign(['rank_id']);
            $table->dropColumn('rank_id');
        });
        $schema->drop('roleplay_ranks');
    },
];
