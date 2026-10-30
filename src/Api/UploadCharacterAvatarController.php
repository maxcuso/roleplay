<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Presentation\CharacterAvatar;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class UploadCharacterAvatarController implements RequestHandlerInterface
{
    public function __construct(private Factory $filesystem, private CharacterAvatar $encoder, private ConnectionInterface $db) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::create($actor);
        try {
            $file = $request->getUploadedFiles()['image'] ?? null;
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                FieldValues::invalid('Выберите изображение в формате JPG, PNG или WebP размером не более 2 МБ');
            }
            if ($file->getSize() === null || $file->getSize() > CharacterAvatar::MAX_BYTES) {
                FieldValues::invalid('Выберите изображение размером не более 2 МБ');
            }
            $stream = $file->getStream();
            if ($stream->isSeekable()) $stream->rewind();
            $bytes = '';
            while (!$stream->eof() && strlen($bytes) <= CharacterAvatar::MAX_BYTES) $bytes .= $stream->read(65536);
            $image = $this->encoder->encode($bytes);
            $filename = 'avatar-'.$actor->id.'-'.bin2hex(random_bytes(16)).'.webp';
            $this->filesystem->disk('roleplay-images')->write('avatars/'.$filename, $image);
            $this->sweep((int) $actor->id);
            return ApiResponse::data(['avatar' => $filename]);
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }

    /** Незавершённая форма может оставить файл. Через неделю удаляем только бесхозные аватары этого игрока. */
    private function sweep(int $userId): void
    {
        try {
            $used = $this->db->table('roleplay_characters')->where('user_id', $userId)->whereNotNull('avatar')->pluck('avatar')->all();
            $draft = $this->db->table('roleplay_creation_drafts')->where('user_id', $userId)->value('payload');
            if ($draft !== null) $used[] = json_decode($draft, true, flags: JSON_THROW_ON_ERROR)['avatar'] ?? null;
            $disk = $this->filesystem->disk('roleplay-images');
            foreach ($disk->listContents('avatars', false) as $file) {
                $name = basename($file->path());
                if ($file->isFile() && preg_match('/^avatar-'.$userId.'-[0-9a-f]{32}\.webp$/D', $name)
                    && ($file->lastModified() ?? PHP_INT_MAX) < time() - 7 * 86400 && !in_array($name, $used, true)) {
                    $disk->delete($file->path());
                }
            }
        } catch (\Throwable) {
            // Уборка не должна превращать удачную загрузку в ошибку формы.
        }
    }
}
