<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Events;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Flarum\Extension\ExtensionManager;
use Illuminate\Contracts\Container\Container;

/** Один владелец событий: сначала старое расширение, после отключения — Roleplay. */
final class Module implements ExtenderInterface
{
    public static function isActive(Container $container): bool
    {
        return !$container->make(ExtensionManager::class)->isEnabled('maxcuso-quest-events');
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        // При обновлении пакета старый модуль может быть ещё включён. Нельзя
        // регистрировать второй комплект маршрутов, обработчиков и планировщика.
        if (!self::isActive($container)) return;

        foreach (require dirname(__DIR__, 2).'/resources/events/extend.php' as $extender) {
            $extender->extend($container, $extension);
        }
    }
}
