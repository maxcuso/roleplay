<?php

namespace Maxcuso\Roleplay\Events;

use DomainException;
use Flarum\Settings\SettingsRepositoryInterface;

class QuestSettings
{
    public const ENABLED = 'maxcuso-quest-events.quests_enabled';

    public function __construct(private SettingsRepositoryInterface $settings, private \Maxcuso\Roleplay\Events\Participation\ParticipationTypes $types) {}

    public function assertTypeAllowed(string $type, ?string $previousType = null): void
    {
        // Existing quests remain editable and continue their normal lifecycle.
        if ($type === 'quest' && $previousType !== 'quest'
            && (!$this->types->has('quest') || !filter_var($this->settings->get(self::ENABLED, '1'), FILTER_VALIDATE_BOOLEAN))) {
            throw new DomainException('Создание ролевых квестов отключено. Выбери форумное событие.');
        }
    }
}
