<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

/** Ожидаемая ошибка сценария. code используется интерфейсом, message — человеком. */
final class ApiProblem extends \RuntimeException
{
    public function __construct(
        public readonly string $problemCode,
        string $message,
        public readonly int $status = 422,
    ) {
        if (!in_array($status, [400, 403, 404, 409, 422], true)) {
            throw new \InvalidArgumentException('Недопустимый статус ожидаемой ошибки.');
        }
        parent::__construct($message);
    }
}