<?php

declare(strict_types=1);

if (getenv('ROLEPLAY_TEST_COPY') !== '1' || !is_file(dirname(__DIR__, 3).'/ROLEPLAY_TEST_INSTALLED')) {
    throw new RuntimeException('Run only in the installed test copy');
}
$c = (require dirname(__DIR__, 3).'/site.php')->bootApp()->getContainer();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, $e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); exit(1); });
$db = $c->make(\Illuminate\Database\ConnectionInterface::class);
$admin = \Flarum\User\User::findOrFail(1);
$client = $c->make(\Flarum\Api\Client::class);
$check = function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "OK: $label\n";
};
// Настоящие контроллеры и база, изолированная матрица прав без изменения групп форума.
$actor = function (array $permissions) {
    $user = new class extends \Flarum\User\User {
        public array $allowed = [];
        public function can(string $ability, mixed $arguments = []): bool {return in_array($ability, $this->allowed, true);}
        public function getForeignKey(): string {return 'user_id';}
    };
    $user->setRawAttributes(\Flarum\User\User::findOrFail(2)->getAttributes(), true);
    $user->exists = true;
    $user->setRelation('groups', new \Illuminate\Database\Eloquent\Collection());
    $user->allowed = ['viewForum', 'roleplay.view', ...$permissions];
    return $user;
};
$read = $actor(['roleplay.workspace', 'roleplay.catalog.view']);
$edit = $actor(['roleplay.workspace', 'roleplay.catalog.view', 'roleplay.catalog.edit']);
$create = $actor(['roleplay.workspace', 'roleplay.catalog.view', 'roleplay.catalog.create']);
$delete = $actor(['roleplay.workspace', 'roleplay.catalog.view', 'roleplay.catalog.delete']);
$call = function ($who, string $method, string $key, ?int $id = null, array $body = [], array $filters = []) use ($c) {
    $request = (new \Laminas\Diactoros\ServerRequest())->withMethod($method)
        ->withAttribute('routeParameters', ['catalog' => $key] + ($id === null ? [] : ['id' => $id]))
        ->withQueryParams($filters)->withParsedBody($body);
    return $c->make(\Maxcuso\Roleplay\Api\WorkspaceCatalogController::class)->handle(\Flarum\Http\RequestUtil::withActor($request, $who));
};
$json = fn ($response) => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
$rev = fn () => (int) $db->table('roleplay_catalog_state')->where('id', 1)->value('revision');
$deny = function (callable $action, string $label) use ($check): void {
    try {$action();} catch (\Flarum\User\Exception\PermissionDeniedException $e) {$check(true, $label); return;}
    throw new RuntimeException($label.' was not enforced');
};
$db->beginTransaction();
try {
    $deny(fn () => $call(new \Flarum\User\Guest(), 'GET', 'perks'), 'Guest cannot open workspace catalogs');
    $deny(fn () => $call($actor([]), 'GET', 'perks'), 'Ordinary member cannot open workspace catalogs');
    foreach (['perks', 'spells', 'items'] as $key) {
        $response = $call($read, 'GET', $key);
        $result = $json($response)['data'];
        $check($response->getStatusCode() === 200 && $result['capabilities']['view']
            && !$result['capabilities']['edit'] && !$result['capabilities']['delete'], 'Read-only member can list '.$key.' without write controls');
    }
    $deny(fn () => $call($read, 'POST', 'perks'), 'Read permission does not permit creation');
    $deny(fn () => $call($read, 'PUT', 'perks', 1), 'Read permission does not permit editing');
    $deny(fn () => $call($edit, 'DELETE', 'perks', 1), 'Editing permission does not permit deletion');
    $deny(fn () => $call($edit, 'GET', 'races'), 'Workspace cannot open other administrative catalogs');
    $response = $client->withActor($edit)->send('GET', '/roleplay/admin/catalogs/perks');
    $check($response->getStatusCode() === 403, 'Workspace editor cannot use administrative routes');

    $fields = ['name' => '[TEST] Workspace perk', 'description' => 'Test description', 'enabled' => true,
        'sort_order' => 0, 'image' => null, 'rarity' => 'common', 'effects' => []];
    $response = $call($create, 'POST', 'perks', body: ['revision' => $rev(), 'fields' => $fields]);
    $check($response->getStatusCode() === 201, 'Creation permission reuses the existing perk validator');
    $id = $json($response)['data']['id'];
    $oldRevision = $rev();
    $fields['name'] = '[TEST] Workspace edited perk';
    $response = $call($edit, 'PUT', 'perks', $id, ['revision' => $oldRevision, 'fields' => $fields]);
    $check($response->getStatusCode() === 200 && $db->table('roleplay_perks')->where('id', $id)->value('name') === $fields['name'], 'Editor can change a record created by another actor');
    $response = $call($edit, 'PUT', 'perks', $id, ['revision' => $oldRevision, 'fields' => $fields]);
    $check($response->getStatusCode() === 409, 'Stale editor cannot overwrite a newer catalog revision');
    $response = $call($read, 'GET', 'perks', filters: ['search' => '[TEST] Workspace edited perk']);
    $check($json($response)['data']['total'] === 1, 'Workspace search uses the shared catalog query');
    $response = $call($delete, 'DELETE', 'perks', $id, ['revision' => $rev()]);
    $check($response->getStatusCode() === 200 && !$db->table('roleplay_perks')->where('id', $id)->exists(), 'Separate delete permission removes an unused record');
    $usedItem = $db->table('roleplay_character_items')->value('item_id');
    if ($usedItem !== null) {
        $response = $call($delete, 'DELETE', 'items', (int) $usedItem, ['revision' => $rev()]);
        $check($response->getStatusCode() === 409 && $json($response)['errors'][0]['code'] === 'catalog_entry_in_use', 'Used items retain the existing deletion protection');
    }

    $creator = $c->make(\Maxcuso\Roleplay\Events\EventCreator::class);
    $source = \Flarum\Post\Post::whereVisibleTo($read)->whereNull('hidden_at')->firstOrFail();
    $make = function (string $type, int $leader, string $status = 'recruiting') use ($creator, $admin, $source) {
        $event = $creator->create($admin, '[TEST] Workspace '.$type, null, 2, 4, 12,
            new DateTimeImmutable('+2 days'), leaderId: $leader, eventType: $type, difficulty: $type === 'quest' ? 1 : null);
        $event->source_post_id = $source->id; $event->status = $status; $event->save();
        return (int) $event->id;
    };
    $own = $make('quest', (int) $read->id);
    $foreign = $make('quest', (int) $admin->id);
    $forum = $make('forum', (int) $read->id);
    $archived = $make('quest', (int) $read->id, 'completed');
    $episodes = function (array $filters = []) use ($c, $read, $json): array {
        $request = \Flarum\Http\RequestUtil::withActor((new \Laminas\Diactoros\ServerRequest())->withQueryParams($filters), $read);
        return $json($c->make(\Maxcuso\Roleplay\Api\WorkspaceEpisodesController::class)->handle($request))['data']['rows'];
    };
    $rows = $episodes();
    $ids = array_column($rows, 'id');
    $check(in_array($own, $ids, true) && !in_array($foreign, $ids, true) && !in_array($forum, $ids, true)
        && !in_array($archived, $ids, true), 'My episodes selects only own current roleplay stories');
    $check(!$rows[0]['canManage'], 'Leading an episode does not grant event management rights');
    $check(in_array($forum, array_column($episodes(['type' => 'forum']), 'id'), true), 'Forum events have a separate filter');
    $check(in_array($archived, array_column($episodes(['status' => 'completed']), 'id'), true), 'Completed episodes appear in the archive');
} finally {
    $db->rollBack();
}
echo "Workspace checks passed; fixtures rolled back.\n";
