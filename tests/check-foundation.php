<?php

declare(strict_types=1);

// Используем установленный Flarum, но не подключаем новый пакет к работающему форуму.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
// Flarum логирует необработанное исключение без ненулевого кода CLI; тест обязан явно завершиться ошибкой.
set_exception_handler(function (\Throwable $error): void {
    fwrite(STDERR, (string) $error."\n");
    exit(1);
});
foreach (['Access', 'ApiProblem', 'ApiResponse'] as $class) {
    require_once __DIR__.'/../src/Api/'.$class.'.php';
}
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "OK: $message\n";
}
use Maxcuso\Roleplay\Api\Access;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Api\ApiResponse;
$response = ApiResponse::data(['value' => 0]);
check($response->getStatusCode() === 200, 'Success status');
check(json_decode((string)$response->getBody(), true)['data']['value'] === 0, 'Zero survives serialization');
check($response->getHeaderLine('Cache-Control') === 'private, no-store', 'Personal responses cannot be cached');
$response = ApiResponse::problem(new ApiProblem('revision_conflict', 'Обнови страницу.', 409));
$error = json_decode((string)$response->getBody(), true)['errors'][0];
check($response->getStatusCode() === 409 && $error['code'] === 'revision_conflict', 'Conflict has a stable code');
check($error['detail'] === 'Обнови страницу.', 'Unicode error message');
foreach (['admin', 'create'] as $action) {
    try {
        Access::$action(new \Flarum\User\User());
        throw new RuntimeException('Guest access accepted');
    } catch (\Flarum\User\Exception\PermissionDeniedException $e) {
        echo "OK: guest cannot $action\n";
    }
}
Access::admin(\Flarum\User\User::findOrFail(1));
check(count(require __DIR__.'/../extend.php') === 9, 'Flarum integration loads');
echo "Foundation checks passed; database unchanged.\n";