<?php

declare(strict_types=1);
namespace Maxcuso\Roleplay\Episodes;

use s9e\TextFormatter\Configurator;

final class ConfigureCompletion
{
    public function __invoke(Configurator $config): void
    {
        $config->tags->add('RP_RECIPIENT')->template = file_get_contents(__DIR__.'/../../resources/episode-recipient.html');
        $config->tags['RP_RECIPIENT']->attributes->add('avatar')->filterChain->append('#url');
        $config->tags['RP_RECIPIENT']->attributes->add('profile')->filterChain->append('#url');
        $config->tags->add('RP_GRANT')->template = file_get_contents(__DIR__.'/../../resources/episode-grant.html');
        $config->BBCodes->addCustom('[episode-end={TEXT}]',
            file_get_contents(__DIR__.'/../../resources/episode-receipt.html'), ['tagName' => 'RP_EPISODE_END']);
        $config->tags['RP_EPISODE_END']->rules->autoClose();
    }
}
