<?php

use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use FoF\Drafts\Draft;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Events\EventCreator;
use Maxcuso\Roleplay\Events\EventDrafts;
use Maxcuso\Roleplay\Events\EventStarter;
use Maxcuso\Roleplay\Events\QuestEvent;

if (getenv('ROLEPLAY_TEST_COPY') !== '1' || !is_file(dirname(__DIR__, 3).'/ROLEPLAY_TEST_INSTALLED')) throw new RuntimeException('Run only in the installed test copy');

$site = require __DIR__ . '/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $e) { fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); exit(1); });
$db = $container->make(ConnectionInterface::class);
$drafts = $container->make(EventDrafts::class);
$creator = $container->make(EventCreator::class);
$starter = $container->make(EventStarter::class);
$actor = User::findOrFail(1);
$participant = User::findOrFail(2);

function check(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
    echo "OK: $message\n";
}

$db->beginTransaction();
try {
    $draft = new Draft();
    $draft->user_id = $actor->id;
    $draft->title = 'Original draft title';
    $draft->content = 'Original draft body';
    $draft->relationships = [];
    $draft->extra = [];
    $draft->ip_address = '127.0.0.1';
    $draft->updated_at = new DateTimeImmutable();
    $draft->save();
    check(in_array($draft->id, array_column($drafts->listing($actor), 'id')), 'Own draft is listed');

    $foreign = $draft->replicate();
    $foreign->user_id = $participant->id;
    $foreign->save();
    check(!in_array($foreign->id, array_column($drafts->listing($actor), 'id')), 'Another user draft is not listed');
    try {
        $drafts->read($actor, $foreign->id);
        throw new RuntimeException('Foreign draft was readable');
    } catch (DomainException $exception) {
        check(true, 'Another user draft cannot be read by ID');
    }

    foreach ([['discussion' => ['data' => ['id' => '1']]], ['recipientUsers' => ['data' => []]]] as $relationships) {
        $draft->relationships = $relationships;
        $draft->save();
        try {
            $drafts->read($actor, $draft->id);
            throw new RuntimeException('Reply or private draft was accepted');
        } catch (DomainException $exception) {
            check(true, 'Reply/private draft is rejected');
        }
    }
    $draft->relationships = [];
    $draft->save();

    $api = $container->make(JsonApi::class);
    $source = $api->forResource(DiscussionResource::class)->forEndpoint('create')->process([
        'data' => ['attributes' => [
            'title' => 'Draft integration source',
            'content' => '[quest title="Card title" min=1 max=2 delay=12 draft=' . $draft->id . ']Card description[/quest]',
        ]],
    ], [], ['actor' => $actor]);
    $event = QuestEvent::where('source_post_id', $source->first_post_id)->firstOrFail();
    check((int) $event->source_draft_id === (int) $draft->id, 'BBCode saves the selected draft ID');
    check((int) $event->draft_owner_id === (int) $actor->id, 'Draft owner is saved separately from leader');

    $draft->title = 'Updated draft title';
    $draft->content = 'Updated draft body';
    $tagId = $db->table('tags')->value('id');
    if ($tagId !== null) {
        $draft->relationships = ['tags' => ['data' => [['type' => 'tags', 'id' => (string) $tagId]]]];
    }
    $draft->save();
    $db->table('quest_event_participants')->insert(['event_id' => $event->id, 'user_id' => $participant->id]);
    $event->scheduled_start_at = new DateTimeImmutable('-1 minute');
    $event->save();
    $starter->startIfDue($event->id);
    $event->refresh();
    $topic = Discussion::findOrFail($event->discussion_id);
    $post = CommentPost::findOrFail($topic->first_post_id);
    check($topic->title === 'Updated draft title', 'Start uses the latest draft title');
    if ($tagId !== null) check($db->table('discussion_tag')->where('discussion_id', $topic->id)->where('tag_id', $tagId)->exists(), 'Start uses the latest draft tags');
    check(str_contains($post->content, 'Updated draft body'), 'Start uses the latest draft body');
    check(str_contains($post->parsed_content, 'USERMENTION'), 'Participant mentions are appended');
    check((int) $post->user_id === (int) $event->leader_id, 'Topic is published as the leader');
    check(Draft::find($draft->id) !== null, 'Source draft remains available');

    $balances = json_encode($db->table('roleplay_character_balances')->orderBy('character_id')->orderBy('resource_key')->get());
    $operations = $db->table('roleplay_operations')->count();
    $locked = $container->make(\Flarum\Api\Client::class)->withActor($actor)->withBody([
        'data' => ['type' => 'discussions', 'id' => (string) $topic->id, 'attributes' => ['isLocked' => true]],
    ])->send('PATCH', '/discussions/'.$topic->id);
    check($locked->getStatusCode() === 200 && $event->fresh()->status === 'completed', 'Native manual topic locking completes the linked event');
    check($balances === json_encode($db->table('roleplay_character_balances')->orderBy('character_id')->orderBy('resource_key')->get())
        && $operations === $db->table('roleplay_operations')->count(), 'Manual topic locking does not grant rewards or create character operations');

    $waiting = $creator->create($actor, 'Waiting event', null, 1, 2, 12, draftId: $draft->id);
    $waiting->source_post_id = $source->first_post_id;
    $db->table('quest_event_participants')->insert(['event_id' => $waiting->id, 'user_id' => $participant->id]);
    $waiting->scheduled_start_at = new DateTimeImmutable('-1 minute');
    $waiting->save();
    $draft->delete();
    try {
        $starter->startIfDue($waiting->id);
        throw new RuntimeException('Deleted draft did not prevent publication');
    } catch (DomainException $exception) {
        $waiting->refresh();
        check($waiting->status === 'recruiting' && $waiting->discussion_id === null && $waiting->scheduled_start_at !== null, 'Deleted draft leaves the event waiting');
        check(str_contains($waiting->start_error, 'Черновик'), 'Missing draft reason is persisted for moderator');
    }
    $newIds = [];
    for ($i = 0; $i < 12; $i++) {
        $item = $foreign->replicate();
        $item->user_id = $actor->id;
        $item->updated_at = new DateTimeImmutable('2100-01-01');
        $item->save();
        $newIds[] = (int) $item->id;
    }
    $listed = $drafts->listing($actor);
    check(count($listed) === 10, 'Draft list is limited to ten entries');
    check(array_column($listed, 'id') === array_slice(array_reverse($newIds), 0, 10), 'Newest ten drafts have stable ordering');
} catch (Throwable $exception) {
    echo get_class($exception) . ': ' . $exception->getMessage() . "\n";
    throw $exception;
} finally {
    $db->rollBack();
}
echo "All draft checks passed; database changes rolled back.\n";
