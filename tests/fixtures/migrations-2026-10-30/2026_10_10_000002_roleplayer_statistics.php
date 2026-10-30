<?php
declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema): void {
        $schema->create('roleplay_player_statistics', function (Blueprint $table): void {
            $table->unsignedInteger('user_id')->primary();
            $table->unsignedBigInteger('earned_experience')->default(0);
            $table->dateTime('started_at')->nullable();
        });

        // Восстанавливаем только подтверждённые начисления из истории, без стартового опыта.
        // Для ранее удалённых персонажей история уже удалена: её нельзя восстановить из балансов.
        $db = $schema->getConnection();
        $db->table('roleplay_characters')->orderBy('id')->chunkById(100, function ($characters) use ($db): void {
            foreach ($characters as $character) {
                $earned = 0;
                $started = null;
                foreach ($db->table('roleplay_character_events')->where('character_id', $character->id)
                    ->whereIn('type', ['character_managed', 'review_decided', 'review_submitted'])->orderBy('id')->cursor() as $event) {
                    $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
                    if ($event->type === 'character_managed') {
                        $amount = match ($payload['action'] ?? null) {
                            'reward' => (int) ($payload['experience'] ?? 0),
                            'balance' => ($payload['resource'] ?? null) === 'experience_active' ? (int) ($payload['delta'] ?? 0) : 0,
                            default => 0,
                        };
                        $earned += max(0, $amount);
                    }
                    if (!empty($payload['activated']) && ($started === null || $event->created_at < $started)) {
                        $started = $event->created_at;
                    }
                }
                $query = $db->table('roleplay_player_statistics')->where('user_id', $character->user_id);
                $previous = $query->first();
                $total = (int) ($previous->earned_experience ?? 0) + $earned;
                if ($total > 9007199254740991) throw new RuntimeException('Roleplayer experience exceeds the safe integer limit.');
                $first = $previous->started_at ?? null;
                if ($started !== null && ($first === null || $started < $first)) $first = $started;
                $db->table('roleplay_player_statistics')->updateOrInsert(['user_id' => $character->user_id], [
                    'earned_experience' => $total, 'started_at' => $first,
                ]);
            }
        });
    },
    'down' => function (Builder $schema): void {
        $schema->drop('roleplay_player_statistics');
    },
];
