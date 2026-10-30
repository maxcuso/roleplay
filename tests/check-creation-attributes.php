<?php

declare(strict_types=1);

// Чистая проверка правил: не подключает форум и не создаёт записей в базе.
require __DIR__.'/../src/Rules/DevelopmentRules.php';
require __DIR__.'/../src/Creation/CreationValidator.php';

use Maxcuso\Roleplay\Creation\CreationValidator;
use Maxcuso\Roleplay\Rules\DevelopmentRules;

$rules = DevelopmentRules::defaults();
$validator = new CreationValidator($rules);
$slugs = $rules->attributeSlugs();
$catalog = [
    'attributes' => array_map(static fn ($slug) => ['id' => array_search($slug, $slugs) + 1,
        'slug' => $slug, 'name' => $slug, 'enabled' => true], $slugs),
    'races' => [1 => ['enabled' => true, 'min_age' => null, 'max_age' => null,
        'rules' => ['fixed_bonuses' => ['physiology' => 2]]]],
];
$payload = ['first_name' => 'Проверка', 'age' => 20, 'race_id' => 1, 'racial_attributes' => []];
$config = ['attribute_distribution' => [3, 2, 1, 1]];
$check = static function (array $base, bool $expected) use ($validator, $catalog, $payload, $config): array {
    $result = $validator->attributes($payload + ['base_attributes' => $base], $catalog, $config);
    if ($result['valid'] !== $expected) {
        throw new RuntimeException('Unexpected starting allocation result: '.json_encode($base));
    }
    return $result;
};
foreach ([[3, 2, 1, 1], [2, 2, 2, 1], [1, 2, 2, 2]] as $values) {
    $check(array_combine($slugs, $values), true);
}
foreach ([[2, 2, 2, 2], [1, 1, 1, 1], [4, 1, 1, 1], [0, 3, 3, 1], [2.5, 1.5, 2, 1], [null, 3, 3, 1]] as $values) {
    $check(array_combine($slugs, $values), false);
}
$check(['physiology' => 3, 'agility' => 2, 'magic' => 1, 'unknown' => 1], false);
$racial = $check(array_combine($slugs, [2, 2, 2, 1]), true);
if ($racial['attributes']['physiology']['level'] !== 4) {
    throw new RuntimeException('Racial bonuses must be added outside the starting budget and bounds.');
}
// Продолжительность жизни описывает расу, а не минимальный возраст героя.
$ageCatalog = $catalog;
$ageCatalog['races'][1]['min_age'] = 100;
$ageCatalog['races'][1]['max_age'] = 200;
foreach ([0 => true, 20 => true, 200 => true, 201 => false] as $age => $expected) {
    $result = $validator->attributes(array_replace($payload, ['age' => $age,
        'base_attributes' => array_combine($slugs, [2, 2, 2, 1])]), $ageCatalog, $config);
    if ($result['valid'] !== $expected) throw new RuntimeException('Only the lifespan maximum may limit age: '.$age);
}
$ageCatalog['races'][1]['max_age'] = null;
$result = $validator->attributes($payload + ['base_attributes' => array_combine($slugs, [2, 2, 2, 1])], $ageCatalog, $config);
if (!$result['valid']) throw new RuntimeException('An empty lifespan maximum must not restrict character age.');

echo "Starting allocation and lifespan: free combinations, budget, bounds, racial bonuses and maximum-only age check passed.\n";
