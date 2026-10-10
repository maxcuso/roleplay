<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Laminas\Diactoros\Response\JsonResponse;

/** Оболочка API. Здесь нет правил игры и обработки неожиданных исключений. */
final class ApiResponse
{
    public static function data(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status, ['Cache-Control' => 'private, no-store']);
    }

    public static function problem(ApiProblem $problem): JsonResponse
    {
        return new JsonResponse([
            'errors' => [[
                'status' => (string) $problem->status,
                'code' => $problem->problemCode,
                'detail' => $problem->getMessage(),
            ]],
        ], $problem->status, ['Cache-Control' => 'private, no-store']);
    }
}