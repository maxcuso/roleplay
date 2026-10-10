<?php

declare(strict_types=1);

// Чистые правила проверяются без запуска Flarum и без базы данных.
spl_autoload_register(function (string $class): void {
    $namespace = 'Maxcuso\\Roleplay\\';
    if (!str_starts_with($class, $namespace)) return;
    $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($namespace))).'.php';
    if (is_file($path)) require_once $path;
});
use Maxcuso\Roleplay\Rules\DevelopmentRules;
use Maxcuso\Roleplay\Rules\SkillEligibility;
use Maxcuso\Roleplay\Rules\ProficiencyEligibility;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "OK: $message\n";
}
function code(array $result, string $expected): void {
    check($result['code'] === $expected, 'Eligibility: '.$expected);
}
$rules = DevelopmentRules::defaults();
$skills = new SkillEligibility($rules);
$proficiencies = new ProficiencyEligibility($rules);
check($rules->maximumAttributeLevel() === 25, 'Maximum attribute level is 25');
foreach (['physiology' => [5, 3], 'agility' => [8, 2], 'magic' => [6, 0], 'charisma' => [8, 0]] as $slug => [$skillCount, $weaponCount]) {
    $expected = ['skills' => $skillCount, 'proficiencies' => $weaponCount];
    check($rules->earnedChoices($slug, 24) === $expected, "$slug choice counts at 24");
    check($rules->earnedChoices($slug, 25) === $expected, "$slug has no ordinary reward at 25");
}
check($rules->automaticProficienciesReached('magic', 4) === [], 'Magic weapon unavailable below 5');
check($rules->automaticProficienciesReached('magic', 5) === ['magic-weapon'], 'Magic weapon automatic at 5');
$attribute = ['id' => 1, 'slug' => 'physiology', 'enabled' => true];
$skill = ['attribute_id' => 1, 'enabled' => true, 'levels' => [
    ['level' => 1, 'perk_id' => 11], ['level' => 2, 'perk_id' => 12], ['level' => 3, 'perk_id' => 13],
]];
$perks = [['id' => 11, 'enabled' => true], ['id' => 12, 'enabled' => true], ['id' => 13, 'enabled' => true]];
$before = serialize([$attribute, $skill, $perks]);
code($skills->evaluate($skill, $attribute, 3, 0, 1, $perks), 'attribute_level_required');
$learn = $skills->evaluate($skill, $attribute, 4, 0, 1, $perks);
check($learn['allowed'] && $learn['action'] === 'learn' && $learn['check_bonus'] === 1 && $learn['grant_perk'],
    'First skill level at first choice threshold');
foreach ([[6, 1, false], [7, 1, true], [14, 2, false], [15, 2, true]] as [$level, $current, $allowed]) {
    check($skills->evaluate($skill, $attribute, $level, $current, 1, $perks)['allowed'] === $allowed,
        "Skill rank boundary attribute $level / skill $current");
}
code($skills->evaluate($skill, $attribute, 25, 3, 1, $perks), 'maximum_skill_level');
code($skills->evaluate($skill, $attribute, 15, 1, 0, $perks), 'no_skill_choices');
code($skills->evaluate($skill, $attribute, 15, -1, 1, $perks), 'invalid_learning_state');
code($skills->evaluate($skill, $attribute, 26, 1, 1, $perks), 'invalid_attribute_level');
code($skills->evaluate(array_replace($skill, ['attribute_id' => 2]), $attribute, 15, 1, 1, $perks), 'wrong_attribute');
code($skills->evaluate(array_replace($skill, ['enabled' => false]), $attribute, 15, 1, 1, $perks), 'definition_disabled');
code($skills->evaluate($skill, array_replace($attribute, ['enabled' => false]), 15, 1, 1, $perks), 'definition_disabled');
code($skills->evaluate($skill, array_replace($attribute, ['slug' => 'unknown']), 15, 1, 1, $perks), 'missing_attribute_rules');
code($skills->evaluate($skill, $attribute, 15, 2, 1, [$perks[0]]), 'missing_level_perk');
$disabledPerks = $perks;
$disabledPerks[2]['enabled'] = false;
code($skills->evaluate($skill, $attribute, 15, 2, 1, $disabledPerks), 'perk_disabled');
$owned = $skills->evaluate($skill, $attribute, 15, 2, 1, $disabledPerks, [13]);
check($owned['allowed'] && !$owned['grant_perk'] && $owned['already_owned_perk'] && $owned['check_bonus'] === 3
    && $owned['consume_choice'], 'Already-owned perk advances skill without duplicate grant, even if disabled');
check(serialize([$attribute, $skill, $perks]) === $before, 'Eligibility does not mutate inputs');
$weapon = ['attribute_id' => 1, 'slug' => 'one-handed', 'enabled' => true];
code($proficiencies->evaluate($weapon, $attribute, 2, false, 1), 'attribute_level_required');
check($proficiencies->evaluate($weapon, $attribute, 3, false, 1)['consume_choice'], 'Normal proficiency spends a choice');
code($proficiencies->evaluate($weapon, $attribute, 3, true, 1), 'proficiency_already_learned');
code($proficiencies->evaluate($weapon, $attribute, 3, false, 0), 'no_proficiency_choices');
$magic = ['id' => 3, 'slug' => 'magic', 'enabled' => true];
$magicWeapon = ['attribute_id' => 3, 'slug' => 'magic-weapon', 'enabled' => true];
$tooEarly = $proficiencies->evaluate($magicWeapon, $magic, 4, false, 0);
check(!$tooEarly['allowed'] && $tooEarly['minimum_attribute_level'] === 5, 'Automatic proficiency explains required threshold');
$automatic = $proficiencies->evaluate($magicWeapon, $magic, 5, false, 0);
check($automatic['allowed'] && $automatic['action'] === 'automatic' && !$automatic['consume_choice'],
    'Automatic proficiency requires no choice');
foreach ([[4, 4], [4, 25]] as $badLevels) {
    $configuration = require __DIR__.'/../resources/rules/development.php';
    $configuration['attributes']['physiology']['skill_choices'] = $badLevels;
    try {
        new DevelopmentRules($configuration);
        throw new RuntimeException('Invalid configuration accepted');
    } catch (InvalidArgumentException) {
        check(true, 'Duplicate or reserved choice threshold rejected');
    }
}
echo "Learning rule checks passed; no persistence performed.\n";