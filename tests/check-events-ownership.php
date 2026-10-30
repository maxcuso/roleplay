<?php

declare(strict_types=1);

if (getenv('ROLEPLAY_TEST_COPY') !== '1') throw new RuntimeException('Run only in the installed test copy');
$app = (require dirname(__DIR__, 3).'/site.php')->bootApp();
$c = $app->getContainer();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); });
$manager = $c->make(\Flarum\Extension\ExtensionManager::class);
$owned = \Maxcuso\Roleplay\Events\Module::isActive($c);
$commands = array_values(array_filter($app->getConsoleCommands(), fn ($command) => $command->getName() === 'quest-events:start-due'));
if (count($commands) !== 1) throw new RuntimeException('Exactly one event scheduler command must be registered');
$expected = $owned ? \Maxcuso\Roleplay\Events\Console\StartDueEventsCommand::class : \Maxcuso\QuestEvents\Console\StartDueEventsCommand::class;
if (get_class($commands[0]) !== $expected) throw new RuntimeException('Event scheduler has the wrong owner');
$events = $c->make(\Illuminate\Contracts\Events\Dispatcher::class);
$listeners = $events->getRawListeners()[\Flarum\Post\Event\Saving::class] ?? [];
$eventListeners = array_values(array_filter($listeners, fn ($listener) => is_string($listener) && str_contains($listener, 'CreateEventsFromPost')));
if (count($eventListeners) !== 1) throw new RuntimeException('Exactly one event creation listener must be registered');
$admin = \Flarum\User\User::findOrFail(1);
$response = $c->make(\Flarum\Api\Client::class)->withActor($admin)->send('GET', '/');
$attributes = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['data']['attributes'];
if ($response->getStatusCode() !== 200 || !($attributes['questEventsRoleplayAvailable'] ?? false)) throw new RuntimeException('Forum must advertise character participation');
if (($attributes['roleplayEventsAvailable'] ?? false) !== $owned) throw new RuntimeException('Frontend ownership must agree with backend');
echo json_encode(['owner' => $owned ? 'Roleplay' : 'QuestEvents', 'legacyEnabled' => $manager->isEnabled('maxcuso-quest-events'), 'schedulerCount' => count($commands), 'creationListenerCount' => count($eventListeners)], JSON_PRETTY_PRINT), PHP_EOL;
