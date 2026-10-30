<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Creation;

use Maxcuso\Roleplay\Rules\DevelopmentRules;
use Maxcuso\Roleplay\Rules\DevelopmentConfiguration;

/** Координатор чистых проверок. Не читает SQL, не доверяет клиентским итогам и не делает выдач. */
final class CreationPlanBuilder
{
    private readonly CreationValidator $validator;
    private readonly TrainingCreationPlan $training;
    private readonly AbilityCreationPlan $abilities;
    private readonly LoadoutCreationPlan $loadout;

    public function __construct(?DevelopmentConfiguration $configuration = null)
    {
        $rules = DevelopmentRules::defaults($configuration);
        $this->validator = new CreationValidator($rules);
        $this->training = new TrainingCreationPlan($rules);
        $this->abilities = new AbilityCreationPlan($configuration);
        $this->loadout = new LoadoutCreationPlan();
    }

    public function build(array $payload, array $catalog, array $configuration): array
    {
        $attributes = $this->validator->attributes($payload, $catalog, $configuration);
        $errors = ['basics' => $attributes['basic_errors'], 'attributes' => $attributes['attribute_errors'], 'training' => [], 'perks' => [], 'spells' => [], 'loadout' => []];
        $race = $attributes['race'];
        $racialPerks = $race === null ? ['perk_grants' => [], 'errors' => []]
            : $this->abilities->racialPerks($payload, $race, $catalog);
        $training = $attributes['valid']
            ? $this->training->build($payload, $catalog, $attributes['attributes'], array_column($racialPerks['perk_grants'], 'id'), $race)
            : ['skills' => [], 'proficiencies' => [], 'choices' => [], 'perk_grants' => [], 'errors' => []];
        $perks = $this->abilities->perkSources(array_merge($racialPerks['perk_grants'], $training['perk_grants']),
            $catalog, $configuration);
        $spells = $race === null ? ['spells' => [], 'spell_choices' => [], 'errors' => []]
            : $this->abilities->spells($payload, $race, $attributes['attributes'], $catalog, $configuration);
        $loadout = $this->loadout->build($payload, $catalog, $configuration);
        $errors['training'] = $training['errors'];
        $errors['perks'] = array_merge($racialPerks['errors'], $perks['errors']);
        $errors['spells'] = $spells['errors'];
        $errors['loadout'] = $loadout['errors'];
        return [
            'valid' => !array_filter($errors), 'errors' => $errors,
            'attributes' => $attributes['attributes'], 'skills' => $training['skills'],
            'proficiencies' => $training['proficiencies'], 'choices' => $training['choices'],
            'perks' => $perks['perks'], 'spells' => $spells['spells'], 'spell_choices' => $spells['spell_choices'],
            'items' => $loadout['items'],
            'balances' => ['experience_active' => $configuration['starting_experience'],
                'experience_total' => $configuration['starting_experience'], 'gold' => $configuration['starting_gold']],
        ];
    }
}
