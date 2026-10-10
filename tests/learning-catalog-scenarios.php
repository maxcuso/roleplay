<?php
// Запускается из check-base-catalogs.php и использует только его временные таблицы.
use Maxcuso\Roleplay\Catalog\Skills\SkillLevelStore;

$physiologyId = (int) $db->table('roleplay_attributes')->where('slug', 'physiology')->value('id');
$agilityId = (int) $db->table('roleplay_attributes')->where('slug', 'agility')->value('id');
$skillId = (int) $db->table('roleplay_skills')->where('slug', 'unarmed-combat')->value('id');
$perkC = $create('perks', ['name' => 'Третий уровень', 'enabled' => true]);
$linked = [
    ['level' => 1, 'perk_id' => $perkA],
    ['level' => 2, 'perk_id' => $perkB],
    ['level' => 3, 'perk_id' => $perkC],
];
$fields = ['name' => 'Рукопашный бой', 'attribute_id' => $physiologyId, 'enabled' => true, 'levels' => $linked];
$api('PUT', 'skills', ['revision' => $revision(), 'fields' => $fields], ['id' => $skillId]);
$result = $api('GET', 'skills', [], ['attribute' => (string) $physiologyId])['data'];
check($result['total'] === 2 && $result['rows'][0]['levels'] === $linked, 'Skill levels round-trip as typed references');
check(!array_key_exists('icon', $result['rows'][0]), 'Skill has no duplicate icon field');
check(count($result['lookups']['skills'][0]['levels']) === 3, 'Skill lookups contain level links');

foreach ([
    $fields + ['icon' => 'fas fa-star'],
    array_replace($fields, ['levels' => [['level' => 1, 'perk_id' => $perkA], ['level' => 1, 'perk_id' => $perkB]]]),
    array_replace($fields, ['levels' => [['level' => 1, 'perk_id' => 999999]]]),
    array_replace($fields, ['attribute_id' => 999999]),
] as $invalid) {
    $before = $revision();
    $api('PUT', 'skills', ['revision' => $before, 'fields' => $invalid], ['id' => $skillId], 422);
    check($revision() === $before, 'Invalid skill update does not publish a revision');
}
$api('GET', 'skills', [], ['attribute' => 'physiology'], 422);
$draft = $create('skills', ['name' => 'Черновик навыка', 'attribute_id' => $physiologyId, 'enabled' => false]);
$api('PATCH', 'skills', ['revision' => $revision(), 'enabled' => true], ['id' => $draft], 422);
check(!(bool) $db->table('roleplay_skills')->find($draft)->enabled, 'Incomplete draft cannot be enabled');
$api('PATCH', 'skills', ['revision' => $revision(), 'enabled' => false], ['id' => $skillId]);
$api('PATCH', 'skills', ['revision' => $revision(), 'enabled' => true], ['id' => $skillId]);
check($db->table('roleplay_skill_levels')->where('skill_id', $skillId)->count() === 3, 'Status toggle keeps skill links');
$api('DELETE', 'perks', ['revision' => $revision()], ['id' => $perkC], 409);
$api('DELETE', 'attributes', ['revision' => $revision()], ['id' => $agilityId], 409);

// Ошибка последней связанной записи должна отменить и название, и удаление старых привязок, и версию.
$before = $revision();
$levelStore = new SkillLevelStore($db);
try {
    $store->save('skills', $skillId, $before, [], $admin,
        fn () => ['name' => 'Не должно сохраниться'],
        fn (int $id) => $levelStore->replace($id, [['level' => 1, 'perk_id' => 999999]]));
    throw new RuntimeException('Related save failure was not raised');
} catch (\Illuminate\Database\QueryException) {
    check($revision() === $before, 'Related save failure rolls back revision');
    check($db->table('roleplay_skills')->find($skillId)->name === $fields['name'], 'Related save failure rolls back parent');
    check($levelStore->forSkills([$skillId])[$skillId] === $linked, 'Related save failure restores old level links');
}
$api('PUT', 'perks', ['revision' => $revision(), 'fields' => ['name' => 'Переименованный третий перк']], ['id' => $perkC]);
check((int) $db->table('roleplay_skill_levels')->where('skill_id', $skillId)->where('level', 3)->value('perk_id') === $perkC,
    'Perk rename preserves its skill link');
$before = $revision();
check($seed->run($admin) === 0 && $revision() === $before && $levelStore->forSkills([$skillId])[$skillId] === $linked,
    'Repeated seed preserves configured links and version');

$proficiency = $create('proficiencies', ['name' => 'Проверка владения', 'attribute_id' => $physiologyId, 'enabled' => true]);
foreach (['level' => 1, 'damage_units' => 3, 'icon' => 'fas fa-star'] as $key => $value) {
    $api('PUT', 'proficiencies', ['revision' => $revision(), 'fields' => [
        'name' => 'Проверка владения', 'attribute_id' => $physiologyId, $key => $value,
    ]], ['id' => $proficiency], 422);
}
check($api('GET', 'proficiencies', [], ['attribute' => (string) $physiologyId])['data']['total'] === 4,
    'Proficiency attribute filter includes three seeded and one new record');
$api('PATCH', 'proficiencies', ['revision' => $revision(), 'enabled' => false], ['id' => $proficiency]);
$api('DELETE', 'proficiencies', ['revision' => $revision()], ['id' => $proficiency]);
$api('DELETE', 'skills', ['revision' => $revision()], ['id' => $draft]);
check(!$db->table('roleplay_skill_levels')->where('skill_id', $draft)->exists(), 'Deleting unused draft removes only its own level links');
foreach ([
    ['skill_id' => $skillId, 'level' => 4, 'perk_id' => $perkA],
    ['skill_id' => $skillId, 'level' => 1, 'perk_id' => $perkA],
] as $invalid) {
    try {
        $db->table('roleplay_skill_levels')->insert($invalid);
        throw new RuntimeException('Skill level database constraint missing');
    } catch (\Illuminate\Database\QueryException) {
        check(true, 'Database rejects invalid or duplicate skill level');
    }
}