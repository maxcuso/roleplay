<?php

namespace Maxcuso\Roleplay\Events;

use InvalidArgumentException;

class EventDefinition
{
    public readonly string $title;
    public readonly int $minParticipants;
    public readonly int $maxParticipants;

    public function __construct(
        string $title, 
        int $minParticipants, 
        int $maxParticipants
    )
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('Название не может быть пустым');
        }

        if ($minParticipants < 1) {
            throw new InvalidArgumentException('Минимальное число участников должно быть не меньше 1');
        }

        if ($maxParticipants < $minParticipants) {
            throw new InvalidArgumentException('Максимальное число участников не может быть меньше минимального');
        }

        $this->title = $title;
        $this->minParticipants = $minParticipants;
        $this->maxParticipants = $maxParticipants;
    }

    public function hasMinimumParticipants(int $participantCount): bool
    {
        return ($participantCount >= $this->minParticipants);
    }

    public function hasAvailableSlot(int $participantCount): bool
    {
        return ($participantCount < $this->maxParticipants);
    }

    public function calculateStartAt(
        \DateTimeImmutable $minimumReachedAt,
        ?\DateTimeImmutable $preferredStartAt,
        int $startDelayHours
    ): \DateTimeImmutable {
        if ($startDelayHours <= 0) {
            throw new InvalidArgumentException(
                'Интервал до старта должен быть больше нуля'
            );
        }

        if ($preferredStartAt !== null && $preferredStartAt >= $minimumReachedAt) {
            return $preferredStartAt;
        }

        return $minimumReachedAt->add(
            new \DateInterval('PT' . $startDelayHours . 'H')
        );
    }
}