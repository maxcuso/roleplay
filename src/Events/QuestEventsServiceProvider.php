<?php

namespace Maxcuso\Roleplay\Events;

use Flarum\Foundation\AbstractServiceProvider;
use Maxcuso\Roleplay\Events\Participation\ParticipationTypes;

final class QuestEventsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // Один реестр для создания, регистрации и API. Roleplay дополняет его при загрузке.
        $this->container->singleton(ParticipationTypes::class);
    }
}
