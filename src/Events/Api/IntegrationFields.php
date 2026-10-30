<?php

namespace Maxcuso\Roleplay\Events\Api;

use Flarum\Api\Schema;
use Maxcuso\Roleplay\Events\Participation\ParticipationTypes;

final class IntegrationFields
{
    public function __construct(private ParticipationTypes $types) {}
    public function __invoke(): array
    {
        return [Schema\Boolean::make('questEventsRoleplayAvailable')->get(fn () => $this->types->has('quest'))];
    }
}
