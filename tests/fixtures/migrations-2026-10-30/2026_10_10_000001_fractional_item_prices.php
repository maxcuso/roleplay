<?php

declare(strict_types=1);

use Illuminate\Database\Schema\{Blueprint, Builder};

/** Меняем только формат цены. Целые цены существующих предметов сохраняются. */
return [
    'up' => function (Builder $schema): void {
        $schema->table('roleplay_items', function (Blueprint $table): void {
            $table->decimal('base_price', 19, 3)->unsigned()->default(0)->change();
        });
    },
    'down' => function (Builder $schema): void {
        // Откат не должен молча уничтожить уже введённые дробные цены.
        if ($schema->getConnection()->table('roleplay_items')->whereRaw('base_price <> FLOOR(base_price)')->exists()) {
            throw new RuntimeException('Перед откатом приведи цены предметов к целым значениям.');
        }
        $schema->table('roleplay_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('base_price')->default(0)->change();
        });
    },
];
