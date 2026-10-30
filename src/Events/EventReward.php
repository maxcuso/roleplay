<?php
namespace Maxcuso\Roleplay\Events;

use InvalidArgumentException;

class EventReward
{
    public static function columns(string $type, array $reward): array
    {
        $columns = [];
        foreach (['experience', 'gold', 'items'] as $key) {
            $value = $reward[$key] ?? 0;
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) || strlen((string) $value) > 10 || (int) $value > 4294967295) {
                throw new InvalidArgumentException('Награда должна быть целым числом от 0 до 4294967295');
            }
            if ($type !== 'quest' && (int) $value !== 0) throw new InvalidArgumentException('Награда доступна только ролевым квестам');
            $columns['reward_' . $key] = (int) $value;
        }
        return $columns;
    }

    public static function data(QuestEvent $event): array
    {
        $reward = [];
        foreach (['experience', 'gold', 'items'] as $key) $reward[$key] = $event->event_type === 'quest' ? (int) $event->{'reward_' . $key} : 0;
        return $reward;
    }
}
