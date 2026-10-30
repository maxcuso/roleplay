<?php
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;

/** Происхождение выдачи, не её игровой источник. Позволяет править стартовый
 * выбор, сохраняя отдельные награды модератора. Иконок и баланса здесь нет.
 */
return [
    'up' => function (Builder $schema): void {
        foreach (['perks', 'spells', 'items'] as $name) {
            $schema->table('roleplay_character_'.$name, function (Blueprint $table): void {
                $table->boolean('is_starting')->default(false);
            });
            // В предыдущей версии награды выдавались только активным персонажам.
            // Поэтому имущество ещё создаваемых персонажей — стартовая выдача.
            $db = $schema->getConnection();
            $db->table('roleplay_character_'.$name)->whereIn('character_id',
                $db->table('roleplay_characters')->select('id')->where('status', 'creating'))
                ->update(['is_starting' => true]);
        }
    },
    'down' => function (Builder $schema): void {
        foreach (['perks', 'spells', 'items'] as $name) {
            $schema->table('roleplay_character_'.$name, fn (Blueprint $table) => $table->dropColumn('is_starting'));
        }
    },
];