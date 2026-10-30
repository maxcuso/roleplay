<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\State;

/** Подготовленное состояние одной версии персонажа и справочников. Не выполняет SQL и игровые операции. */
final class CharacterSnapshot implements \JsonSerializable
{
    public function __construct(
        public readonly int $catalogRevision,
        public readonly array $character,
        public readonly array $attributes,
        public readonly array $skills,
        public readonly array $proficiencies,
        public readonly array $perks,
        public readonly array $spells,
        public readonly array $inventory,
        public readonly array $equipment,
        public readonly array $missingAttributes,
        public readonly array $balances = [],
        public readonly array $choices = [],
    ) {}

    /**
     * Только базовый расчёт по сохранённым уровням, весу и навыкам.
     * Полный расчёт с эффектами и доступными атаками выполняет CharacterCalculationBuilder.
     */
    public function baselineInput(): array
    {
        if ($this->missingAttributes) {
            throw new \DomainException('Механическое состояние неполно: отсутствуют характеристики.');
        }
        $levels = [];
        foreach ($this->skills as $skill) {
            $levels[$skill['id']] = $skill['level'];
        }
        return [
            'attributes' => $this->attributes,
            // Не прибавляем оборудование второй раз: оно уже входит в инвентарь.
            'inventory' => array_map(fn (array $item) => [
                'id' => $item['id'], 'name' => $item['name'],
                'weight' => $item['weight'], 'quantity' => $item['quantity'],
            ], $this->inventory),
            'skillLevels' => $levels,
        ];
    }

    public function ownedPerkIds(): array
    {
        return array_column($this->perks, 'id');
    }

    public function ownedProficiencyIds(): array
    {
        return array_column($this->proficiencies, 'id');
    }

    public function jsonSerialize(): array
    {
        return [
            'catalog_revision' => $this->catalogRevision,
            'character' => $this->character,
            'attributes' => $this->attributes,
            'skills' => $this->skills,
            'proficiencies' => $this->proficiencies,
            'perks' => $this->perks,
            'spells' => $this->spells,
            'inventory' => $this->inventory,
            'equipment' => $this->equipment,
            'missing_attributes' => $this->missingAttributes,
            'balances' => $this->balances,
            'choices' => $this->choices,
            'pending_calculation' => ['effects', 'attack_modes'],
        ];
    }
}