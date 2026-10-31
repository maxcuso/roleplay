<?php
use Maxcuso\Roleplay\Character\Moderation\CharacterManagement;
$management = new CharacterManagement($db, $characterOperations);
$manageIndex = 0;
$manageBody = function (string $action, array $data, string $comment = 'Тестовая выдача') use ($progressProfile, $progressId, $moderator, &$manageIndex): array {
    $view = $progressProfile->read($progressId, $moderator);
    return ['operation_key' => sprintf('55555555-5555-4555-8555-%012d', ++$manageIndex),
        'revision' => $view['snapshot']['character']['revision'], 'catalog_revision' => $view['snapshot']['catalog_revision'],
        'action' => $action, 'data' => $data, 'comment' => $comment];
};
rejected(fn () => $management->change($progressId, $owner, $manageBody('balance', ['resource' => 'gold', 'delta' => 1])), 'management_forbidden');
rejected(fn () => $management->change($progressId, $moderator, $manageBody('balance', ['resource' => 'gold', 'delta' => 1], '')), 'invalid_catalog_data');
$before = $snapshotBuilder->build($progressId)->balances;
$playerStats = new \Maxcuso\Roleplay\Character\Profile\RoleplayerStatistics($db);
$playerBefore = $playerStats->read((int) $owner->id)['earned_experience'];
$add = $manageBody('balance', ['resource' => 'experience_active', 'delta' => 5]);
$result = $management->change($progressId, $moderator, $add);
check($management->change($progressId, $moderator, $add) === $result, 'Moderator exact retry is idempotent');
check($playerStats->read((int) $owner->id)['earned_experience'] === $playerBefore + 5,
    'Player experience counts a moderator award once even after exact retry');
$after = $snapshotBuilder->build($progressId)->balances;
check($after['experience_active'] === $before['experience_active'] + 5 && $after['experience_total'] === $before['experience_total'] + 5,
    'Awarded experience adds both balances once');
$management->change($progressId, $moderator, $manageBody('balance', ['resource' => 'experience_active', 'delta' => -2]));
check($playerStats->read((int) $owner->id)['earned_experience'] === $playerBefore + 5,
    'Experience removal does not reduce player experience');
check($snapshotBuilder->build($progressId)->balances['experience_total'] === $after['experience_total'], 'Experience removal preserves lifetime total');
rejected(fn () => $management->change($progressId, $moderator, $manageBody('balance', ['resource' => 'gold', 'delta' => -100000])), 'insufficient_balance');
$perk = $create('perks', ['name' => 'Выдача модератора', 'enabled' => true]);
$grant = $manageBody('grant_perk', ['target_id' => $perk, 'source_id' => $raceSource, 'context' => ['race_id' => $freeRace]]);
$management->change($progressId, $moderator, $grant);
$source = $db->table('roleplay_character_perks')->where('character_id', $progressId)->where('perk_id', $perk)->first();
check(json_decode($source->source_context, true)['race_name'] === $db->table('roleplay_races')->find($freeRace)->name,
    'Source context uses canonical catalog race name');
rejected(fn () => $management->change($progressId, $moderator, $manageBody('grant_perk', ['target_id' => $perk, 'source_id' => $raceSource, 'context' => ['race_id' => $freeRace]])), 'already_owned');
$management->change($progressId, $moderator, $manageBody('remove_perk', ['target_id' => $perk]));
check(!$db->table('roleplay_character_perks')->where('character_id', $progressId)->where('perk_id', $perk)->exists(), 'Moderator can remove perk');
$gearDefinition = $create('items', ['name' => 'Предмет для выдачи', 'item_type_id' => $gearType, 'enabled' => true, 'max_stack' => 10]);
$grantItem = $manageBody('grant_item', ['target_id' => $gearDefinition, 'quantity' => 12]);
$management->change($progressId, $moderator, $grantItem);
$stacks = $db->table('roleplay_character_items')->where('character_id', $progressId)->where('item_id', $gearDefinition)->get();
check($stacks->every(fn ($row) => $row->quantity <= 10), 'Moderator item award respects maximum stack');
$stack = $stacks->first(fn ($row) => $row->quantity >= 2);
$management->change($progressId, $moderator, $manageBody('remove_item', ['target_id' => (int) $stack->id, 'quantity' => 1]));
check((int) $db->table('roleplay_character_items')->find($stack->id)->quantity === (int) $stack->quantity - 1,
    'Item removal touches only selected stack');
// Золото и заклинания проходят тем же путём, что остальные выдачи.
$goldBefore = $snapshotBuilder->build($progressId)->balances['gold'];
$management->change($progressId, $moderator, $manageBody('balance', ['resource' => 'gold', 'delta' => 8]));
check($snapshotBuilder->build($progressId)->balances['gold'] === $goldBefore + 8, 'Gold award changes the requested balance');
rejected(fn () => $management->change($progressId, $moderator,
    $manageBody('balance', ['resource' => 'mana', 'delta' => 1])), 'invalid_balance_change');
$management->change($progressId, $moderator, $manageBody('grant_spell', ['target_id' => $ordinarySpellTwo]));
check($db->table('roleplay_character_spells')->where('character_id', $progressId)->where('spell_id', $ordinarySpellTwo)->exists(),
    'Moderator grants a learned spell');
$management->change($progressId, $moderator, $manageBody('remove_spell', ['target_id' => $ordinarySpellTwo]));
check(!$db->table('roleplay_character_spells')->where('character_id', $progressId)->where('spell_id', $ordinarySpellTwo)->exists(),
    'Moderator removes only requested learned spell');
$db->table('roleplay_magic_paths')->where('id', $startPath)->update(['enabled' => false]);
rejected(fn () => $management->change($progressId, $moderator,
    $manageBody('grant_spell', ['target_id' => $ordinarySpellTwo])), 'magic_path_disabled');
$db->table('roleplay_magic_paths')->where('id', $startPath)->update(['enabled' => true]);
$view = $progressProfile->read($progressId, $owner);
check(!$view['permissions']['can_manage'] && count($view['history']) > 0, 'Owner sees reasons but cannot award resources');
check(!$progressProfile->read($progressId, $visitor)['history'], 'Visitors never see private financial history');
check(count($management->lookups($progressId, $moderator)['lookups']['perks']) > 0, 'Moderator can read award selectors without admin access');
rejected(fn () => $management->lookups($progressId, $owner), 'management_forbidden');

// Общая награда — одна транзакция, одна запись истории и тот же UUID при потере ответа.
$beforeReward = $snapshotBuilder->build($progressId)->balances;
$eventsBefore = $db->table('roleplay_character_events')->where('character_id', $progressId)->count();
$reward = $manageBody('reward', ['experience' => 3, 'gold' => 7]);
$rewardResult = $management->change($progressId, $moderator, $reward);
check($management->change($progressId, $moderator, $reward) === $rewardResult, 'Combined reward exact retry does not repeat either resource');
$afterReward = $snapshotBuilder->build($progressId)->balances;
check($afterReward['experience_active'] === $beforeReward['experience_active'] + 3
    && $afterReward['experience_total'] === $beforeReward['experience_total'] + 3
    && $afterReward['gold'] === $beforeReward['gold'] + 7, 'Combined reward updates both resources and lifetime experience');
check($db->table('roleplay_character_events')->where('character_id', $progressId)->count() === $eventsBefore + 1, 'Combined reward creates one history event');
$eventsBefore = $db->table('roleplay_character_events')->where('character_id', $progressId)->count();
rejected(fn () => $management->change($progressId, $moderator, $manageBody('reward', ['experience' => -1, 'gold' => -100000])), 'insufficient_balance');
check($snapshotBuilder->build($progressId)->balances === $afterReward
    && $eventsBefore === $db->table('roleplay_character_events')->where('character_id', $progressId)->count(),
    'Failure of second resource rolls back first resource and history');
rejected(fn () => $management->change($progressId, $moderator, $manageBody('reward', ['experience' => 0, 'gold' => 0])), 'invalid_balance_change');
rejected(fn () => $management->change($progressId, $moderator, $manageBody('reward', ['experience' => 1, 'gold' => -1])), 'invalid_balance_change');
$management->change($progressId, $moderator, $manageBody('reward', ['experience' => -1, 'gold' => -1]));
check($snapshotBuilder->build($progressId)->balances['experience_total'] === $afterReward['experience_total'], 'Combined removal preserves lifetime experience');

// Используем настоящие контроллеры и нормализаторы с несохранённым actor, не создаём аккаунт модератора.
$creationController = new \Maxcuso\Roleplay\Api\ManagementCatalogController($registry);
$moderatorCreate = function (string $method, string $catalog, array $fields = [], int $expected = 200) use ($creationController, $moderator, $revision): array {
    $request = \Flarum\Http\RequestUtil::withActor((new \Laminas\Diactoros\ServerRequest())->withMethod($method)
        ->withQueryParams(['catalog' => $catalog])->withParsedBody(['revision' => $revision(), 'fields' => $fields]), $moderator);
    $response = $creationController->handle($request);
    $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    check($response->getStatusCode() === $expected, 'Moderator creation HTTP '.$method.' '.$catalog.' '.$expected);
    return $body['data'] ?? $body;
};
$form = $moderatorCreate('GET', 'perks');
check(array_keys($form['lookups']) === ['attributes', 'skills'], 'Perk editor receives no administrative race/creation configuration');
$newPerk = $moderatorCreate('POST', 'perks', ['name' => 'Из окна выдачи', 'enabled' => true], 201)['id'];
$moderatorCreate('POST', 'spells', ['name' => 'Созданное заклинание', 'magic_path_id' => $startPath, 'level' => 1, 'enabled' => true], 201);
$moderatorCreate('POST', 'items', ['name' => 'Созданный предмет', 'item_type_id' => $gearType, 'enabled' => true], 201);
$moderatorCreate('GET', 'races', [], 403);
$moderatorCreate('POST', 'perks', ['name' => '', 'enabled' => true], 422);
$permissionDenied = function (callable $action): void {
    try {$action();} catch (\Flarum\User\Exception\PermissionDeniedException $error) {check(true, 'Administrative/ordinary player access remains denied'); return;}
    throw new RuntimeException('Expected permission denial');
};
$permissionDenied(fn () => $registry->creationForm('perks', $owner));
$permissionDenied(fn () => $registry->get('perks')->save($newPerk, $revision(), ['name' => 'Правка', 'enabled' => true], $moderator));
$permissionDenied(fn () => $registry->get('perks')->remove($newPerk, $revision(), $moderator));
$permissionDenied(fn () => $registry->get('races')->save(null, $revision(), ['name' => 'Нельзя'], $moderator));
check(!$db->table('roleplay_character_perks')->where('character_id', $progressId)->where('perk_id', $newPerk)->exists(),
    'Creating entity does not automatically grant it');
file_put_contents(__DIR__.'/.runtime/development-view.json', json_encode($progressProfile->read($progressId, $owner), JSON_THROW_ON_ERROR));
file_put_contents(__DIR__.'/.runtime/management-view.json', json_encode($progressProfile->read($progressId, $moderator), JSON_THROW_ON_ERROR));
file_put_contents(__DIR__.'/.runtime/management-lookups.json', json_encode($management->lookups($progressId, $moderator), JSON_THROW_ON_ERROR));
echo "Moderator grants, sources, balances, inventory and private history passed.\n";
