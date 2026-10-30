<?php

use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Events\QuestEvent;

if (getenv('ROLEPLAY_TEST_COPY') !== '1' || !is_file(dirname(__DIR__, 3).'/ROLEPLAY_TEST_INSTALLED')) throw new RuntimeException('Run only in the installed test copy');

$site = require __DIR__ . '/../../../site.php';
$container = $site->bootApp()->getContainer();
$api = $container->make(JsonApi::class);
set_exception_handler(function (Throwable $e) { fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); exit(1); });
$db = $container->make(ConnectionInterface::class);
$actor = User::findOrFail(1);

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "OK: $label\n";
}

$full = '[quest title="BBCode test" min=2 max=4 delay=12 type=quest difficulty=1]Description & **bold**[/quest]';
$create = fn (string $content) => $api->forResource(DiscussionResource::class)->forEndpoint('create')->process([
    'data' => ['attributes' => ['title' => 'BBCode integration test', 'content' => $content]],
], [], ['actor' => $actor]);
$update = fn ($post, string $content, User $user) => $api->forResource(PostResource::class)->forEndpoint('update')->handle(
    RequestUtil::withActor((new ServerRequest())
        ->withMethod('PATCH')
        ->withUri(new Uri('/posts/' . $post->id))
        ->withParsedBody(['data' => ['type' => 'posts', 'id' => (string) $post->id, 'attributes' => ['content' => $content]]]), $user)
);

$db->beginTransaction();
try {
    $before = QuestEvent::count();
    $discussion = $create($full);
    $event = QuestEvent::orderByDesc('id')->firstOrFail();
    $post = CommentPost::findOrFail($discussion->first_post_id);
    check(QuestEvent::count() === $before + 1, 'Creating a discussion creates one event');
    check($post->content === '[quest=' . $event->id . ']', 'Stored post contains only the short code');
    check((int) $event->source_post_id === (int) $post->id, 'Event is linked to its source post');
    check($event->description === 'Description & **bold**', 'Description preserves source text');
    check($event->scheduled_start_at === null, 'Empty event has no scheduled start');
    check($event->event_type === 'quest' && $event->difficulty === 1, 'Full BBCode saves quest difficulty');

    $update($post, $post->content . "\nEdited text", $actor);
    check(QuestEvent::count() === $before + 1, 'Editing a short code does not create an event');

    $update($post, $post->fresh()->content . "\n" . str_replace('type=quest difficulty=1', 'type=forum color="#Ab12Cd"', $full), $actor);
    check(QuestEvent::count() === $before + 2, 'Adding full code during editing creates an event');
    check(QuestEvent::orderByDesc('id')->first()->custom_color === '#ab12cd', 'Full BBCode saves custom forum color');

    $countBeforeFailure = QuestEvent::count();
    $contentBeforeFailure = $post->fresh()->content;
    try {
        $update($post, $full . "\n" . str_replace('max=4', 'max=1', $full), $actor);
        throw new RuntimeException('Invalid limits were accepted');
    } catch (ValidationException $exception) {
        check(QuestEvent::count() === $countBeforeFailure, 'Invalid second event rolls back the first');
        check($post->fresh()->content === $contentBeforeFailure, 'Failed edit preserves the original post');
    }

    $create('[code]' . $full . '[/code]');
    check(QuestEvent::count() === $countBeforeFailure, 'BBCode inside a code block creates nothing');

    $discussionCount = $db->table('discussions')->count();
    try {
        $create(str_replace('delay=12', 'delay=0', $full));
        throw new RuntimeException('Invalid delay was accepted');
    } catch (ValidationException $exception) {
        check($db->table('discussions')->count() === $discussionCount, 'Invalid event rolls back the new discussion');
    }

    foreach ([str_replace('[/quest]', '', $full), str_replace('delay=12', 'delay=12 draft=4294967295', $full), str_replace('difficulty=1', 'difficulty=7', $full), str_replace('type=quest difficulty=1', 'type=forum color="red;"', $full)] as $invalid) {
        try {
            $create($invalid);
            throw new RuntimeException('Incomplete or unsupported code was accepted');
        } catch (ValidationException $exception) {
            check(QuestEvent::count() === $countBeforeFailure, 'Incomplete or unsupported code creates nothing');
        }
    }

    $guest = new \Flarum\User\Guest();
    $guestPost = new CommentPost();
    $guestPost->setContentAttribute($full, $guest);
    try {
        $container->make(\Maxcuso\Roleplay\Events\Listener\CreateEventsFromPost::class)
            ->handle(new \Flarum\Post\Event\Saving($guestPost, $guest));
        throw new RuntimeException('Guest was allowed to create an event');
    } catch (\Flarum\User\Exception\PermissionDeniedException $exception) {
        check(QuestEvent::count() === $countBeforeFailure, 'Actor without permission cannot create an event');
    }

    CommentPost::saved(function (CommentPost $saved) {
        if (str_contains($saved->content, 'force-failure')) {
            throw new RuntimeException('Injected save failure');
        }
    });
    try {
        $create($full . "\nforce-failure");
        throw new RuntimeException('Injected failure did not run');
    } catch (RuntimeException $exception) {
        check($exception->getMessage() === 'Injected save failure', 'Failure occurred after post SQL save');
        check(QuestEvent::count() === $countBeforeFailure, 'Post save failure rolls back event creation');
        check($db->table('discussions')->count() === $discussionCount, 'Post save failure rolls back discussion creation');
    }
} catch (Throwable $exception) {
    echo get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n";
    throw $exception;
} finally {
    $db->rollBack();
}

echo "All checks passed; database changes rolled back.\n";
