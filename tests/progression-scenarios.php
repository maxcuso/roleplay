<?php
// Используем созданного временного персонажа; не меняем рабочие анкеты форума.
use Maxcuso\Roleplay\Character\Progression\CharacterProgression;
use Maxcuso\Roleplay\Rules\ExperienceCosts;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;
foreach ([2 => 3, 5 => 3, 6 => 10, 10 => 10, 11 => 25, 15 => 25, 16 => 100, 20 => 100, 21 => 100, 25 => 100] as $level => $cost) {
    check(ExperienceCosts::forLevel($level) === $cost, 'Cost for acquired level '.$level);
}
$progression = new CharacterProgression($db);
$progressId = $profileId;
$progressProfile = new CharacterProfile($db, $topics);
$progressIndex = 0;
$progressBody = function (string $kind, int $target) use ($progressId, $progressProfile, $owner, &$progressIndex): array {
    $view = $progressProfile->read($progressId, $owner);
    return ['operation_key' => sprintf('66666666-6666-4666-8666-%012d', ++$progressIndex),
        'revision' => $view['snapshot']['character']['revision'], 'catalog_revision' => $view['snapshot']['catalog_revision'],
        'kind' => $kind, 'target_id' => $target, 'rules_hash' => $view['development']['rules_hash']];
};
$db->table('roleplay_character_balances')->where('character_id', $progressId)->whereIn('resource_key', ['experience_active', 'experience_total'])
    ->update(['amount' => 1000]);
$db->table('roleplay_character_attributes')->where('character_id', $progressId)->where('attribute_id', $physiologyId)
    ->update(['base_value' => 3, 'racial_bonus' => 0]);
$db->table('roleplay_character_choices')->where('character_id', $progressId)->where('attribute_id', $physiologyId)->delete();
$upgrade = $progressBody('attribute', $physiologyId);
$result = $progression->change($progressId, $owner, $upgrade);
check($progression->change($progressId, $owner, $upgrade) === $result, 'Progression exact retry is idempotent');
$state = $snapshotBuilder->build($progressId);
check($state->attributes['physiology']['level'] === 4 && $state->balances['experience_active'] === 997
    && $state->balances['experience_total'] === 1000, 'Attribute spends only active XP');
check($db->table('roleplay_character_choices')->where('character_id', $progressId)->where('attribute_id', $physiologyId)
    ->where('kind', 'skill')->where('threshold', 4)->count() === 1, 'New threshold opens one choice');
$oldRules = $progressBody('attribute', $physiologyId);
$oldRules['rules_hash'] = str_repeat('0', 64);
rejected(fn () => $progression->change($progressId, $owner, $oldRules), 'progression_rules_changed');
// Этот персонаж ещё не обучен физиологическому навыку.
$db->table('roleplay_character_skills')->where('character_id', $progressId)->where('skill_id', $startPhysSkill)->delete();
$learn = $progressBody('skill', $startPhysSkill);
$progression->change($progressId, $owner, $learn);
check((int) $db->table('roleplay_character_skills')->where('character_id', $progressId)->where('skill_id', $startPhysSkill)->value('level') === 1,
    'Skill I can be learned at earned threshold');
rejected(fn () => $progression->change($progressId, $owner, $progressBody('skill', $startPhysSkill)), 'attribute_level_required');
check($db->table('roleplay_character_choices')->where('character_id', $progressId)->where('target_id', $startPhysSkill)
    ->where('kind', 'skill')->count() === 1, 'Learning consumes exactly one choice');
rejected(fn () => $progression->change($progressId, $moderator, $progressBody('attribute', $physiologyId)), 'progression_forbidden');
// Вторая и третья ступень добавляют перки, не заменяют уже выданный первый.
$perksBefore = $snapshotBuilder->build($progressId)->ownedPerkIds();
foreach ([8 => 2, 15 => 3] as $level => $skillLevel) {
    $db->table('roleplay_character_attributes')->where('character_id', $progressId)->where('attribute_id', $physiologyId)->update(['base_value' => $level]);
    $threshold = $skillLevel === 2 ? 8 : 13;
    $db->table('roleplay_character_choices')->insert(['character_id' => $progressId, 'attribute_id' => $physiologyId,
        'kind' => 'skill', 'threshold' => $threshold, 'target_id' => null, 'target_level' => null]);
    $progression->change($progressId, $owner, $progressBody('skill', $startPhysSkill));
    check((int) $db->table('roleplay_character_skills')->where('character_id', $progressId)->where('skill_id', $startPhysSkill)->value('level') === $skillLevel,
        'Skill reaches level '.$skillLevel.' through sequential choice');
}
check(!array_diff($perksBefore, $snapshotBuilder->build($progressId)->ownedPerkIds()), 'Higher skill preserves all earlier perks');
$magicId = (int) $db->table('roleplay_attributes')->where('slug', 'magic')->value('id');
$magicProficiency = (int) $db->table('roleplay_weapon_proficiencies')->where('slug', 'magic-weapon')->value('id');
$db->table('roleplay_character_attributes')->where('character_id', $progressId)->where('attribute_id', $magicId)->update(['base_value' => 4, 'racial_bonus' => 0]);
$progression->change($progressId, $owner, $progressBody('attribute', $magicId));
check($db->table('roleplay_character_weapon_proficiencies')->where('character_id', $progressId)->where('proficiency_id', $magicProficiency)->exists(),
    'Magic 5 automatically grants magical proficiency');
$db->table('roleplay_character_attributes')->where('character_id', $progressId)->where('attribute_id', $physiologyId)
    ->update(['base_value' => 24]);
$last = $progressBody('attribute', $physiologyId);
$progression->change($progressId, $owner, $last);
check($snapshotBuilder->build($progressId)->attributes['physiology']['level'] === 25, 'Special level 25 costs 100 and is purchasable');
check(!$db->table('roleplay_character_choices')->where('character_id', $progressId)->where('threshold', 25)->exists(),
    'Level 25 does not create ordinary choices');
rejected(fn () => $progression->change($progressId, $owner, $progressBody('attribute', $physiologyId)), 'progression_unavailable');
check($progressProfile->read($progressId, $visitor)['development'] === null, 'Development options remain private to owner');
echo "Progression prices, choices, ceilings and retries passed.\n";