<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema): void {
        $schema->create('roleplay_episode_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('event_id')->index();
            $table->unsignedInteger('discussion_id')->index();
            $table->unsignedInteger('actor_user_id');
            $table->longText('payload');
            $table->dateTime('created_at');
        });
        $schema->create('roleplay_episode_completions', function (Blueprint $table): void {
            $table->unsignedInteger('event_id')->primary();
            $table->uuid('plan_id')->unique();
            $table->unsignedInteger('post_id')->index();
            $table->unsignedInteger('actor_user_id');
            $table->longText('result');
            $table->dateTime('created_at');
            // Снимок выдачи остаётся в журнале даже после удаления темы или карточки.
        });
    },
    'down' => function (Builder $schema): void {
        $schema->dropIfExists('roleplay_episode_completions');
        $schema->dropIfExists('roleplay_episode_plans');
    },
];
