<?php

declare(strict_types=1);

use Flarum\Database\Migration;
use Flarum\Group\Group;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
use Maxcuso\Roleplay\Events\EventSchema;
use Maxcuso\Roleplay\Rules\DevelopmentConfiguration;

/** Единая схема первого выпуска по состоянию на 30 октября 2026 года.
 * Имя сохранено: существующая локальная установка уже выполнила эту миграцию.
 * Только структура и технические начальные строки; персонажи и справочники не выдаются.
 * Историческая цепочка хранится исключительно в tests/fixtures/migrations-2026-10-30.
 */
return [
    'up' => function (Builder $schema): void {
        // 01. Справочники и версия настроек.
        $schema->create('roleplay_catalog_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('updated_at');
        });
        $indexPrefix = 'rp_'.substr(sha1($schema->getConnection()->getTablePrefix()), 0, 8).'_';
        foreach (['attributes', 'magic_paths', 'item_types', 'perk_sources', 'perks', 'spells', 'skills', 'weapon_proficiencies', 'items', 'races'] as $name) {
            $schema->create('roleplay_'.$name, function (Blueprint $table) use ($name, $indexPrefix): void {
                $table->increments('id');
                $table->string('name', 100);
                $table->string('slug', 100)->unique();
                $table->text('description')->nullable();
                $table->boolean('enabled')->default(false);
                $table->integer('sort_order')->default(0);
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                // Короткое имя индекса оставляет место для префикса таблиц форума.
                // В PostgreSQL/SQLite имена индексов уникальны во всей схеме.
                $table->index(['enabled', 'sort_order', 'id'], $indexPrefix.$name.'_order');

                if (in_array($name, ['attributes', 'magic_paths', 'item_types', 'perk_sources'], true)) {
                    $table->string('icon', 100)->nullable();
                }
                if (in_array($name, ['skills', 'weapon_proficiencies'], true)) {
                    $table->unsignedInteger('attribute_id');
                    $table->foreign('attribute_id')->references('id')->on('roleplay_attributes')->restrictOnDelete();
                }
                if ($name === 'magic_paths') $table->string('color', 7)->nullable();
                if ($name === 'item_types') {
                    $table->integer('equipment_limit')->default(0);

                }
                if ($name === 'perk_sources') {
                    $table->string('name_source', 16)->nullable();
                    $table->string('icon_source', 16)->nullable();
                    $table->json('attribute_icons');
                }
                if ($name === 'perks') $table->string('image', 1000)->nullable();
                if (in_array($name, ['perks', 'items'], true)) $table->string('rarity', 16)->default('common');
                if (in_array($name, ['perks', 'items'], true)) $table->json('effects')->nullable();
                if ($name === 'spells') {
                    $table->unsignedInteger('magic_path_id');
                    $table->unsignedTinyInteger('level')->default(1);
                    $table->boolean('is_starting')->default(true);
                    $table->string('distance_mode', 16)->default('self');
                    $table->decimal('distance_value', 10, 3)->unsigned()->nullable();
                    $table->unsignedInteger('radius_value')->default(0);
                    $table->unsignedInteger('damage_units')->default(0);
                    $table->string('cast_mode', 16)->default('instant');
                    $table->unsignedInteger('cast_value')->nullable();
                    $table->string('cast_unit', 16)->default('turn');
                    foreach (['duration', 'cooldown'] as $time) {
                        $table->unsignedInteger($time.'_value')->default(0);
                        $table->string($time.'_unit', 16)->default('turn');
                    }
                    $table->unsignedInteger('mana_cost')->default(0);
                    $table->unsignedInteger('stamina_cost')->default(0);
                    $table->string('other_cost', 500)->nullable();
                    $table->foreign('magic_path_id')->references('id')->on('roleplay_magic_paths')->restrictOnDelete();
                }
                if ($name === 'items') {
                    $table->unsignedInteger('item_type_id');
                    $table->text('appearance')->nullable();
                    $table->boolean('is_magical')->default(false);
                    $table->boolean('is_ingredient')->default(false);
                    $table->boolean('is_literature')->default(false);
                    $table->text('literature_contents')->nullable();
                    $table->boolean('consumed_on_learning')->default(false);
                    // Применение задаётся у конкретного предмета. Флаги независимы от его типа.
                    foreach (['is_melee_weapon', 'is_ranged_weapon', 'is_magic_weapon', 'is_armor'] as $flag) {
                        $table->boolean($flag)->default(false);
                    }
                    // Отдельное владение для каждого применения. Конкретные ID выбираются в админке.
                    foreach (['melee_proficiency_id', 'ranged_proficiency_id', 'magic_proficiency_id'] as $column) {
                        $table->unsignedInteger($column)->nullable();
                        $table->foreign($column)->references('id')->on('roleplay_weapon_proficiencies')->restrictOnDelete();
                    }
                    $table->unsignedInteger('ignored_damage_units')->default(0);
                    $table->unsignedInteger('damage_units')->default(0);
                    $table->decimal('weight', 10, 3)->default(0);
                    $table->decimal('base_price', 19, 3)->unsigned()->default(0);
                    $table->unsignedInteger('max_stack')->default(1);
                    $table->json('properties');
                    $table->foreign('item_type_id')->references('id')->on('roleplay_item_types')->restrictOnDelete();
                }
                if ($name === 'races') {
                    $table->string('rules_url', 1000)->nullable();
                    $table->unsignedInteger('min_age')->nullable();
                    $table->unsignedInteger('max_age')->nullable();
                    $table->json('rules');
                    $table->string('average_height', 255)->nullable();
                    $table->text('distinctive_traits')->nullable();
                    $table->string('background_color', 7)->nullable();
                    $table->text('quote')->nullable();
                    $table->string('image', 1000)->nullable();
                }
            });
        }

        // Привязки редактируются вместе с навыком. Пустые уровни допустимы только у выключенного навыка.
        $schema->create('roleplay_skill_levels', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('skill_id');
            $table->unsignedTinyInteger('level');
            $table->unsignedInteger('perk_id')->nullable();
            $table->unique(['skill_id', 'level'], 'rp_skill_level_unique');
            $table->foreign('skill_id')->references('id')->on('roleplay_skills')->cascadeOnDelete();
            $table->foreign('perk_id')->references('id')->on('roleplay_perks')->restrictOnDelete();
        });

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

        // 02. Персонажи, способности и инвентарь. Существующих персонажей не создаём.
        $schema->create('roleplay_characters', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('race_id');
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('avatar', 100)->nullable();
            $table->unsignedInteger('rank_id')->nullable();
            $table->foreign('rank_id')->references('id')->on('roleplay_ranks')->restrictOnDelete();
            $table->unsignedInteger('age')->nullable();
            $table->unsignedInteger('creation_catalog_revision')->nullable();
            $table->unsignedInteger('discussion_id')->nullable()->unique();
            $table->boolean('literature_approved')->default(false);
            $table->string('mechanics_status', 24)->default('draft');
            $table->string('literature_status', 24)->default('draft');
            $table->string('literature_hash', 64)->nullable();
            $table->unsignedInteger('lore_revision')->default(1);
            $table->string('status', 16)->default('creating');
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->foreign('race_id')->references('id')->on('roleplay_races')->restrictOnDelete();
        });
        // Перенесённая модель состояния: расовая прибавка фиксируется при создании персонажа.
        // Короткие имена FK включают префикс установки: ограничения не конфликтуют с другой схемой.
        $constraintPrefix = 'rp_'.substr(sha1($schema->getConnection()->getTablePrefix()), 0, 8).'_';
        foreach (['attributes' => 'attribute_id', 'skills' => 'skill_id',
            'weapon_proficiencies' => 'proficiency_id'] as $name => $column) {
            $schema->create('roleplay_character_'.$name, function (Blueprint $table) use ($name, $column, $constraintPrefix): void {
                $table->increments('id');
                $table->unsignedInteger('character_id');
                $table->unsignedInteger($column);
                $table->unique(['character_id', $column], $constraintPrefix.$name.'_unique');
                $key = match ($name) {
                    'attributes' => 'ca', 'skills' => 'cs', 'weapon_proficiencies' => 'cw',
                };
                $table->foreign('character_id', $constraintPrefix.$key.'_character_fk')
                    ->references('id')->on('roleplay_characters')->restrictOnDelete();
                $table->foreign($column, $constraintPrefix.$key.'_definition_fk')
                    ->references('id')->on('roleplay_'.$name)->restrictOnDelete();
                if ($name === 'attributes') {
                    $table->unsignedInteger('base_value');
                    $table->integer('racial_bonus')->default(0);
                }
                if ($name === 'skills') $table->unsignedTinyInteger('level');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
        }
        foreach (['perks' => 'perk_id', 'spells' => 'spell_id', 'items' => 'item_id'] as $name => $column) {
            $schema->create('roleplay_character_'.$name, function (Blueprint $table) use ($name, $column): void {
                $table->increments('id');
                $table->unsignedInteger('character_id');
                $table->unsignedInteger($column);
                $table->boolean('is_starting')->default(false);
                $table->foreign('character_id')->references('id')->on('roleplay_characters')->restrictOnDelete();
                $table->foreign($column)->references('id')->on('roleplay_'.$name)->restrictOnDelete();
                if ($name === 'perks') {
                    // Ровно один источник на выдачу. Конкретное название/контекст относятся к выдаче.
                    $table->unsignedInteger('source_id');
                    $table->json('source_context');
                    // Обычная редкость стартовой выдачи не меняет определение перка.
                    $table->string('rarity_override', 16)->nullable();
                    // Один полученный перк и один источник у данного персонажа.
                    $table->unique(['character_id', 'perk_id'], 'rp_character_perk_unique');
                    $table->foreign('source_id')->references('id')->on('roleplay_perk_sources')->restrictOnDelete();
                }
                if ($name === 'spells') $table->unique(['character_id', 'spell_id'], 'rp_character_spell_unique');
                if ($name === 'items') {
                    $table->unsignedInteger('quantity')->default(1);
                    $table->boolean('equipped')->default(false);
                }
            });
        }
        // Черновики, настройки и история операций — отдельны от определений игровых сущностей.
        $schema->create('roleplay_creation_config', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->json('configuration');
            $table->dateTime('updated_at');
        });
        $schema->create('roleplay_actor_states', function (Blueprint $table): void {
            // Строка блокируется даже до появления первого черновика; таблицу users не изменяем.
            $table->unsignedInteger('user_id')->primary();
            $table->dateTime('created_at');
        });
        $schema->create('roleplay_creation_drafts', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->unique();
            $table->json('payload');
            $table->unsignedTinyInteger('payload_version')->default(1);
            $table->string('current_step', 32)->default('basics');
            $table->unsignedInteger('revision');
            $table->unsignedInteger('catalog_revision');
            $table->string('rules_hash', 64);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        $schema->create('roleplay_operations', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('type', 32);
            $table->unsignedInteger('actor_user_id');
            $table->unsignedInteger('character_id')->nullable();
            $table->string('request_hash', 64);
            $table->json('result')->nullable();
            $table->dateTime('created_at');
            $table->foreign('character_id')->references('id')->on('roleplay_characters')->restrictOnDelete();
        });
        $schema->create('roleplay_character_choices', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('character_id');
            $table->unsignedInteger('attribute_id');
            $table->string('kind', 16);
            $table->unsignedTinyInteger('threshold');
            // Разный тип цели определяется kind. Реальное владение/навык хранится в своей таблице.
            $table->unsignedInteger('target_id')->nullable();
            $table->unsignedTinyInteger('target_level')->nullable();
            $table->string('rule_key', 64)->nullable();
            $table->unsignedSmallInteger('rule_ordinal')->nullable();
            $table->unique(['character_id', 'rule_key', 'rule_ordinal'], 'rp_choice_rule_unique');
            $table->foreign('character_id')->references('id')->on('roleplay_characters')->restrictOnDelete();
            $table->foreign('attribute_id')->references('id')->on('roleplay_attributes')->restrictOnDelete();
        });
        $schema->create('roleplay_character_balances', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('character_id');
            $table->string('resource_key', 32);
            $table->unsignedBigInteger('amount');
            $table->dateTime('updated_at');
            $table->unique(['character_id', 'resource_key'], 'rp_resource_balance_unique');
            $table->foreign('character_id')->references('id')->on('roleplay_characters')->restrictOnDelete();
        });
        $schema->create('roleplay_character_events', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('character_id');
            $table->unsignedInteger('actor_user_id');
            $table->string('operation_id', 36);
            $table->string('type', 32);
            $table->json('payload');
            $table->dateTime('created_at');
            $table->unique(['operation_id', 'type'], 'rp_operation_event_unique');
            $table->foreign('character_id')->references('id')->on('roleplay_characters')->restrictOnDelete();
            $table->foreign('operation_id')->references('id')->on('roleplay_operations')->restrictOnDelete();
        });
        // 03. Настройки развития и накопленная статистика ролевика.
        $schema->create('roleplay_development_config', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->json('configuration');
            $table->dateTime('updated_at');
        });
        $schema->create('roleplay_player_statistics', function (Blueprint $table): void {
            $table->unsignedInteger('user_id')->primary();
            $table->unsignedBigInteger('earned_experience')->default(0);
            $table->dateTime('started_at')->nullable();
        });

        // 04. Участие персонажа в квесте, планы наград и неизменяемые итоги.
        $questPrefix = 'rpq_'.substr(sha1($schema->getConnection()->getTablePrefix()), 0, 8).'_';
        $schema->create('roleplay_quest_participants', function (Blueprint $table) use ($questPrefix): void {
            $table->unsignedInteger('event_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('character_id')->nullable();
            $table->string('character_name', 201);
            $table->dateTime('created_at');
            $table->unique(['event_id', 'user_id'], $questPrefix.'participation');
            $table->foreign('character_id', $questPrefix.'character')->references('id')->on('roleplay_characters')->nullOnDelete();
        });
        $schema->create('roleplay_episode_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('event_id')->nullable()->index();
            $table->unsignedInteger('discussion_id')->index();
            $table->unsignedInteger('actor_user_id');
            $table->longText('payload');
            $table->dateTime('created_at');
        });
        $schema->create('roleplay_episode_completions', function (Blueprint $table): void {
            $table->unsignedInteger('event_id')->nullable()->unique();
            $table->uuid('plan_id')->primary();
            $table->unsignedInteger('post_id')->index();
            $table->unsignedInteger('actor_user_id');
            $table->longText('result');
            $table->dateTime('created_at');
        });

        // 05. Запросы и результаты проверок, бросок фиксируется при публикации.
        $schema->create('roleplay_checks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 12);
            $table->unsignedInteger('actor_user_id')->index();
            $table->unsignedInteger('discussion_id')->nullable()->index();
            $table->unsignedInteger('post_id')->nullable()->index();
            $table->longText('payload');
            $table->longText('result')->nullable();
            $table->dateTime('created_at');
        });

        $db = $schema->getConnection();
        // Только техническая строка. Стартовые данные настраиваются явно, не миграцией.
        $db->table('roleplay_creation_config')->insert(['id' => 1, 'configuration' => '{}', 'updated_at' => gmdate('Y-m-d H:i:s')]);
        // Ревизия 2 совпадает с чистой установкой прежней цепочки после добавления описаний.
        $db->table('roleplay_catalog_state')->insert(['id' => 1, 'revision' => 2, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $db->table('roleplay_development_config')->insert([
            'id' => 1, 'configuration' => json_encode(DevelopmentConfiguration::defaults()->values, JSON_THROW_ON_ERROR),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        // Дополнительная защита MySQL/MariaDB. SQLite не поддерживает ALTER ADD CHECK,
        // а PostgreSQL — CAST AS SIGNED. Проверки приложения действуют на всех СУБД.
        $databaseChecks = [
            'catalog_state' => 'id = 1 AND revision >= 1',
            'creation_config' => 'id = 1',
            'creation_drafts' => 'revision >= 1 AND catalog_revision >= 1 AND payload_version = 1',
            'character_choices' => "kind IN ('skill', 'proficiency') AND threshold BETWEEN 1 AND 24"
                ." AND ((target_id IS NULL AND target_level IS NULL) OR (target_id >= 1"
                ." AND ((kind = 'skill' AND target_level IS NOT NULL AND target_level BETWEEN 1 AND 3) OR (kind = 'proficiency' AND target_level IS NULL))))",
            'skill_levels' => 'level BETWEEN 1 AND 3',
            'character_attributes' => 'base_value >= 1 AND CAST(base_value AS SIGNED) + racial_bonus >= 1',
            'character_skills' => 'level BETWEEN 1 AND 3',
            'character_items' => 'quantity >= 1 AND (equipped = 0 OR quantity = 1)',
            'races' => 'min_age IS NULL OR max_age IS NULL OR min_age <= max_age',
            'item_types' => 'equipment_limit >= -1',
            'spells' => 'level BETWEEN 1 AND 100',
            'items' => 'max_stack >= 1 AND weight >= 0'
                .' AND (is_melee_weapon = 0 OR melee_proficiency_id IS NOT NULL)'
                .' AND (is_ranged_weapon = 0 OR ranged_proficiency_id IS NOT NULL)'
                .' AND (is_magic_weapon = 0 OR magic_proficiency_id IS NOT NULL)',
        ];
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach ($databaseChecks as $name => $condition) {
                $table = $db->getQueryGrammar()->wrapTable('roleplay_'.$name);
                $db->statement("ALTER TABLE $table ADD CHECK ($condition)");
            }
        }

        // 06. Форумные события: при наличии QuestEvents сохраняем таблицы и их содержимое.
        EventSchema::up($schema);
        $permissions = Migration::addPermissions([
            'roleplay.workspace' => Group::MODERATOR_ID,
            'roleplay.catalog.view' => Group::MODERATOR_ID,
            'quest-events.createForum' => Group::MEMBER_ID,
        ]);
        $permissions['up']($schema);
    },
    'down' => function (Builder $schema): void {
        // Удаление данных выполняется только явной штатной очисткой расширения.
        // Форумные события, которые мог создать прежний QuestEvents, остаются нетронутыми.
        foreach (['checks', 'episode_completions', 'episode_plans', 'quest_participants',
            'character_events', 'operations', 'character_balances', 'character_choices',
            'creation_drafts', 'actor_states', 'creation_config', 'character_items', 'character_spells', 'character_perks',
            'character_attributes', 'character_skills', 'character_weapon_proficiencies', 'characters',
            'ranks', 'skill_levels', 'races', 'items', 'skills', 'weapon_proficiencies',
            'spells', 'perks', 'perk_sources', 'item_types', 'magic_paths', 'attributes', 'catalog_state',
            'development_config', 'player_statistics'] as $name) {
            $schema->dropIfExists('roleplay_'.$name);
        }
        $permissions = Migration::addPermissions([
            'roleplay.workspace' => Group::MODERATOR_ID,
            'roleplay.catalog.view' => Group::MODERATOR_ID,
            'quest-events.createForum' => Group::MEMBER_ID,
        ]);
        $permissions['down']($schema);
    },
];
