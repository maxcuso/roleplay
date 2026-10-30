<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Checks;

use s9e\TextFormatter\Configurator;

final class ConfigureChecks
{
    public function __invoke(Configurator $config): void
    {
        foreach (['request', 'roll'] as $type) {
            $name = 'RP_CHECK_'.strtoupper($type);
            $config->BBCodes->addCustom('[check-'.$type.'={TEXT}]', file_get_contents(__DIR__.'/../../resources/check-'.$type.'.html'), ['tagName' => $name]);
            $config->tags[$name]->rules->autoClose();
        }
    }
}
