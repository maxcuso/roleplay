<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Events;

use Flarum\Database\Migration;
use Flarum\Group\Group;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** Принимает существующие таблицы QuestEvents или создаёт их на чистом форуме. */
final class EventSchema
{
    public static function up(Builder $schema): void
    {
        $existing = $schema->hasTable('quest_events');
        if (!$existing) {
            $schema->create('quest_events', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('title', 100);
                $table->string('description', 1000)->nullable();
                $table->unsignedInteger('min_participants');
                $table->unsignedInteger('max_participants');
                $table->string('status', 20)->default('recruiting');
                $table->unsignedInteger('start_delay_hours');
                $table->dateTime('preferred_start_at')->nullable();
                $table->dateTime('scheduled_start_at')->nullable();
                $table->unsignedInteger('leader_id')->nullable();
                $table->unsignedInteger('discussion_id')->nullable();
                $table->foreign('leader_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
            });
        }

        // Каждый столбец проверяется отдельно: повтор или частичное старое
        // обновление не пересоздаёт таблицу и не теряет уже существующие строки.
        $columns = [
            'source_post_id' => function (Blueprint $table): void {
                $table->unsignedInteger('source_post_id')->nullable();
                $table->foreign('source_post_id')->references('id')->on('posts')->nullOnDelete();
            },
            'source_draft_id' => fn (Blueprint $table) => $table->unsignedInteger('source_draft_id')->nullable(),
            'draft_owner_id' => function (Blueprint $table): void {
                $table->unsignedInteger('draft_owner_id')->nullable();
                $table->foreign('draft_owner_id')->references('id')->on('users')->nullOnDelete();
            },
            'event_type' => fn (Blueprint $table) => $table->string('event_type', 10)->default('forum'),
            'difficulty' => fn (Blueprint $table) => $table->unsignedTinyInteger('difficulty')->nullable(),
            'custom_color' => fn (Blueprint $table) => $table->string('custom_color', 7)->nullable(),
            'reward_experience' => fn (Blueprint $table) => $table->unsignedInteger('reward_experience')->default(0),
            'reward_gold' => fn (Blueprint $table) => $table->unsignedInteger('reward_gold')->default(0),
            'reward_items' => fn (Blueprint $table) => $table->unsignedInteger('reward_items')->default(0),
            'start_error' => fn (Blueprint $table) => $table->text('start_error')->nullable(),
            'start_failed_at' => fn (Blueprint $table) => $table->dateTime('start_failed_at')->nullable(),
        ];
        foreach ($columns as $name => $definition) {
            if (!$schema->hasColumn('quest_events', $name)) $schema->table('quest_events', $definition);
        }

        if (!$schema->hasTable('quest_event_participants')) {
            $schema->create('quest_event_participants', function (Blueprint $table): void {
                $table->unsignedInteger('event_id');
                $table->unsignedInteger('user_id');
                $table->unique(['event_id', 'user_id']);
                $table->foreign('event_id')->references('id')->on('quest_events')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        // У обновляемого форума сохраняем даже намеренно снятые права.
        // Стартовые права добавляются только при первой установке событий.
        if (!$existing) {
            $permissions = Migration::addPermissions([
                'quest-events.create' => Group::MODERATOR_ID,
                'quest-events.manage' => Group::MODERATOR_ID,
            ]);
            $permissions['up']($schema);
        }
    }
}
