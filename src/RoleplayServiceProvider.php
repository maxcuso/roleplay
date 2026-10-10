<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay;

use Flarum\Foundation\AbstractServiceProvider;
use Maxcuso\Roleplay\Character\Application\ApplicationTopics;
use Maxcuso\Roleplay\Character\Application\FlarumApplicationTopics;

/** Единственное место выбора адаптера форума. Игровые сервисы не знают устройство API ядра. */
final class RoleplayServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(ApplicationTopics::class, FlarumApplicationTopics::class);
    }
}