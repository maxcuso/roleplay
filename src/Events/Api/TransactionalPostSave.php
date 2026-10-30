<?php

namespace Maxcuso\Roleplay\Events\Api;

use Flarum\Api\Context;
use Flarum\Api\Endpoint\Endpoint;
use Illuminate\Database\ConnectionInterface;

class TransactionalPostSave
{
    public function __construct(private ConnectionInterface $database)
    {
    }

    public function __invoke(Endpoint $endpoint): Endpoint
    {
        // Preserve the original action, including Flarum's validation and permission checks.
        $original = clone $endpoint;
        $endpoint->action(function (Context $context) use ($original) {
            return $this->database->transaction(fn () => $original->process($context));
        });

        return $endpoint;
    }
}
