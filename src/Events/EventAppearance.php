<?php

namespace Maxcuso\Roleplay\Events;

use InvalidArgumentException;

/** Appearance is independent of the event's lifecycle status. */
class EventAppearance
{
    public const PALETTE = [1 => '#b8b8b8', '#80b77e', '#45698d', '#6b4e71', '#ffcb7f', '#a4243b'];
    public const DEFAULT_COLOR = '#d1d5db';

    public static function validate(string $type, ?int $difficulty, ?string $color): array
    {
        if ($type === 'quest') {
            if (!isset(self::PALETTE[$difficulty ?? 0])) {
                throw new InvalidArgumentException('Сложность квеста должна быть от 1 до 6');
            }
            if ($color !== null) {
                throw new InvalidArgumentException('Цвет квеста определяется сложностью');
            }
            return ['event_type' => $type, 'difficulty' => $difficulty, 'custom_color' => null];
        }
        if ($type !== 'forum') {
            throw new InvalidArgumentException('Тип события должен быть quest или forum');
        }
        if ($difficulty !== null) {
            throw new InvalidArgumentException('У форумного события нет уровня сложности');
        }
        $color ??= self::DEFAULT_COLOR;
        if (!preg_match('/^#[0-9a-fA-F]{6}$/D', $color)) {
            throw new InvalidArgumentException('Цвет должен иметь вид #caffbf');
        }
        return ['event_type' => $type, 'difficulty' => null, 'custom_color' => strtolower($color)];
    }

    public static function color(QuestEvent $event): string
    {
        if ($event->event_type === 'quest') {
            return self::PALETTE[$event->difficulty ?? 0] ?? self::DEFAULT_COLOR;
        }
        // Even values edited directly in the database must be safe for CSS.
        return preg_match('/^#[0-9a-fA-F]{6}$/D', $event->custom_color ?? '')
            ? $event->custom_color : self::DEFAULT_COLOR;
    }
}
