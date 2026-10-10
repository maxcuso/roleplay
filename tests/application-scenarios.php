<?php
// Читаем теги/ядро установленного форума, но записываем только временные rpn_* таблицы.
use Flarum\Api\Client;
use Flarum\Tags\Tag;
use Flarum\Post\CommentPost;
use Flarum\Discussion\Discussion;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ResponseInterface;
use Maxcuso\Roleplay\Api\ApplicationSettingsController;
use Maxcuso\Roleplay\Character\Application\FlarumApplicationTopics;
use Maxcuso\Roleplay\Character\Application\ApplicationPostPolicy;

// Проверка контракта реального Client без вызова API создания тем.
$capture = new \stdClass();
$capture->status = 201;
$dryClient = new class($capture) extends Client {
    public function __construct(private readonly object $capture) {}
    public function post(string $path): ResponseInterface {
        $this->capture->path = $path;
        $this->capture->actor = $this->actor;
        $this->capture->body = $this->body;
        return new JsonResponse($this->capture->status === 201
            ? ['data' => ['id' => '456', 'type' => 'discussions']] : ['errors' => [['detail' => 'Нет доступа к тегу']]],
            $this->capture->status);
    }
};
$forumTopics = new FlarumApplicationTopics($dryClient);
$existingTag = Tag::query()->orderByDesc('parent_id')->first();
if ($existingTag) {
    check($forumTopics->create($owner, (int) $existingTag->id, 'Тестовая анкета', 'Шаблон') === 456,
        'Flarum adapter reads created ID from standard JSON:API response');
    check($capture->path === '/discussions' && $capture->actor === $owner
        && $capture->body['data']['attributes']['content'] === 'Шаблон',
        'Flarum adapter preserves player as actor and template as first post');
    $sentTags = array_column($capture->body['data']['relationships']['tags']['data'], 'id');
    $expectedTags = $existingTag->parent_id ? [(string) $existingTag->parent_id, (string) $existingTag->id] : [(string) $existingTag->id];
    check($sentTags === $expectedTags, 'Selected child tag includes its parent without bypassing tag permissions');
    $capture->status = 403;
    rejected(fn () => $forumTopics->create($owner, (int) $existingTag->id, 'Тестовая анкета', 'Шаблон'), 'application_topic_failed');
}
rejected(fn () => $forumTopics->create($owner, 4294967295, 'Тестовая анкета', 'Шаблон'), 'application_tag_missing');

// Настройки меняют только application. Ни источники, ни стартовое снаряжение не теряются.
$settingsController = new ApplicationSettingsController($db);
$settingsRequest = RequestUtil::withActor((new ServerRequest())->withMethod('GET'), $admin);
$settingsResponse = $settingsController->handle($settingsRequest);
$settings = json_decode((string) $settingsResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)['data'];
check($settingsResponse->getStatusCode() === 200 && isset($settings['tags'], $settings['application']['body_template']),
    'Settings API returns tag choices and configurable literary template');
$beforeConfig = $configStore->read();
$settingsBody = ['revision' => $settings['revision'], 'application' => [
    'tag_id' => $existingTag ? (int) $existingTag->id : null,
    'title_template' => 'Анкета {name}', 'body_template' => "{name}\n{race}\n{age}\nБиография:\n",
]];
$saveSettingsRequest = $settingsRequest->withMethod('PUT')->withParsedBody($settingsBody);
check($settingsController->handle($saveSettingsRequest)->getStatusCode() === 200, 'Settings API saves under catalog revision');
$afterConfig = $configStore->read();
unset($beforeConfig['application'], $afterConfig['application']);
check($beforeConfig === $afterConfig, 'Literary settings preserve complete starting configuration');
check($settingsController->handle($saveSettingsRequest)->getStatusCode() === 409, 'Stale settings cannot overwrite newer configuration');
$invalidTagBody = $settingsBody; $invalidTagBody['revision'] = $revision(); $invalidTagBody['application']['tag_id'] = 4294967295;
check($settingsController->handle($saveSettingsRequest->withParsedBody($invalidTagBody))->getStatusCode() === 422,
    'Settings reject nonexistent tag');
try {
    $settingsController->handle(RequestUtil::withActor($settingsRequest, $moderator));
    throw new RuntimeException('Moderator used admin settings');
} catch (\Flarum\User\Exception\PermissionDeniedException) {
    check(true, 'Moderator cannot access admin settings');
}

// Только объекты в памяти: первый пост не сохраняется в настоящую таблицу posts.
$policy = new ApplicationPostPolicy($db);
$post = new CommentPost();
$post->user_id = $owner->id;
$post->discussion_id = $topicId;
$post->number = 1;
$post->setRelation('discussion', new Discussion());
check($policy->edit($owner, $post) === 'ALLOW', 'Owner can fill pending literary template beyond normal post time limit');
$post->number = 2;
check($policy->edit($owner, $post) === null, 'Policy leaves ordinary replies to standard forum permissions');
$post->number = 1;
$post->hidden_at = new \Carbon\Carbon();
check($policy->edit($owner, $post) === 'DENY', 'Hidden literary post cannot be edited via special permission');
$post->hidden_at = null;
check($policy->edit($visitor, $post) === null, 'Special edit permission never grants access to another user');
$db->table('roleplay_characters')->where('id', $reviewId)->update(['status' => 'active']);
check($policy->edit($owner, $post) === 'DENY', 'Active application closes player editing via special policy');
check($policy->edit($admin, $post) === null, 'Administrator retains standard forum permissions');

echo "Application settings, forum request contract and literary post policy checks passed.\n";