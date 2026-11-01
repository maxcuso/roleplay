<?php

declare(strict_types=1);

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\ServerRequest;
use Maxcuso\Roleplay\Api\{Access, CreateCharacterController, CreationDraftController, UploadCharacterAvatarController};
use Maxcuso\Roleplay\Creation\{CreateCharacter, CreationDraft};

// Только клонированные модели и чтение. Учётные записи, сессии и данные форума не меняются.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void {fwrite(STDERR, (string) $error."\n"); exit(1);});
$db = $container->make(ConnectionInterface::class);
$admin = User::findOrFail(1);
$admin->assertAdmin();
$check = function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "OK: $message\n";
};
$counts = fn () => [$db->table('roleplay_characters')->count(), $db->table('roleplay_creation_drafts')->count()];
$before = $counts();
$reject = function (User $actor, string $label) use ($container, $check): void {
    $check(!Access::canCreate($actor), "$label is not advertised as eligible");
    $request = RequestUtil::withActor((new ServerRequest())->withMethod('POST')->withParsedBody([]), $actor);
    $actions = [
        'access' => fn () => Access::create($actor),
        'read draft' => fn () => $container->make(CreationDraft::class)->read($actor),
        'save draft' => fn () => $container->make(CreationDraft::class)->save($actor, []),
        'create character' => fn () => $container->make(CreateCharacter::class)->create($actor, []),
        'draft endpoint' => fn () => $container->make(CreationDraftController::class)->handle($request),
        'create endpoint' => fn () => $container->make(CreateCharacterController::class)->handle($request),
        'avatar endpoint' => fn () => $container->make(UploadCharacterAvatarController::class)->handle($request),
    ];
    foreach ($actions as $name => $action) {
        try {$action();} catch (PermissionDeniedException) {
            $check(true, "$label blocked: $name");
            continue;
        }
        throw new RuntimeException("$label was allowed: $name");
    }
};
$reject(new \Flarum\User\Guest(), 'Anonymous visitor');
$unconfirmed = clone $admin;
$unconfirmed->is_email_confirmed = false;
$check(!Access::creationRequiresLogin($unconfirmed), 'Unconfirmed real account is not treated as an anonymous visitor');
$reject($unconfirmed, 'Unconfirmed account, even with administrator permissions');
if (class_exists(\forumaker\Arena\ArenaGuestUser::class)) {
    $fake = clone $admin;
    $fake->id = PHP_INT_MAX - 100;
    $fake->is_email_confirmed = true;
    try {
        // Подмена только в памяти данного PHP-процесса, настройка Арены не записывается.
        \forumaker\Arena\ArenaGuestUser::rememberGuestUserId((int) $fake->id);
        $check(Access::creationRequiresLogin($fake), 'Shared Arena account is identified by ID');
        $reject($fake, 'Shared Arena account, even with confirmed email');
    } finally {
        \forumaker\Arena\ArenaGuestUser::useSettings($container->make(SettingsRepositoryInterface::class));
    }
}
$confirmed = clone $admin;
$confirmed->is_email_confirmed = true;
Access::create($confirmed);
$check(Access::canCreate($confirmed), 'Confirmed account retains creation access');
$check($counts() === $before, 'No characters or drafts were created');
