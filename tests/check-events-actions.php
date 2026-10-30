<?php

use Flarum\Http\RequestUtil;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\ServerRequest;
use Maxcuso\Roleplay\Events\Api\ShowEventController;
use Maxcuso\Roleplay\Events\Api\RegisterParticipantController;
use Maxcuso\Roleplay\Events\Api\LeaveEventController;
use Maxcuso\Roleplay\Events\EventCreator;

if (getenv('ROLEPLAY_TEST_COPY') !== '1' || !is_file(dirname(__DIR__, 3).'/ROLEPLAY_TEST_INSTALLED')) throw new RuntimeException('Run only in the installed test copy');

$site = require __DIR__ . '/../../../site.php';
$container = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $e) { fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); exit(1); });
$db = $container->make(ConnectionInterface::class);
$admin = User::findOrFail(1);
$user = User::findOrFail(2);
$creator = $container->make(EventCreator::class);
function check(bool $value, string $label): void {
    if (!$value) throw new RuntimeException($label);
    echo "OK: $label\n";
}
$request = fn ($event, $actor) => RequestUtil::withActor((new ServerRequest())->withAttribute('routeParameters', ['id' => $event->id])->withParsedBody(['userId' => 1]), $actor);
$show = fn ($event, $actor) => json_decode((string) $container->make(ShowEventController::class)->handle($request($event, $actor))->getBody(), true)['data'];
$join = fn ($event, $actor) => $container->make(RegisterParticipantController::class)->handle($request($event, $actor));
$leave = fn ($event, $actor) => $container->make(LeaveEventController::class)->handle($request($event, $actor));
$db->beginTransaction();
try {
    $source = $container->make(\Flarum\Api\JsonApi::class)->forResource(\Flarum\Api\Resource\DiscussionResource::class)->forEndpoint('create')->process([
        'data'=>['attributes'=>['title'=>'Action test source', 'content'=>'Published fixture for event action checks']],
    ], [], ['actor'=>$admin]);
    $attach = function ($event) use ($source) { $event->source_post_id = $source->first_post_id; $event->save(); return $event; };
    // Isolate this user's existing registrations; the enclosing rollback restores them.
    $db->table('quest_event_participants')->where('user_id', $user->id)->delete();
    $event = $creator->create($admin, 'Action test', null, 1, 3, 12, new DateTimeImmutable('+1 day'));
    $attach($event);
    check($show($event, new Guest())['isParticipating'] === false, 'Guest sees an unjoined card');
    try { $join($event, new Guest()); throw new RuntimeException('Guest joined'); }
    catch (\Flarum\User\Exception\NotAuthenticatedException $exception) { check(true, 'Guest cannot register'); }
    check($join($event, $user)->getStatusCode() === 201, 'Register succeeds');
    $data = $show($event, $user);
    check($data['participantCount'] === 1 && $data['participants'][0]['id'] === (int) $user->id, 'Response lists the authenticated participant, ignoring body userId');
    check($data['canLeave'] && $data['isParticipating'] && $data['scheduledStartAt'] !== null, 'Card offers leave and shows scheduled start');
    check($join($event, $user)->getStatusCode() === 409, 'Duplicate registration returns conflict');
    check($leave($event, $user)->getStatusCode() === 200, 'Leave succeeds');
    $data = $show($event, $user);
    check($data['participantCount'] === 0 && !$data['canLeave'] && $data['scheduledStartAt'] === null, 'Leave clears participant and schedule below minimum');
    $join($event, $user);
    $event->refresh(); $event->status = 'active'; $event->save();
    check(!$show($event, $user)['canLeave'] && $leave($event, $user)->getStatusCode() === 409, 'Active event disallows self leave');
    $second = $creator->create($admin, 'Second action test', null, 1, 3, 12, new DateTimeImmutable('+1 day'));
    $third = $creator->create($admin, 'Third action test', null, 1, 3, 12, new DateTimeImmutable('+1 day'));
    $attach($second); $attach($third);
    check($join($second, $user)->getStatusCode() === 201, 'Second slot is available');
    $blocked = $join($third, $user);
    check($blocked->getStatusCode() === 409 && isset(json_decode((string) $blocked->getBody(), true)['error']), 'Third slot returns a displayable server error');
} catch (Throwable $exception) {
    echo get_class($exception) . ': ' . $exception->getMessage() . "\n";
    throw $exception;
} finally { $db->rollBack(); }
echo "All action checks passed; database changes rolled back.\n";
