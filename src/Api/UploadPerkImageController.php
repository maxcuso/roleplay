<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Illuminate\Contracts\Filesystem\Factory;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Presentation\PerkImageEncoder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class UploadPerkImageController implements RequestHandlerInterface
{
    public function __construct(private Factory $filesystem, private UrlGenerator $url, private PerkImageEncoder $encoder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        Access::catalogCreate(RequestUtil::getActor($request));
        try {
            $file = $request->getUploadedFiles()['image'] ?? null;
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                FieldValues::invalid('Выбери изображение для загрузки.');
            }
            if ($file->getSize() === null || $file->getSize() > PerkImageEncoder::MAX_BYTES) {
                FieldValues::invalid('Размер изображения — не более 5 МБ.');
            }
            $stream = $file->getStream();
            if ($stream->isSeekable()) $stream->rewind();
            $bytes = '';
            while (!$stream->eof() && strlen($bytes) <= PerkImageEncoder::MAX_BYTES) $bytes .= $stream->read(65536);
            [$image, $extension] = $this->encoder->encode($bytes);
            $path = 'perks/'.bin2hex(random_bytes(16)).'.'.$extension;
            $this->filesystem->disk('roleplay-images')->write($path, $image);
            return ApiResponse::data(['image' => $this->url->to('forum')->path('assets/roleplay/'.$path)]);
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}
