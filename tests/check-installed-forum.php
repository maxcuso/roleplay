<?php
declare(strict_types=1);

/**
 * Проверка настоящих маршрутов и событий Flarum только в подготовленной копии.
 * Использует существующие аккаунты; тестовые определения/персонаж/тема удаляются в finally.
 */
$root = dirname(__DIR__, 3);
if (getenv('ROLEPLAY_TEST_COPY') !== '1' || !is_file($root.'/ROLEPLAY_TEST_INSTALLED')) {
    fwrite(STDERR, "Only an installed test copy is allowed\n"); exit(1);
}
$site = require $root.'/site.php';
$container = $site->bootApp()->getContainer();
$db = $container->make(\Illuminate\Database\ConnectionInterface::class);
$client = $container->make(\Flarum\Api\Client::class);
$admin = \Flarum\User\User::findOrFail(1);
$owner = \Flarum\User\User::findOrFail(2);
$admin->assertAdmin();
$settings = new \Maxcuso\Roleplay\Creation\CreationConfigurationStore($db);
$oldConfig = $settings->read();
if ($db->table('roleplay_characters')->exists() || $db->table('roleplay_creation_drafts')->exists()) {
    fwrite(STDERR, "Test copy is already in use; lifecycle fixtures are not allowed\n"); exit(1);
}
$revision = fn () => (int) $db->table('roleplay_catalog_state')->where('id', 1)->value('revision');
$fixtures = [];
$characterId = $discussionId = null;
$messages = [];
function installedCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "OK: $message\n";
}
$call = function ($actor, string $method, string $path, array $body = [], int $expected = 200) use ($client): array {
    $response = $client->withActor($actor)->withBody($body)->send($method, $path);
    $text = (string) $response->getBody();
    $json = $text === '' ? [] : json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    if ($response->getStatusCode() !== $expected) throw new RuntimeException(
        "$method $path: ".$response->getStatusCode().' '.json_encode($json, JSON_UNESCAPED_UNICODE));
    return $json['data'] ?? $json;
};
$create = function (string $key, array $input) use ($call, $admin, $revision, &$fixtures): int {
    $row = $call($admin, 'POST', '/roleplay/admin/catalogs/'.$key, ['revision' => $revision(), 'fields' => $input], 201);
    $id = (int) $row['id'];
    $fixtures[$key][] = $id;
    return $id;
};
$profile = function () use ($call, $owner, &$characterId): array {
    return $call($owner, 'GET', '/roleplay/characters/'.$characterId);
};
$command = function (array $view): array {
    return ['operation_key' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
        'revision' => $view['snapshot']['character']['revision'], 'catalog_revision' => $view['snapshot']['catalog_revision']];
};
$failed = null;
try {
    installedCheck(!$owner->isAdmin() && $owner->can('roleplay.create') && $owner->can('roleplay.view'), 'Existing ordinary player has creation access');
    $call($owner, 'GET', '/roleplay/admin/catalogs/races', [], 403);
    installedCheck(true, 'Ordinary player cannot access admin catalogues');
    $call($admin, 'GET', '/roleplay/admin/catalogs/races');
    installedCheck(true, 'Admin catalogue route loads imported races');
    $perkIds = [];
    foreach ([1, 2, 3] as $level) $perkIds[] = $create('perks', ['name' => '[TEST] Перк '.$level, 'enabled' => true]);
    $levels = array_map(fn ($level, $id) => ['level' => $level, 'perk_id' => $id], [1, 2, 3], $perkIds);
    $charSkill = $create('skills', ['name' => '[TEST] Харизма', 'attribute_id' => 4, 'enabled' => true, 'levels' => $levels]);
    $magicSkill = $create('skills', ['name' => '[TEST] Магия', 'attribute_id' => 3, 'enabled' => true, 'levels' => $levels]);
    $tagId = (int) $db->table('tags')->whereNull('parent_id')->orderBy('id')->value('id');
    installedCheck($tagId > 0, 'Existing application tag available');
    $config = $oldConfig;
    $config['enabled'] = true;
    $config['application']['tag_id'] = $tagId;
    $config['application']['title_template'] = '[TEST] Анкета: {name}';
    $call($admin, 'PUT', '/roleplay/admin/creation', ['revision' => $revision(), 'configuration' => $config]);
    $view = $call($owner, 'GET', '/roleplay/creation/draft');
    $payload = ['first_name' => 'Проверка', 'last_name' => 'Интеграции', 'age' => 21, 'race_id' => 2,
        'base_attributes' => ['physiology' => 1, 'agility' => 1, 'magic' => 3, 'charisma' => 2],
        'skills' => [$charSkill, $magicSkill], 'proficiencies' => [4],
        'spell_ids' => [1, 2], 'racial_spell_ids' => [3],
        'loadout' => ['primary-weapon' => ['legacy-option-1'], 'secondary-gear' => ['legacy-option-5']]];
    $view = $call($owner, 'PUT', '/roleplay/creation/draft', [
        'revision' => $view['draft']['revision'], 'catalog_revision' => $view['catalog']['revision'],
        'rules_hash' => $view['catalog']['rules_hash'], 'step' => 'review', 'payload' => $payload]);
    installedCheck($view['preview']['valid'], 'Full creation preview is valid');
    $createBody = ['operation_key' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
        'draft_id' => $view['draft']['id'], 'draft_revision' => $view['draft']['revision'],
        'catalog_revision' => $view['catalog']['revision'], 'rules_hash' => $view['catalog']['rules_hash']];
    $result = $call($owner, 'POST', '/roleplay/creation', $createBody, 201);
    $characterId = (int) $result['character_id'];
    $discussionId = (int) $db->table('roleplay_characters')->find($characterId)->discussion_id;
    installedCheck($discussionId > 0 && $db->table('posts')->where('discussion_id', $discussionId)->where('type', 'comment')->count() === 1,
        'Creation writes a real application topic with one template post');
    $call($owner, 'POST', '/roleplay/creation', $createBody, 201);
    installedCheck($db->table('roleplay_characters')->where('user_id', $owner->id)->count() === 1, 'Exact create retry does not duplicate character or topic');
    $view = $profile();
    installedCheck($view['review']['mechanics_status'] === 'review' && $view['review']['literature_status'] === 'draft', 'Mechanics starts under review; literature starts as work in progress');
    $view = $call($admin, 'POST', '/roleplay/characters/'.$characterId.'/review', $command($view) + ['part' => 'mechanics', 'action' => 'approve']);
    installedCheck($view['snapshot']['character']['status'] === 'creating', 'Mechanical approval alone does not activate');
    $call($admin, 'POST', '/posts', ['data' => ['type' => 'posts',
        'attributes' => ['content' => '[success title=Принято font=#1E2019 bg=#A9C7AA border=#A9C7AA][/success]'],
        'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $discussionId]]]]], 201);
    $call($admin, 'PATCH', '/discussions/'.$discussionId, ['data' => ['type' => 'discussions', 'id' => (string) $discussionId,
        'attributes' => ['isLocked' => true]]]);
    $view = $profile();
    installedCheck($view['snapshot']['character']['status'] === 'active' && $view['review']['literature_status'] === 'approved',
        'Real moderator post plus topic lock automatically accepts literature and activates');
    $view = $call($admin, 'POST', '/roleplay/characters/'.$characterId.'/management', $command($view) + [
        'action' => 'balance', 'data' => ['resource' => 'experience_active', 'delta' => 20], 'comment' => 'Проверка развития']);
    $view = $profile();
    $advance = $command($view) + ['kind' => 'attribute', 'target_id' => 3, 'rules_hash' => $view['development']['rules_hash']];
    $view = $call($owner, 'POST', '/roleplay/characters/'.$characterId.'/progression', $advance);
    $call($owner, 'POST', '/roleplay/characters/'.$characterId.'/progression', $advance);
    $attribute = $db->table('roleplay_character_attributes')->where('character_id', $characterId)->where('attribute_id', 3)->first();
    installedCheck((int)$attribute->base_value + (int)$attribute->racial_bonus === 5, 'Magic increases exactly once and grants automatic proficiency');
    installedCheck($db->table('roleplay_character_weapon_proficiencies')->where('character_id', $characterId)->where('proficiency_id', 6)->exists(),
        'Automatic Magic weapon proficiency is persisted');
    $item = $db->table('roleplay_character_items')->where('character_id', $characterId)->where('item_id', 1)->first();
    $view = $call($owner, 'POST', '/roleplay/characters/'.$characterId.'/equipment', $command($view) + ['instance_id' => (int)$item->id, 'equipped' => true]);
    installedCheck((bool)$db->table('roleplay_character_items')->find($item->id)->equipped, 'Real equipment route persists selection');
    $view = $call($admin, 'POST', '/roleplay/characters/'.$characterId.'/management', $command($view) + [
        'action' => 'grant_perk', 'data' => ['target_id' => 1, 'source_id' => 1, 'context' => ['race_id' => 1]],
        'comment' => 'Проверка источника']);
    installedCheck($db->table('roleplay_character_perks')->where('character_id', $characterId)->where('perk_id', 1)->exists(), 'Moderator perk award uses a real catalogue source');
    echo "Installed Flarum lifecycle passed\n";
} catch (Throwable $error) {
    $failed = $error;
    fwrite(STDERR, (string) $error."\n");
} finally {
    // Только ID, созданные этим сценарием. Восстановление настроек не меняет справочники импорта.
    if ($characterId !== null) {
        foreach (['character_events', 'character_choices', 'character_balances', 'character_items',
            'character_spells', 'character_perks', 'character_attributes', 'character_skills', 'character_weapon_proficiencies', 'operations'] as $table) {
            $db->table('roleplay_'.$table)->where('character_id', $characterId)->delete();
        }
        $db->table('roleplay_characters')->where('id', $characterId)->delete();
    }
    $db->table('roleplay_creation_drafts')->where('user_id', $owner->id)->delete();
    $db->table('roleplay_actor_states')->where('user_id', $owner->id)->delete();
    if ($discussionId !== null) {
        try { $call($admin, 'DELETE', '/discussions/'.$discussionId, [], 204); }
        catch (Throwable $cleanup) { $failed ??= $cleanup; fwrite(STDERR, "Topic cleanup failed\n"); }
    }
    $settings->save($admin, $revision(), $oldConfig);
    foreach (['skills' => 'skills', 'perks' => 'perks'] as $key => $table) {
        foreach ($fixtures[$key] ?? [] as $id) $db->table('roleplay_'.$table)->where('id', $id)->delete();
    }
    installedCheck($db->table('roleplay_characters')->count() === 0, 'Test character removed; imported catalogues remain');
}
exit($failed === null ? 0 : 1);
