<?php
// Этот сценарий выполняется внутри check-base-catalogs.php: все записи находятся во временных таблицах.
use Laminas\Diactoros\ServerRequest;
use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Api\AdminCatalogController;
use Maxcuso\Roleplay\Api\AdminCharacterController;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Character\Admin\CharacterAdmin;
use Maxcuso\Roleplay\Presentation\PerkImageEncoder;
use Flarum\Http\UrlGenerator;

$registry = new CatalogRegistry($db);
$controller = new AdminCatalogController($registry);
$api = function (string $method, string $catalog, array $body = [], array $query = [], int $expected = 200) use ($controller, $admin): array {
    $request = RequestUtil::withActor((new ServerRequest())->withMethod($method), $admin)
        ->withQueryParams(['catalog' => $catalog] + $query)->withParsedBody($body);
    $response = $controller->handle($request);
    $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if ($response->getStatusCode() !== $expected) throw new RuntimeException("$method $catalog: ".json_encode($payload, JSON_UNESCAPED_UNICODE));
    check(true, "$method $catalog status $expected");
    return $payload;
};
$create = function (string $key, array $fields) use ($api, $revision): int {
    return $api('POST', $key, ['revision' => $revision(), 'fields' => $fields], [], 201)['data']['id'];
};
$perkA = $create('perks', ['name' => 'Родство с природой', 'description' => 'Текст способности', 'image' => '/assets/perk.svg', 'rarity' => 'uncommon', 'enabled' => true]);
$perkB = $create('perks', ['name' => 'Другой перк']);
$spellA = $create('spells', ['name' => 'Заморозки', 'magic_path_id' => $pathId, 'level' => 1, 'distance_mode' => 'self',
    'damage_units' => 14, 'mana_cost' => 2, 'cooldown_value' => 2, 'enabled' => true]);
$spellB = $create('spells', ['name' => 'Касание', 'magic_path_id' => $pathId, 'level' => 2, 'distance_mode' => 'touch',
    'cast_mode' => 'timed', 'cast_value' => 1, 'cast_unit' => 'minute', 'duration_value' => 3, 'duration_unit' => 'day', 'stamina_cost' => 1]);
$typeId = $create('item-types', ['name' => 'Одежда', 'equipment_limit' => 5]);
$itemId = $create('items', ['name' => 'Плащ', 'item_type_id' => $typeId, 'is_magical' => true, 'weight' => 1.25,
    'damage_units' => 6, 'base_price' => 5, 'max_stack' => 2, 'properties' => [
        ['description' => 'Первое свойство', 'cooldown_value' => 2, 'cooldown_unit' => 'turn'],
        ['description' => 'Второе свойство', 'cooldown_value' => 0, 'cooldown_unit' => 'hour'],
    ]]);
$questSource = $create('sources', ['name' => 'Квест', 'icon' => 'fas fa-scroll']);
$rules = ['fixed_bonuses' => ['magic' => 2], 'choice_attributes' => ['agility'], 'attribute_count' => 1,
    'fixed_perk_ids' => [$perkA], 'perk_choice_ids' => [$perkB], 'perk_choice_count' => 1,
    'fixed_spell_ids' => [$spellA], 'spell_choice_path_ids' => [$pathId], 'spell_choice_count' => 1];
$richRace = $create('races', ['name' => 'Эльф', 'rules' => $rules]);
$raceRow = $api('GET', 'races', [], ['attribute' => 'magic'])['data']['rows'][0];
check($raceRow['id'] === $richRace && $raceRow['rules']['fixed_spell_ids'] === [$spellA], 'Race rules and fixed spell round-trip without a Magic level field');
check($api('GET', 'spells', [], ['path' => (string) $pathId, 'level' => '2'])['data']['total'] === 1, 'Spell path/circle filters');
check($api('GET', 'items', [], ['type' => (string) $typeId, 'magical' => '1'])['data']['total'] === 1, 'Item type/magic filters');
$badRules = $rules;
$badRules['perk_choice_ids'] = [$perkA];
$api('POST', 'races', ['revision' => $revision(), 'fields' => ['name' => 'Ошибка', 'rules' => $badRules]], [], 422);
$badRules = $rules;
$badRules['choice_attributes'] = [[]];
$api('POST', 'races', ['revision' => $revision(), 'fields' => ['name' => 'Ошибка', 'rules' => $badRules]], [], 422);
$api('POST', 'perks', ['revision' => $revision(), 'fields' => ['name' => 'Ошибка', 'source_id' => $questSource]], [], 422);
$api('POST', 'items', ['revision' => $revision(), 'fields' => ['name' => 'Ошибка', 'item_type_id' => $typeId, 'weight' => -1]], [], 422);
$api('POST', 'spells', ['revision' => $revision(), 'fields' => ['name' => 'Ошибка', 'magic_path_id' => 999999]], [], 422);
$api('GET', 'unknown', [], [], 404);
$api('PUT', 'paths', ['revision' => $revision(), 'fields' => $pathInput], [], 422);

// Загруженная форма каждой сущности сохраняется повторно без ручного изменения типов SQL-значений.
$editable = [
    'attributes' => ['icon'],
    'skills' => ['attribute_id', 'levels'],
    'proficiencies' => ['attribute_id'],
    'paths' => ['icon', 'color'],
    'item-types' => ['icon', 'equipment_limit'],
    'sources' => ['icon', 'name_source', 'icon_source', 'attribute_icons'],
    'perks' => ['image', 'rarity'],
    'spells' => ['magic_path_id', 'level', 'distance_mode', 'distance_value', 'radius_value', 'duration_value', 'duration_unit',
        'damage_units', 'cast_mode', 'cast_value', 'cast_unit', 'cooldown_value', 'cooldown_unit', 'mana_cost', 'stamina_cost', 'other_cost'],
    'items' => ['item_type_id', 'rarity', 'appearance', 'is_magical', 'is_melee_weapon', 'is_ranged_weapon', 'is_magic_weapon', 'is_armor', 'is_ingredient', 'is_literature', 'damage_units', 'ignored_damage_units', 'weight', 'base_price', 'max_stack', 'properties',
        'melee_proficiency_id', 'ranged_proficiency_id', 'magic_proficiency_id'],
    'races' => ['min_age', 'max_age', 'rules'],
];
foreach ($editable as $key => $fields) {
    $result = $api('GET', $key)['data'];
    check(count($result['lookups']) === 11 && $result['revision'] === $revision(), "$key consistent references");
    $row = $result['rows'][0];
    $allowed = array_merge(['name', 'description', 'enabled', 'sort_order'], $fields);
    $values = array_intersect_key($row, array_flip($allowed));
    $api('PUT', $key, ['revision' => $revision(), 'fields' => $values], ['id' => $row['id']]);
}
$loadedItem = $api('GET', 'items')['data']['rows'][0];
check(is_float($loadedItem['weight']) && is_bool($loadedItem['is_magical']) && $loadedItem['properties'][1]['description'] === 'Второе свойство', 'Decimal, boolean and item text properties have correct API types');
$stale = $revision();
$api('PATCH', 'items', ['revision' => $stale, 'enabled' => true], ['id' => $itemId]);
$api('PATCH', 'items', ['revision' => $stale, 'enabled' => false], ['id' => $itemId], 409);
foreach (['paths' => $pathId, 'item-types' => $typeId, 'perks' => $perkA, 'spells' => $spellA] as $key => $id) {
    $api('DELETE', $key, ['revision' => $revision()], ['id' => $id], 409);
}

// Имена и ссылка правятся в отдельной версии персонажа; одобрение и статус сохраняются.
$now = gmdate('Y-m-d H:i:s');
$characterId = $db->table('roleplay_characters')->insertGetId(['user_id' => $admin->id, 'race_id' => $richRace,
    'first_name' => 'Имя', 'last_name' => 'Фамилия', 'literature_approved' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
$characterTwo = $db->table('roleplay_characters')->insertGetId(['user_id' => $admin->id, 'race_id' => $raceId,
    'first_name' => 'Второй', 'created_at' => $now, 'updated_at' => $now]);
foreach ([[$characterId, $questSource], [$characterTwo, $sourceId]] as [$character, $source]) {
    $db->table('roleplay_character_perks')->insert(['character_id' => $character, 'perk_id' => $perkA,
        'source_id' => $source, 'source_context' => '{}']);
}
check($db->table('roleplay_character_perks')->where('perk_id', $perkA)->distinct()->count('source_id') === 2, 'Same perk has a different single source per character');
$db->table('roleplay_character_spells')->insert(['character_id' => $characterId, 'spell_id' => $spellA]);
$api('PATCH', 'paths', ['revision' => $revision(), 'enabled' => false], ['id' => $pathId]);
check($db->table('roleplay_character_spells')->where('character_id', $characterId)->count() === 1, 'Disabled path preserves learned spells');
$api('DELETE', 'sources', ['revision' => $revision()], ['id' => $questSource], 409);
$api('DELETE', 'races', ['revision' => $revision()], ['id' => $richRace], 409);
$characters = new CharacterAdmin($db, $container->make(UrlGenerator::class));
$characterController = new AdminCharacterController($characters, $registry);
$discussion = \Flarum\Discussion\Discussion::whereVisibleTo($admin)->first();
$link = $discussion ? '/d/'.$discussion->id : '';
$characterFields = ['first_name' => 'Новое имя', 'last_name' => 'Новая фамилия', 'discussion_link' => $link];
$request = RequestUtil::withActor((new ServerRequest())->withMethod('PUT'), $admin)
    ->withQueryParams(['id' => $characterId])->withParsedBody(['revision' => 1, 'fields' => $characterFields]);
check($characterController->handle($request)->getStatusCode() === 200, 'Character editor saves');
$after = $db->table('roleplay_characters')->find($characterId);
check((bool) $after->literature_approved && $after->status === 'active' && $after->revision == 2, 'Character edit keeps approval/status and increments own revision');
check($characterController->handle($request)->getStatusCode() === 409, 'Stale character edit is rejected');
$characterList = $characters->list($admin, 'Новое', 'active', 1);
check($characterList['total'] === 1 && $characterList['rows'][0]['owner_name'] === $admin->username, 'Character table search/status/owner');
$guestRequest = RequestUtil::withActor((new ServerRequest())->withMethod('GET'), new \Flarum\User\User())->withQueryParams(['catalog' => 'perks']);
try {
    $controller->handle($guestRequest);
    throw new RuntimeException('Guest catalogue API accepted');
} catch (\Flarum\User\Exception\PermissionDeniedException) {
    echo "OK: catalogue API is admin only\n";
}
$unused = $create('perks', ['name' => 'Можно удалить']);
$api('DELETE', 'perks', ['revision' => $revision()], ['id' => $unused]);
$api('GET', 'perks', [], ['search' => 'Можно удалить']);
check($registry->get('perks')->list($admin, 'Можно удалить')['total'] === 0, 'Unused definition is deleted');

// Изображение проверяем в памяти; файлы рабочего форума не создаются.
$encoder = new PerkImageEncoder();
[$svg, $extension] = $encoder->encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><image href="https://example.test/a.png"/><path d="M0 0 L1 1"/></svg>');
check($extension === 'svg' && !str_contains($svg, '<script') && !str_contains($svg, 'https://example'), 'Perk SVG strips scripts and remote references');
rejected(fn () => $encoder->encode('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'), 'invalid_catalog_data');
// Признаки относятся к предмету, даже если он имеет тип «Одежда».
// Проверяем сочетание всех четырёх признаков и защиту немагической брони.
$itemMeleeProficiency = (int) $db->table('roleplay_weapon_proficiencies')->where('slug', 'one-handed')->value('id');
$itemRangedProficiency = (int) $db->table('roleplay_weapon_proficiencies')->where('slug', 'thrown')->value('id');
$itemMagicProficiency = (int) $db->table('roleplay_weapon_proficiencies')->where('slug', 'magic-weapon')->value('id');
$multiUseItem = $create('items', [
    'name' => 'Многоцелевой предмет', 'item_type_id' => $typeId, 'is_magical' => false,
    'is_melee_weapon' => true, 'is_ranged_weapon' => true, 'is_magic_weapon' => true, 'is_armor' => true,
    'damage_units' => 14, 'ignored_damage_units' => 18,
    'melee_proficiency_id' => $itemMeleeProficiency, 'ranged_proficiency_id' => $itemRangedProficiency,
    'magic_proficiency_id' => $itemMagicProficiency,
]);
$multiUse = $api('GET', 'items', [], ['search' => 'Многоцелевой'])['data']['rows'][0];
foreach (['is_melee_weapon', 'is_ranged_weapon', 'is_magic_weapon', 'is_armor'] as $flag) {
    check($multiUse[$flag] === true, 'Item usage flag is independent and typed: '.$flag);
}
check($multiUse['is_magical'] === false && $multiUse['ignored_damage_units'] === 18,
    'Nonmagical armor has a separate damage reduction score');
$api('PUT', 'items', ['revision' => $revision(), 'fields' => array_intersect_key(
    $multiUse, array_flip(array_merge(['name', 'description', 'enabled', 'sort_order'], $editable['items']))
)], ['id' => $multiUseItem]);
$api('PATCH', 'items', ['revision' => $revision(), 'enabled' => true], ['id' => $multiUseItem]);
$api('POST', 'items', ['revision' => $revision(), 'fields' => [
    'name' => 'Ошибка', 'item_type_id' => $typeId, 'is_armor' => 'yes',
]], [], 422);
$api('POST', 'items', ['revision' => $revision(), 'fields' => [
    'name' => 'Ошибка', 'item_type_id' => $typeId, 'ignored_damage_units' => -1,
]], [], 422);
$api('POST', 'items', ['revision' => $revision(), 'fields' => [
    'name' => 'Ошибка', 'item_type_id' => $typeId, 'ignored_damage_units' => 1.5,
]], [], 422);
$api('PUT', 'item-types', ['revision' => $revision(), 'fields' => [
    'name' => 'Одежда', 'equipment_limit' => 5, 'is_weapon' => true,
]], ['id' => $typeId], 422);
foreach (['melee_proficiency_id' => $itemMeleeProficiency, 'ranged_proficiency_id' => $itemRangedProficiency,
    'magic_proficiency_id' => $itemMagicProficiency] as $field => $id) {
    check($multiUse[$field] === $id && is_int($multiUse[$field]), 'Separate proficiency ID round-trips: '.$field);
    $api('DELETE', 'proficiencies', ['revision' => $revision()], ['id' => $id], 409);
}
foreach (['is_melee_weapon', 'is_ranged_weapon', 'is_magic_weapon'] as $flag) {
    $api('POST', 'items', ['revision' => $revision(), 'fields' => [
        'name' => 'Без владения', 'item_type_id' => $typeId, $flag => true,
    ]], [], 422);
}
$api('POST', 'items', ['revision' => $revision(), 'fields' => [
    'name' => 'Неизвестное владение', 'item_type_id' => $typeId,
    'is_melee_weapon' => true, 'melee_proficiency_id' => 999999,
]], [], 422);
$api('DELETE', 'items', ['revision' => $revision()], ['id' => $multiUseItem]);
require __DIR__.'/learning-catalog-scenarios.php';
require __DIR__.'/character-state-scenarios.php';
require __DIR__.'/effect-catalog-scenarios.php';
require __DIR__.'/creation-scenarios.php';
require __DIR__.'/profile-scenarios.php';
echo "Admin catalogue and character API checks passed.\n";
