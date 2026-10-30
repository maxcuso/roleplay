<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay;

use Flarum\Foundation\AbstractServiceProvider;
use Maxcuso\Roleplay\Character\Application\ApplicationTopics;
use Maxcuso\Roleplay\Character\Application\FlarumApplicationTopics;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;
use Illuminate\Database\ConnectionInterface;

/** Единственное место выбора адаптера форума. Игровые сервисы не знают устройство API ядра. */
final class RoleplayServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(ApplicationTopics::class, FlarumApplicationTopics::class);
        // В автономных тестах темы необязательны, а HTTP-профиль всегда использует адаптер форума.
        $this->container->bind(CharacterProfile::class, fn ($container) => new CharacterProfile(
            $container->make(ConnectionInterface::class), $container->make(ApplicationTopics::class),
        ));
    }

    public function boot(): void
    {
        if (\Maxcuso\Roleplay\Events\Module::isActive($this->container)) {
            $this->container->make(\Maxcuso\Roleplay\Events\Participation\ParticipationTypes::class)
                ->add('quest', $this->container->make(\Maxcuso\Roleplay\Quests\QuestParticipation::class));
        } elseif (class_exists(\Maxcuso\QuestEvents\Participation\ParticipationTypes::class)) {
            // Только на время обновления: старое расширение пока обслуживает
            // события, а правила персонажей уже общие для обоих адаптеров.
            $this->container->make(\Maxcuso\QuestEvents\Participation\ParticipationTypes::class)
                ->add('quest', $this->container->make(\Maxcuso\Roleplay\Quests\LegacyQuestParticipation::class));
        }
    }
}
