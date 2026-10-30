<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema): void {
        $schema->table('roleplay_episode_plans', fn (Blueprint $t) => $t->unsignedInteger('event_id')->nullable()->change());
        $schema->table('roleplay_episode_completions', function (Blueprint $t): void {
            $t->dropPrimary();
            $t->primary('plan_id');
        });
        $schema->table('roleplay_episode_completions', function (Blueprint $t): void {
            $t->dropUnique(['plan_id']);
            $t->unsignedInteger('event_id')->nullable()->change();
            // Квест завершается один раз, а обычная тема допускает несколько независимых выдач.
            $t->unique('event_id');
        });
    },
    'down' => function (Builder $schema): void {
        foreach (['roleplay_episode_plans', 'roleplay_episode_completions'] as $table) {
            if ($schema->getConnection()->table($table)->whereNull('event_id')->exists()) {
                throw new RuntimeException('Cannot downgrade while topic reward records exist');
            }
        }
        $schema->table('roleplay_episode_completions', function (Blueprint $t): void {
            $t->dropPrimary();
            $t->dropUnique(['event_id']);
            $t->unsignedInteger('event_id')->nullable(false)->change();
            $t->primary('event_id');
            $t->unique('plan_id');
        });
        $schema->table('roleplay_episode_plans', fn (Blueprint $t) => $t->unsignedInteger('event_id')->nullable(false)->change());
    },
];
