<?php
declare(strict_types=1);

use Illuminate\Database\Schema\{Blueprint, Builder};

return [
    'up' => function (Builder $schema): void {
        $db = $schema->getConnection();
        $hadData = $db->table('roleplay_perks')->exists() || $db->table('roleplay_items')->exists();
        foreach (['perk' => 'perks', 'item' => 'items'] as $kind => $owner) {
            $table = 'roleplay_'.$owner;
            if (!$schema->hasColumn($table, 'effects')) {
                $schema->table($table, function (Blueprint $table): void { $table->json('effects')->nullable(); });
                $db->table($table)->update(['effects' => '[]']);
            }
        }
        // Сначала переносим ОБЕ группы. Старые таблицы убираем только после успешной записи всех владельцев.
        // Повторный запуск после прерывания безопасен: исходные связи ещё на месте.
        foreach (['perk' => 'perks', 'item' => 'items'] as $kind => $owner) {
            $links = 'roleplay_'.$kind.'_effects';
            if (!$schema->hasTable($links)) continue;
            foreach ($db->table('roleplay_'.$owner)->orderBy('id')->get() as $row) {
                $effects = [];
                foreach ($db->table($links)->where($kind.'_id', $row->id)->orderBy('id')->get() as $link) {
                    $definition = $db->table('roleplay_effects')->where('id', $link->effect_id)->first();
                    if (!$definition) throw new RuntimeException('Не найдено определение переносимого эффекта.');
                    $value = $link->value === null ? null : (float) $link->value;
                    if ($value !== null && floor($value) === $value) $value = (int) $value;
                    $effects[] = [
                        'name' => $definition->name, 'description' => $definition->description, 'icon' => $definition->icon,
                        'mechanic' => $definition->mechanic, 'operation' => $definition->operation, 'stat' => $definition->stat,
                        'attribute_id' => $definition->attribute_id === null ? null : (int) $definition->attribute_id,
                        'skill_id' => $definition->skill_id === null ? null : (int) $definition->skill_id,
                        'value' => $value, 'condition' => $link->condition,
                        'condition_text' => $link->condition_text, 'scope' => $link->scope,
                    ];
                }
                $db->table('roleplay_'.$owner)->where('id', $row->id)->update([
                    'effects' => json_encode($effects, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
        foreach (['perk_effects', 'item_effects', 'effects'] as $table) $schema->dropIfExists('roleplay_'.$table);
        if ($hadData) $db->table('roleplay_catalog_state')->where('id', 1)->increment('revision');
    },
    'down' => function (Builder $schema): void {
        // Новые независимые записи нельзя без потерь снова объединить в общий справочник.
        throw new RuntimeException('Переход на встроенные эффекты откатывается восстановлением резервной копии базы.');
    },
];
