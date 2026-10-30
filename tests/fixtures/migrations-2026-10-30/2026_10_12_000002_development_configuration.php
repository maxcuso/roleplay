<?php
use Illuminate\Database\Schema\{Builder, Blueprint};
use Maxcuso\Roleplay\Rules\DevelopmentConfiguration;

/** Один раз создаём хранилище и привязываем прошлые выборы к постоянным ключам.
 * Дальнейшие изменения баланса выполняются редактором, без новых миграций.
 */
return [
    'up' => function (Builder $schema): void {
        $schema->create('roleplay_development_config', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary(); $table->json('configuration'); $table->dateTime('updated_at');
        });
        $db = $schema->getConnection();
        $db->table('roleplay_development_config')->insert(['id' => 1,
            'configuration' => json_encode(DevelopmentConfiguration::defaults()->values, JSON_THROW_ON_ERROR), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $schema->table('roleplay_character_choices', function (Blueprint $table): void {
            $table->string('rule_key', 64)->nullable(); $table->unsignedSmallInteger('rule_ordinal')->nullable();
        });
        $slugs = $db->table('roleplay_attributes')->pluck('slug', 'id')->all();
        foreach ($db->table('roleplay_character_choices')->orderBy('id')->get() as $choice) {
            $slug = $slugs[$choice->attribute_id] ?? null;
            if ($slug !== null) $db->table('roleplay_character_choices')->where('id', $choice->id)->update([
                'rule_key' => $slug.':'.$choice->kind.':'.$choice->threshold, 'rule_ordinal' => 1]);
        }
        $schema->table('roleplay_character_choices', function (Blueprint $table): void {
            $table->unique(['character_id', 'rule_key', 'rule_ordinal'], 'rp_choice_rule_unique');
            $table->dropUnique('rp_choice_threshold_unique');
        });
    },
    'down' => function (Builder $schema): void {
        $db = $schema->getConnection();
        if ($db->table('roleplay_character_choices')->select('character_id', 'attribute_id', 'kind', 'threshold')
            ->groupBy('character_id', 'attribute_id', 'kind', 'threshold')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Нельзя откатить развитие: несколько выборов на одном уровне.');
        }
        $schema->table('roleplay_character_choices', function (Blueprint $table): void {
            $table->unique(['character_id', 'attribute_id', 'kind', 'threshold'], 'rp_choice_threshold_unique');
            $table->dropUnique('rp_choice_rule_unique'); $table->dropColumn(['rule_key', 'rule_ordinal']);
        });
        $schema->dropIfExists('roleplay_development_config');
    },
];
