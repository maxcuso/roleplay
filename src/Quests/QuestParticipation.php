<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Quests;

use Maxcuso\Roleplay\Events\Participation\ParticipationType;

/** Правила участия персонажа для встроенного движка событий. */
final class QuestParticipation extends CharacterParticipation implements ParticipationType {}
