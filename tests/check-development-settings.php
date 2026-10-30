<?php
declare(strict_types=1);

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Rules\DevelopmentConfigurationStore;
use Maxcuso\Roleplay\Rules\DevelopmentConfiguration;
use Maxcuso\Roleplay\Catalog\SeedBaseCatalogs;
use Maxcuso\Roleplay\Catalog\Shared\{CatalogRevision, DefinitionStore};
use Maxcuso\Roleplay\Catalog\Races\RaceCatalog;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;
use Maxcuso\Roleplay\Character\Progression\CharacterProgression;

// Новые настройки и персонаж существуют только в таблицах с отдельным проверенным префиксом.
$site = require __DIR__.'/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $error): void {fwrite(STDERR, (string) $error."\n"); exit(1);});
$original = $container->make(ConnectionInterface::class);
$prefix = 'rpd_'.bin2hex(random_bytes(3)).'_';
if (!preg_match('/^rpd_[0-9a-f]{6}_$/D', $prefix) || $prefix === $original->getTablePrefix()) throw new RuntimeException('Unsafe prefix.');
$db = new ($original::class)($original->getPdo(), $original->getDatabaseName(), $prefix, $original->getConfig());
$schema = $db->getSchemaBuilder();
$admin = User::findOrFail(1);
$assert = function (bool $ok, string $message): void {if (!$ok) throw new RuntimeException($message);};
$revision = fn () => (int) $db->table('roleplay_catalog_state')->where('id', 1)->value('revision');
$migration = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_12_000002_development_configuration.php';
try {
    foreach (['2026_10_02_000001_create_base_catalogs', '2026_10_08_000001_track_creation_grants',
        '2026_10_08_000002_add_item_purposes', '2026_10_09_000001_add_ranks', '2026_10_09_000002_inline_effects',
        '2026_10_10_000001_fractional_item_prices', '2026_10_10_000003_fractional_spell_distances',
        '2026_10_10_000002_roleplayer_statistics', '2026_10_17_000001_race_presentation', '2026_10_22_000002_race_quote_image', '2026_10_30_000002_starting_spells', '2026_10_30_000003_literature_contents'] as $name) (require __DIR__.'/fixtures/migrations-2026-10-30/'.$name.'.php')['up']($schema);
    (new SeedBaseCatalogs($db))->run($admin);
    $definitions = new DefinitionStore($db, new CatalogRevision($db));
    $race = (new RaceCatalog($definitions))->save(null, $revision(), ['name' => 'Тестовая раса', 'enabled' => true], $admin)['id'];
    $now = gmdate('Y-m-d H:i:s');
    $id = $db->table('roleplay_characters')->insertGetId(['user_id' => $admin->id, 'race_id' => $race, 'first_name' => 'Тест',
        'status' => 'active', 'mechanics_status' => 'approved', 'literature_status' => 'approved', 'literature_approved' => true,
        'created_at' => $now, 'updated_at' => $now]);
    $attributeIds = $db->table('roleplay_attributes')->pluck('id', 'slug')->all();
    foreach ($attributeIds as $slug => $attributeId) $db->table('roleplay_character_attributes')->insert([
        'character_id' => $id, 'attribute_id' => $attributeId, 'base_value' => $slug === 'physiology' || $slug === 'magic' ? 3 : 1,
        'racial_bonus' => 0, 'created_at' => $now, 'updated_at' => $now]);
    foreach (['experience_active' => 1000, 'experience_total' => 1000, 'gold' => 0] as $resource => $amount) {
        $db->table('roleplay_character_balances')->insert(['character_id' => $id, 'resource_key' => $resource, 'amount' => $amount, 'updated_at' => $now]);
    }
    // Старый выбор остаётся тем же объектом после миграции, даже если позднее меняется его порог.
    $db->table('roleplay_character_choices')->insert(['character_id' => $id, 'attribute_id' => $attributeIds['physiology'],
        'kind' => 'skill', 'threshold' => 4, 'target_id' => null, 'target_level' => null]);
    $migration['up']($schema);
    $assert($db->table('roleplay_character_choices')->value('rule_key') === 'physiology:skill:4', 'Old choice key preserved.');
    $store = new DevelopmentConfigurationStore($db);
    // Текстовая миграция не меняет игровые пороги, значения и постоянные ключи.
    $legacyDescriptions = $store->read()->values;
    foreach ($legacyDescriptions['attributes'] as &$attribute) {
        unset($attribute['initial_description']);
        foreach ($attribute['rules'] as &$rule) unset($rule['description']);
        unset($rule);
    }
    unset($attribute);
    $db->table('roleplay_development_config')->where('id', 1)->update(['configuration' => json_encode($legacyDescriptions, JSON_THROW_ON_ERROR)]);
    $beforeDescriptions = $store->read()->values;
    $descriptionMigration = require __DIR__.'/fixtures/migrations-2026-10-30/2026_10_22_000001_development_descriptions.php';
    $descriptionMigration['up']($schema);
    $withDescriptions = $store->read()->values;
    $stripDescriptions = function (array $values): array {
        foreach ($values['attributes'] as &$attribute) {
            unset($attribute['initial_description']);
            foreach ($attribute['rules'] as &$rule) unset($rule['description']);
            unset($rule);
        }
        unset($attribute);
        return $values;
    };
    $assert($stripDescriptions($beforeDescriptions) === $stripDescriptions($withDescriptions), 'Description migration preserves every game value.');
    $assert(str_contains($withDescriptions['attributes']['physiology']['rules'][0]['description'], '{damage}'), 'Damage remains a live substitution.');
    $customText = $withDescriptions;
    $customText['attributes']['physiology']['rules'][0]['description'] = 'Урон — {damage}';
    $assert((new DevelopmentConfiguration($customText))->values['attributes']['physiology']['rules'][0]['description'] === 'Урон — {damage}', 'Custom wording survives normalization.');
    $customText['attributes']['physiology']['rules'][0]['description'] = 'Урон — {unknown}';
    try {new DevelopmentConfiguration($customText); throw new RuntimeException('Unknown text token accepted.');}
    catch (ApiProblem $error) {$assert($error->problemCode === 'invalid_development_configuration', 'Unknown substitutions are rejected.');}
    $defaults = $store->read();
    $assert($defaults->cost(6) === 10 && count($defaults->learning()->skillChoiceLevels('physiology')) === 5, 'Defaults match existing balance.');
    $assert($defaults->values['spell_learning_costs'] === [1 => 5, 2 => 10, 3 => 15, 4 => 20, 5 => 30], 'Old settings expose agreed learning costs.');
    $learningPrices = $defaults->values;
    $learningPrices['spell_learning_costs'][2] = 17;
    $assert((new \Maxcuso\Roleplay\Rules\DevelopmentConfiguration($learningPrices))->values['spell_learning_costs'][2] === 17,
        'Custom learning price survives normalization.');
    $values = $defaults->values;
    foreach ($values['attributes']['physiology']['rules'] as &$rule) {
        if ($rule['id'] === 'physiology:skill:4') $rule['level'] = 6;
    }
    unset($rule);
    $values['attributes']['physiology']['rules'][] = ['id' => 'test:skill:7', 'level' => 7, 'type' => 'skill', 'value' => 2];
    $values['attributes']['physiology']['growth']['stamina_max']['initial'] = 20;
    $values['attributes']['physiology']['growth']['carry_capacity']['per_level'] = 2.5;
    $values['experience_costs'][1]['cost'] = 17;
    $values['skill_minimums'][2] = 8;
    foreach ($values['attributes']['magic']['rules'] as &$rule) if ($rule['id'] === 'magic:circle:3') $rule['level'] = 4;
    unset($rule);
    $oldRevision = $revision();
    $store->save($admin, $oldRevision, $values);
    try {$store->save($admin, $oldRevision, $values); throw new RuntimeException('Stale save accepted.');}
    catch (ApiProblem $error) {$assert($error->problemCode === 'catalog_revision_conflict', 'Stale save rejected.');}
    $assert($db->table('roleplay_character_choices')->count() === 1, 'Editing settings does not rewrite earned choices.');
    $profile = new CharacterProfile($db);
    $view = $profile->read($id, $admin);
    $assert($view['calculation']['stats']['stamina_max']->value === 24, 'Profile uses edited resource formula.');
    $assert($view['calculation']['stats']['carry_capacity']->value === 10.0, 'Fractional growth calculated.');
    $assert($view['calculation']['magic_circle'] === 0, 'Profile uses edited acquired Magic circle.');
    $development = new CharacterProgression($db);
    $counter = 0;
    $increase = function () use ($profile, $id, $admin, $development, $attributeIds, &$counter) {
        $view = $profile->read($id, $admin);
        $body = ['operation_key' => sprintf('77777777-7777-4777-8777-%012d', ++$counter),
            'revision' => $view['snapshot']['character']['revision'], 'catalog_revision' => $view['snapshot']['catalog_revision'],
            'rules_hash' => $view['development']['rules_hash'], 'kind' => 'attribute', 'target_id' => $attributeIds['physiology']];
        return $development->change($id, $admin, $body);
    };
    for ($level = 4; $level <= 7; $level++) $increase();
    $assert($db->table('roleplay_character_choices')->where('rule_key', 'physiology:skill:4')->count() === 1, 'Moved rule does not award the same choice again.');
    $assert($db->table('roleplay_character_choices')->where('rule_key', 'test:skill:7')->count() === 2, 'Quantity creates two independent choices.');
    $assert((int) $db->table('roleplay_character_balances')->where('resource_key', 'experience_active')->value('amount') === 960, 'Edited level price is charged.');
    $profile->read($id, $admin); // Два выбора одного уровня корректно собираются в снимок.
    $bad = $store->read()->values; $bad['maximum_level'] = 6; $bad['skill_minimums'] = [1 => 1, 2 => 2, 3 => 3];
    foreach ($bad['attributes'] as &$attribute) $attribute['rules'] = array_values(array_filter($attribute['rules'], fn ($rule) => $rule['level'] < 6));
    unset($attribute);
    try {$store->save($admin, $revision(), $bad); throw new RuntimeException('Invalid bounds accepted.');}
    catch (ApiProblem $error) {$assert($error->problemCode === 'development_maximum_in_use', 'Existing acquired levels are protected.');}
    $controller = new \Maxcuso\Roleplay\Api\DevelopmentConfigurationController($db);
    $put = \Flarum\Http\RequestUtil::withActor((new \Laminas\Diactoros\ServerRequest())->withMethod('PUT')
        ->withParsedBody(['revision' => $revision(), 'configuration' => $store->read()->values]), $admin);
    $assert($controller->handle($put)->getStatusCode() === 200, 'PUT API accepts valid settings.');
    $assert($controller->handle($put)->getStatusCode() === 409, 'PUT API rejects a stale version.');
    try {
        $controller->handle(\Flarum\Http\RequestUtil::withActor((new \Laminas\Diactoros\ServerRequest())->withMethod('GET'), new User()));
        throw new RuntimeException('Guest settings read accepted.');
    } catch (\Flarum\User\Exception\PermissionDeniedException) {}
    $request = \Flarum\Http\RequestUtil::withActor((new \Laminas\Diactoros\ServerRequest())->withMethod('GET'), $admin);
    $response = $controller->handle($request);
    $fixture = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['data'];
    // Мастер и профиль должны использовать одни настройки, включая ещё незаполненный черновик.
    $catalog = (new \Maxcuso\Roleplay\Creation\CreationCatalog($db))->read(fn ($catalog) => $catalog);
    $builder = new \Maxcuso\Roleplay\Creation\CreationPlanBuilder($catalog['development']);
    $empty = \Maxcuso\Roleplay\Creation\CreationPayload::empty();
    $assert(!$builder->build($empty, $catalog['definitions'], $catalog['configuration'])['valid'], 'Incomplete creation draft remains readable.');
    $payload = (new \Maxcuso\Roleplay\Creation\CreationPayload())->normalize(['first_name' => 'Новый', 'age' => 20, 'race_id' => $race,
        'base_attributes' => ['physiology' => 2, 'agility' => 1, 'magic' => 3, 'charisma' => 1]]);
    $plan = $builder->build($payload, $catalog['definitions'], $catalog['configuration']);
    $assert($plan['valid'] && $plan['spell_choices']['ordinary_count'] === 0, 'Creation uses changed circle thresholds: '.json_encode($plan['errors'], JSON_UNESCAPED_UNICODE));
    $review = (new \Maxcuso\Roleplay\Creation\CreationReview($catalog['development']))->build($payload, $catalog['definitions'], $plan, $catalog['revision']);
    $assert($review['calculation']['stats']['stamina_max']->value === 22 && $review['calculation']['magic_circle'] === 0, 'Creation review uses changed formulas.');
    // Клиентский пример содержит именно возвращаемые API поля; игровые числа восстанавливаем исходными.
    $fixture['configuration'] = DevelopmentConfiguration::defaults()->values;
    file_put_contents(__DIR__.'/.runtime/development-view.json', json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    echo "Development settings: defaults, version conflict, formulas, circle, prices, stable choices and quantity passed.\n";
} finally {
    $schema->disableForeignKeyConstraints();
    foreach ($schema->getTableListing() as $table) {
        if (str_starts_with($table, $prefix.'roleplay_')) $schema->drop(substr($table, strlen($prefix)));
    }
    $schema->enableForeignKeyConstraints();
}
