<?php
// Продолжение общей проверки: все персонажи/вещи находятся во временных таблицах.
// Макеты actor ниже не сохраняются в users и не создают администратора.
use Flarum\User\User;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\ServerRequest;
use Maxcuso\Roleplay\Api\CharacterProfileController;
use Maxcuso\Roleplay\Api\CharacterEquipmentController;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;
use Maxcuso\Roleplay\Character\Equipment\CharacterEquipment;

$actorFor = fn (int $id, bool $moderate = false, bool $canView = true) =>
    new class($id, $moderate, $canView) extends User {
        public function __construct(int $id = 0, private bool $moderate = false, private bool $canView = true)
        {
            parent::__construct();
            $this->id = $id;
            $this->exists = $id > 0;
        }
        public function can(string $ability, mixed $arguments = null): bool
        {
            return match ($ability) {
                'reply', 'view', 'viewForum', 'roleplay.view' => $this->canView,
                'roleplay.moderate' => $this->moderate,
                default => false,
            };
        }
        public function isAdmin(): bool { return false; }
    };
$owner = $actorFor((int) $admin->id);
$visitor = $actorFor(999001);
$moderator = $actorFor(999002, true);
$guest = $actorFor(0);
$profile = new CharacterProfile($db, $topics);
$equipment = new CharacterEquipment($db);
$profileId = $freeResult['character_id'];

$ownList = $profile->list((int) $admin->id, null, $owner);
check(in_array($profileId, array_column($ownList['characters'], 'id'), true), 'Owner sees unfinished character in profile list');
check(!in_array($profileId, array_column($profile->list((int) $admin->id, null, $visitor)['characters'], 'id'), true),
    'List hides unfinished character from another user');
rejected(fn () => $profile->read($profileId, $visitor), 'character_not_found');
rejected(fn () => $profile->read(999999999, $visitor), 'character_not_found');
check($profile->read($profileId, $moderator)['snapshot']['character']['id'] === $profileId,
    'Moderator can read unfinished application through profile');
check(!$profile->read($profileId, $owner)['permissions']['can_equip'], 'Creating character cannot change equipment');
try {
    $profile->read($profileId, $actorFor(999003, false, false));
    throw new RuntimeException('Profile permission bypass');
} catch (\Flarum\User\Exception\PermissionDeniedException $error) {
    check(true, 'Server requires view permission even for public profile');
}
$db->table('roleplay_characters')->where('id', $profileId)->update(['status' => 'active']);
rejected(fn () => $profile->read($profileId, $visitor), 'character_not_found');
$db->table('roleplay_characters')->where('id', $profileId)->update(['literature_approved' => true, 'mechanics_status' => 'approved', 'literature_status' => 'approved']);
$public = $profile->read($profileId, $visitor);
check(!$public['permissions']['can_equip'] && $profile->read($profileId, $guest)['snapshot']['character']['id'] === $profileId,
    'Approved active character is visible, equipment remains owner-only');
check(!isset($public['snapshot']['character']['creation_catalog_revision'])
    && !isset($public['snapshot']['character']['race']['rules']) && !isset($public['snapshot']['effect_definitions']),
    'Public header does not expose internal creation metadata or race configuration');
check(isset($public['lookups']['attributes'][0]['slug']), 'Skill source can inherit the current attribute icon');

$gearType = $create('item-types', ['name' => 'Один предмет', 'equipment_limit' => 1]);
$gearDefinition = $create('items', [
    'name' => 'Броня с маной', 'item_type_id' => $gearType, 'max_stack' => 10, 'weight' => 1.5,
    'is_magical' => true, 'is_armor' => true, 'ignored_damage_units' => 3,
    'effects' => [$linkEffect('mana', 4)],
]);
$stackId = (int) $db->table('roleplay_character_items')->insertGetId([
    'character_id' => $profileId, 'item_id' => $gearDefinition, 'quantity' => 3, 'equipped' => false,
]);
$keyIndex = 0;
$gearKey = function () use (&$keyIndex): string {
    return sprintf('77777777-7777-4777-8777-%012d', ++$keyIndex);
};
$gearCommand = function (int $instance, bool $equipped) use ($profile, $profileId, $owner, $gearKey): array {
    $view = $profile->read($profileId, $owner);
    return ['operation_key' => $gearKey(), 'revision' => $view['snapshot']['character']['revision'],
        'catalog_revision' => $view['snapshot']['catalog_revision'], 'instance_id' => $instance, 'equipped' => $equipped];
};
$beforeGear = $profile->read($profileId, $owner);
$body = $gearCommand($stackId, true);
$changed = $equipment->change($profileId, $owner, $body);
$afterGear = $profile->read($profileId, $owner);
$equippedId = $changed['instance_id'];
check($equippedId !== $stackId && (int) $db->table('roleplay_character_items')->find($stackId)->quantity === 2
    && count($afterGear['snapshot']['equipment']) === 1, 'Equip separates exactly one instance from a stack');
check($afterGear['calculation']['inventory_weight']->value === $beforeGear['calculation']['inventory_weight']->value
    && $afterGear['calculation']['stats']['mana_max']->value === $beforeGear['calculation']['stats']['mana_max']->value + 4
    && $afterGear['calculation']['stats']['ignored_damage']->value === $beforeGear['calculation']['stats']['ignored_damage']->value + 3,
    'Equipment recalculates effects and armor but never duplicates inventory weight');
check($equipment->change($profileId, $owner, $body) === $changed
    && $db->table('roleplay_character_events')->where('operation_id', $body['operation_key'])->count() === 1,
    'Same command safely replays without extra instance, revision or event');
$wrongBody = $body;
$wrongBody['equipped'] = false;
rejected(fn () => $equipment->change($profileId, $owner, $wrongBody), 'operation_key_conflict');
rejected(fn () => $equipment->change($profileId, $moderator, $gearCommand($equippedId, false)), 'character_not_found');
rejected(fn () => $equipment->change($profileId, $visitor, $gearCommand($equippedId, false)), 'character_not_found');
rejected(fn () => $equipment->change($profileId, $owner, $gearCommand($stackId, true)), 'equipment_limit_reached');

$stale = $gearCommand($equippedId, false);
$stale['revision']--;
rejected(fn () => $equipment->change($profileId, $owner, $stale), 'equipment_revision_conflict');
$oldCatalog = $gearCommand($equippedId, false);
$oldCatalog['catalog_revision']--;
rejected(fn () => $equipment->change($profileId, $owner, $oldCatalog), 'equipment_revision_conflict');
rejected(fn () => $equipment->change($profileId, $owner, $gearCommand(999999999, true)), 'item_not_found');

// Даже после отключения/снижения лимита можно снять уже надетую вещь.
$api('PUT', 'item-types', ['revision' => $revision(), 'fields' => [
    'name' => 'Теперь нельзя', 'equipment_limit' => -1, 'enabled' => false,
]], ['id' => $gearType]);
$equipment->change($profileId, $owner, $gearCommand($equippedId, false));
$afterRemoval = $profile->read($profileId, $owner);
check(!$db->table('roleplay_character_items')->find($equippedId)->equipped
    && $afterRemoval['calculation']['stats']['mana_max']->value === $beforeGear['calculation']['stats']['mana_max']->value,
    'Removal stays possible after type disable and removes item effects');
rejected(fn () => $equipment->change($profileId, $owner, $gearCommand($equippedId, true)), 'item_not_equippable');

// 0 означает без лимита; существующие выключенные определения не отнимают владение вещами.
$api('PUT', 'item-types', ['revision' => $revision(), 'fields' => [
    'name' => 'Без лимита', 'equipment_limit' => 0, 'enabled' => false,
]], ['id' => $gearType]);
$equipment->change($profileId, $owner, $gearCommand($stackId, true));
$equipment->change($profileId, $owner, $gearCommand($equippedId, true));
check(count($profile->read($profileId, $owner)['snapshot']['equipment']) === 2,
    'Unlimited type permits multiple equipped instances even when disabled for new catalog choices');

$db->table('roleplay_characters')->where('id', $profileId)->update(['status' => 'dead']);
rejected(fn () => $equipment->change($profileId, $owner, $gearCommand($equippedId, false)), 'equipment_forbidden');
check($profile->read($profileId, $visitor)['snapshot']['character']['status'] === 'dead',
    'Approved dead character stays readable without equipment controls');
$db->table('roleplay_characters')->where('id', $profileId)->update(['status' => 'active']);

// Ошибка записи истории должна откатить и вещь, и версию, и операцию.
$failCommand = $gearCommand($stackId, true);
$beforeFailure = $db->table('roleplay_character_items')->where('character_id', $profileId)->get()->toJson();
$beforeRevision = $db->table('roleplay_characters')->find($profileId)->revision;
$trigger = $testPrefix.'profile_event_failure';
$db->unprepared("CREATE TRIGGER $trigger BEFORE INSERT ON ".$db->getTablePrefix()."roleplay_character_events
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test rollback'");
try {
    try {
        $equipment->change($profileId, $owner, $failCommand);
        throw new RuntimeException('Injected event failure accepted');
    } catch (\Illuminate\Database\QueryException $error) {
        check($beforeFailure === $db->table('roleplay_character_items')->where('character_id', $profileId)->get()->toJson()
            && $beforeRevision === $db->table('roleplay_characters')->find($profileId)->revision
            && !$db->table('roleplay_operations')->where('id', $failCommand['operation_key'])->exists(),
            'Failure rolls back all inventory, version and history writes');
    }
} finally { $db->unprepared("DROP TRIGGER IF EXISTS $trigger"); }
$equipment->change($profileId, $owner, $failCommand);

$profileController = new CharacterProfileController($db, $topics);
$gearController = new CharacterEquipmentController($db, $topics);
$profileRequest = RequestUtil::withActor((new ServerRequest())->withQueryParams(['id' => $profileId]), $owner);
$response = $profileController->handle($profileRequest);
check($response->getStatusCode() === 200 && $response->getHeaderLine('Cache-Control') === 'private, no-store',
    'Real profile API serializes private, non-cached response');
$profileFixture = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['data'];
if (!is_dir(__DIR__.'/.runtime')) mkdir(__DIR__.'/.runtime', 0777, true);
file_put_contents(__DIR__.'/.runtime/profile-view.json', json_encode($profileFixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
$gearRequest = RequestUtil::withActor((new ServerRequest())->withMethod('POST')
    ->withQueryParams(['id' => $profileId])->withParsedBody($failCommand), $owner);
check($gearController->handle($gearRequest)->getStatusCode() === 200, 'Equipment API replays and returns actual recalculated profile');
$badBool = $gearCommand($equippedId, false);
$badBool['equipped'] = 'yes';
$badRequest = RequestUtil::withActor($gearRequest->withParsedBody($badBool), $owner);
check($gearController->handle($badRequest)->getStatusCode() === 400, 'Equipment API rejects malformed boolean');

// Пагинация не теряет старые строки при добавлении новых персонажей между страницами.
$fixtureRace = (int) $db->table('roleplay_characters')->find($profileId)->race_id;
for ($i = 0; $i < 32; $i++) {
    $db->table('roleplay_characters')->insert([
        'user_id' => 888001, 'race_id' => $fixtureRace, 'first_name' => 'Архив '.$i,
        'status' => 'archived', 'literature_approved' => true, 'mechanics_status' => 'approved', 'literature_status' => 'approved', 'created_at' => $now, 'updated_at' => $now,
    ]);
}
$firstPage = $profile->list(888001, null, $visitor);
$secondPage = $profile->list(888001, $firstPage['next_before'], $visitor);
check(count($firstPage['characters']) === 30 && count($secondPage['characters']) === 2
    && !array_intersect(array_column($firstPage['characters'], 'id'), array_column($secondPage['characters'], 'id')),
    'Profile list uses stable bounded cursor pagination');
echo "Profile, equipment and visibility checks passed.\n";