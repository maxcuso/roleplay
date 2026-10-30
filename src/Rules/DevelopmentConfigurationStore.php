<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Rules;

use Illuminate\Database\ConnectionInterface;
use Flarum\User\User;
use Maxcuso\Roleplay\Api\{Access, ApiProblem};
use Maxcuso\Roleplay\Catalog\Shared\{CatalogRegistry, CatalogRevision};

/** Одна версия с каталогами: открытый мастер и подтверждение повышения замечают изменение баланса. */
final class DevelopmentConfigurationStore
{
    public function __construct(private readonly ConnectionInterface $db) {}
    public function read(): DevelopmentConfiguration
    {
        $json = $this->db->table('roleplay_development_config')->where('id', 1)->value('configuration');
        if ($json === null) throw new \RuntimeException('Настройки развития не инициализированы.');
        return new DevelopmentConfiguration(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }
    public function save(User $actor, int $revision, array $input): array
    {
        Access::admin($actor);
        return (new CatalogRevision($this->db))->write($revision, function () use ($input): array {
            $configuration = new DevelopmentConfiguration($input);
            $ownedMaximum = (int) $this->db->table('roleplay_character_attributes')->selectRaw('MAX(base_value + racial_bonus) AS maximum')->value('maximum');
            if ($configuration->values['maximum_level'] < $ownedMaximum) throw new ApiProblem('development_maximum_in_use', 'Максимум не может быть ниже уже приобретённого уровня характеристики персонажа. Увеличьте максимальное значение');
            $proficiencies = array_column((new CatalogRegistry($this->db))->definitionsFor('proficiencies'), null, 'slug');
            $attributes = array_column((new CatalogRegistry($this->db))->definitionsFor('attributes'), null, 'slug');
            foreach ($configuration->values['attributes'] as $slug => $attribute) {
                foreach ($attribute['rules'] as $rule) {
                    if ($rule['type'] !== 'automatic') continue;
                    $definition = $proficiencies[$rule['value']] ?? null;
                    if (!$definition || $definition['attribute_id'] !== ($attributes[$slug]['id'] ?? null)) throw new ApiProblem('automatic_proficiency_missing', 'Выберите владение, связанное с этой характеристикой');
                }
            }
            $this->db->table('roleplay_development_config')->where('id', 1)->update([
                'configuration' => json_encode($configuration->values, JSON_THROW_ON_ERROR), 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            // Выданные выборы и приобретённые сущности здесь не пересчитываем и не удаляем.
            return ['configuration' => $configuration->values];
        });
    }
}
