<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Quests;

/** Переходный адаптер: используется только пока старый QuestEvents включён. */
final class LegacyQuestParticipation extends CharacterParticipation implements \Maxcuso\QuestEvents\Participation\ParticipationType {}
