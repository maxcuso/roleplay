<?php
declare(strict_types=1);

use Flarum\User\User;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\ServerRequest;
use Maxcuso\Roleplay\Api\{AdminCharacterController, ApiProblem};
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Character\Admin\CharacterAdmin;
use Maxcuso\Roleplay\Character\Application\ApplicationTopics;
use Maxcuso\Roleplay\Creation\CreateCharacter;

// Проверяем реальное удаление только на таблицах с отдельным префиксом. Аккаунты и темы не создаём.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void {fwrite(STDERR, (string) $error."\n"); exit(1);});
$original = $container->make(ConnectionInterface::class);
$prefix = 'rpn_'.bin2hex(random_bytes(3)).'_';
if (!preg_match('/^rpn_[0-9a-f]{6}_$/D', $prefix) || $prefix === $original->getTablePrefix()) throw new RuntimeException('Unsafe prefix');
$db = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
$schema = $db->getSchemaBuilder();
$base = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_02_000001_create_base_catalogs.php';
$ranks = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_09_000001_add_ranks.php';
$purpose = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_08_000002_add_item_purposes.php';
$admin = User::findOrFail(1);
$assert = function (bool $ok, string $message): void {if (!$ok) throw new RuntimeException($message);};
try {
    $base['up']($schema); $ranks['up']($schema); $purpose['up']($schema);
    (require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_30_000002_starting_spells.php')['up']($schema);
    (require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_30_000003_literature_contents.php')['up']($schema);
    $registry = new CatalogRegistry($db);
    $create = fn (string $key, array $fields) => $registry->get($key)->save(null,
        (int) $db->table('roleplay_catalog_state')->value('revision'), $fields, $admin)['id'];
    $race = $create('races', ['name' => 'Раса']);
    $attribute = $create('attributes', ['name' => 'Физиология']);
    $skill = $create('skills', ['name' => 'Навык', 'attribute_id' => $attribute, 'enabled' => false]);
    $proficiency = $create('proficiencies', ['name' => 'Владение', 'attribute_id' => $attribute]);
    $source = $create('sources', ['name' => 'Источник']);
    $perk = $create('perks', ['name' => 'Перк']);
    $path = $create('paths', ['name' => 'Путь']);
    $spell = $create('spells', ['name' => 'Заклинание', 'magic_path_id' => $path]);
    $type = $create('item-types', ['name' => 'Тип']);
    $item = $create('items', ['name' => 'Предмет', 'item_type_id' => $type]);
    $now = gmdate('Y-m-d H:i:s');
    $character = fn (string $name) => (int) $db->table('roleplay_characters')->insertGetId([
        'user_id' => $admin->id, 'race_id' => $race, 'first_name' => $name, 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $id = $character('Удаляемый'); $other = $character('Другой');
    $body = ['operation_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'draft_id' => 1,
        'draft_revision' => 1, 'catalog_revision' => 1, 'rules_hash' => str_repeat('a', 64)];
    $hash = hash('sha256', json_encode(['create_character', (int) $admin->id, 1, 1, 1, $body['rules_hash']], JSON_THROW_ON_ERROR));
    $sections = ['attributes', 'skills', 'weapon_proficiencies', 'perks', 'spells', 'items', 'choices', 'balances', 'events'];
    foreach ([$id, $other] as $target) {
        $key = $target === $id ? $body['operation_key'] : 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $db->table('roleplay_operations')->insert(['id' => $key, 'type' => 'create_character', 'actor_user_id' => $admin->id,
            'character_id' => $target, 'request_hash' => $hash, 'result' => json_encode(['character_id' => $target]), 'created_at' => $now]);
        $rows = [
            'attributes' => ['attribute_id' => $attribute, 'base_value' => 3, 'racial_bonus' => 0],
            'skills' => ['skill_id' => $skill, 'level' => 1],
            'weapon_proficiencies' => ['proficiency_id' => $proficiency],
            'perks' => ['perk_id' => $perk, 'source_id' => $source, 'source_context' => '{}'],
            'spells' => ['spell_id' => $spell], 'items' => ['item_id' => $item, 'quantity' => 1, 'equipped' => true],
            'choices' => ['attribute_id' => $attribute, 'kind' => 'skill', 'threshold' => 1],
            'balances' => ['resource_key' => 'gold', 'amount' => 10, 'updated_at' => $now],
            'events' => ['actor_user_id' => $admin->id, 'operation_id' => $key, 'type' => 'character_created', 'payload' => '{}', 'created_at' => $now],
        ];
        foreach ($rows as $section => $fields) $db->table('roleplay_character_'.$section)->insert(['character_id' => $target] + $fields);
    }
    $controller = new AdminCharacterController(new CharacterAdmin($db, $container->make(UrlGenerator::class)), $registry);
    $request = fn (User $actor, int $revision) => RequestUtil::withActor((new ServerRequest())->withMethod('DELETE'), $actor)
        ->withQueryParams(['id' => $id])->withParsedBody(['revision' => $revision]);
    try {
        $controller->handle($request(new User(), 1)); throw new RuntimeException('Guest deletion allowed');
    } catch (\Flarum\User\Exception\PermissionDeniedException) {}
    $assert($controller->handle($request($admin, 2))->getStatusCode() === 409, 'Stale version rejected');
    $assert($db->table('roleplay_character_items')->where('character_id', $id)->count() === 1, 'Rejected delete keeps state');
    $assert($controller->handle($request($admin, 1))->getStatusCode() === 200, 'Delete API succeeds');
    $assert(!$db->table('roleplay_characters')->where('id', $id)->exists(), 'Character removed');
    foreach ($sections as $section) {
        $assert(!$db->table('roleplay_character_'.$section)->where('character_id', $id)->exists(), 'Removed '.$section);
        $assert($db->table('roleplay_character_'.$section)->where('character_id', $other)->count() === 1, 'Preserved other '.$section);
    }
    $assert($db->table('roleplay_operations')->where('id', $body['operation_key'])->value('character_id') === null, 'UUID retained without FK');
    $assert($db->table('roleplay_perks')->where('id', $perk)->exists() && User::find($admin->id) !== null, 'Definitions and account preserved');
    $topics = new class implements ApplicationTopics {
        public function create(User $actor, int $tagId, string $title, string $content): int {throw new RuntimeException('Unexpected topic creation');}
        public function inspect(User $actor, int $discussionId): array {throw new RuntimeException('Unexpected topic access');}
    };
    try {
        (new CreateCharacter($db, $topics))->create($admin, $body); throw new RuntimeException('Deleted creation replay accepted');
    } catch (ApiProblem $error) {$assert($error->problemCode === 'character_deleted', 'Old creation cannot resurrect character');}
    $assert($controller->handle($request($admin, 1))->getStatusCode() === 404, 'Repeated deletion reports missing character');
    echo "Character deletion passed: admin-only, revision, related state, isolation and creation replay.\n";
} finally {
    // Только таблицы этой проверки; рабочий префикс и его данные не меняются.
    foreach (['character_events', 'operations', 'character_balances', 'character_choices', 'creation_drafts', 'actor_states', 'creation_config',
        'character_items', 'character_spells', 'character_perks', 'character_attributes', 'character_skills', 'character_weapon_proficiencies',
        'characters', 'ranks', 'perk_effects', 'item_effects', 'effects', 'skill_levels', 'races', 'items', 'skills', 'weapon_proficiencies',
        'spells', 'perks', 'perk_sources', 'item_types', 'magic_paths', 'attributes', 'catalog_state'] as $name) $schema->dropIfExists('roleplay_'.$name);
}
