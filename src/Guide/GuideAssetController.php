<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Guide;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

/** Только готовые иллюстрации и шрифты страницы. Произвольные файлы пакета недоступны. */
final class GuideAssetController implements RequestHandlerInterface
{
    private const MIME = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
    ];

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('viewForum');
        $path = $request->getQueryParams()['path'] ?? '';
        if (!is_string($path) || !preg_match('~\A[a-zA-Z0-9_/-]+(?:[.][a-zA-Z0-9_-]+)*\z~D', $path)
            || str_contains($path, '..')) return new EmptyResponse(404);
        $root = realpath(__DIR__.'/../../preview/role/assets');
        $file = realpath($root.'/'.$path);
        $mime = self::MIME[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        if (!$root || !$file || !$mime || !is_file($file)
            || !str_starts_with($file, $root.DIRECTORY_SEPARATOR)) return new EmptyResponse(404);

        $etag = '"'.filemtime($file).'-'.filesize($file).'"';
        $headers = ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600', 'ETag' => $etag];
        if ($request->getHeaderLine('If-None-Match') === $etag) return new EmptyResponse(304, $headers);
        return new Response(fopen($file, 'rb'), 200, $headers);
    }
}
