<?php
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Flarum\User\Guest;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\ServerRequest;
use Maxcuso\Roleplay\Events\Api\ManageEventController;
use Maxcuso\Roleplay\Events\Api\ManagedEventData;
use Maxcuso\Roleplay\Events\Api\ListEventsController;
use Maxcuso\Roleplay\Events\EventCreator;
use Maxcuso\Roleplay\Events\QuestEvent;

if (getenv('ROLEPLAY_TEST_COPY') !== '1' || !is_file(dirname(__DIR__, 3).'/ROLEPLAY_TEST_INSTALLED')) throw new RuntimeException('Run only in the installed test copy');

$site = require __DIR__ . '/../../../site.php';
$c = $site->bootApp()->getContainer();
set_exception_handler(function (Throwable $e) { fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); exit(1); });
$db = $c->make(ConnectionInterface::class);
$actor = User::findOrFail(1);
$user = User::findOrFail(2);
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "OK: $label\n"; }
$managed = fn($event) => $c->make(ManagedEventData::class)->serialize($event->fresh()->load(['leader','participants'])->loadCount('participants'), $actor);
$payload = function($event) use ($managed) {
    $d = $managed($event);
    return ['revision'=>$d['revision'],'title'=>$d['title'],'description'=>$d['description'],'minParticipants'=>$d['minParticipants'],
        'maxParticipants'=>$d['maxParticipants'],'startDelayHours'=>$d['startDelayHours'],'preferredStartAt'=>$d['preferredStartAt'],
        'leaderId'=>$d['leader']['id'] ?? null,'eventType'=>$d['eventType'],'difficulty'=>$d['difficulty'],
        'customColor'=>$d['eventType']==='forum'?$d['cardColor']:null,'draftAction'=>'keep','draftId'=>null];
};
$request = fn($event,$data,$method='PATCH',$who=null) => RequestUtil::withActor((new ServerRequest())->withMethod($method)->withAttribute('routeParameters',['id'=>$event->id])->withParsedBody($data), $who ?? $actor);
$call = fn($event,$data,$method='PATCH',$who=null) => $c->make(ManageEventController::class)->handle($request($event,$data,$method,$who));
$db->beginTransaction();
try {
    $user->groups()->detach();
    $user = User::findOrFail(2);
    $event = $c->make(EventCreator::class)->create($actor,'Management test','Description',2,4,12,new DateTimeImmutable('+2 days'));
    $original = $payload($event);
    foreach (['PATCH','DELETE'] as $method) {
        try { $call($event,$original,$method,$user); throw new RuntimeException('Unprivileged write accepted'); }
        catch (\Flarum\User\Exception\PermissionDeniedException $e) { check(true,"Unauthorized $method rejected"); }
    }
    try { $c->make(ListEventsController::class)->handle(RequestUtil::withActor(new ServerRequest(),new Guest())); throw new RuntimeException('Guest list accepted'); }
    catch (\Flarum\User\Exception\PermissionDeniedException $e) { check(true,'Guest cannot list managed events'); }
    $list = $c->make(ListEventsController::class)->handle(RequestUtil::withActor(new ServerRequest(),$actor));
    check($list->getStatusCode()===200,'Moderator list loads');
    for ($i=0; $i<27; $i++) { $copy=$event->replicate(); $copy->title='Paging test '.$i; $copy->save(); }
    $first=json_decode((string)$c->make(ListEventsController::class)->handle(RequestUtil::withActor(new ServerRequest(),$actor))->getBody(),true);
    $second=json_decode((string)$c->make(ListEventsController::class)->handle(RequestUtil::withActor((new ServerRequest())->withQueryParams(['before'=>$first['meta']['nextBefore']]),$actor))->getBody(),true);
    check(count($first['data'])===25 && count($second['data'])>0 && !array_intersect(array_column($first['data'],'id'),array_column($second['data'],'id')),'Cursor pages have no overlap');
    $edit = array_replace($original,['title'=>'Updated title','leaderId'=>2,'eventType'=>'quest','difficulty'=>3,'customColor'=>null]);
    check($call($event,$edit)->getStatusCode()===200,'Title, leader and color update');
    check($event->fresh()->title==='Updated title' && (int)$event->fresh()->leader_id===2,'Changes persist');
    check($call($event,$original)->getStatusCode()===409,'Stale edit rejected');
    check($call($event,$original,'DELETE')->getStatusCode()===409,'Stale deletion rejected');
    $draft=new \FoF\Drafts\Draft();
    $draft->user_id=1; $draft->title='Management draft'; $draft->content='Prepared content'; $draft->relationships=[]; $draft->extra=[];
    $draft->ip_address='127.0.0.1'; $draft->updated_at=new DateTimeImmutable(); $draft->save();
    $edit=$payload($event); $edit['draftAction']='select'; $edit['draftId']=$draft->id;
    check($call($event,$edit)->getStatusCode()===200 && (int)$event->fresh()->source_draft_id===(int)$draft->id,'Own draft can replace source');
    $foreign=$draft->replicate(); $foreign->user_id=2; $foreign->save();
    $edit=$payload($event); $edit['draftAction']='select'; $edit['draftId']=$foreign->id;
    check($call($event,$edit)->getStatusCode()===409 && (int)$event->fresh()->source_draft_id===(int)$draft->id,'Foreign draft is rejected without losing old source');
    $edit=$payload($event); $edit['draftAction']='clear';
    check($call($event,$edit)->getStatusCode()===200 && $event->fresh()->source_draft_id===null,'Source draft may be cleared before start');
    $beforeJoin = $payload($event);
    $db->table('quest_event_participants')->insert(['event_id'=>$event->id,'user_id'=>2]);
    check($call($event,$beforeJoin)->getStatusCode()===409,'Participant change invalidates old form');
    $edit = $payload($event); $edit['minParticipants']=1;
    check($call($event,$edit)->getStatusCode()===200 && $event->fresh()->scheduled_start_at!==null,'Lowering minimum schedules start');
    $start = $event->fresh()->scheduled_start_at->format(DATE_ATOM);
    $edit = $payload($event); $edit['description']='New description';
    check($call($event,$edit)->getStatusCode()===200 && $event->fresh()->scheduled_start_at->format(DATE_ATOM)===$start,'Description preserves timer');
    $edit = $payload($event); $edit['minParticipants']=2;
    check($call($event,$edit)->getStatusCode()===200 && $event->fresh()->scheduled_start_at===null,'Raising minimum cancels start below minimum');
    $db->table('quest_event_participants')->insert(['event_id'=>$event->id,'user_id'=>1]);
    $edit = $payload($event); $edit['minParticipants']=1; $edit['maxParticipants']=1;
    check($call($event,$edit)->getStatusCode()===409,'Cannot reduce maximum below registrations');
    $edit = $payload($event); $edit['leaderId']=null;
    check($call($event,$edit)->getStatusCode()===200 && $event->fresh()->leader_id===null,'Leader may be cleared');
    $discussion = $c->make(\Flarum\Api\JsonApi::class)->forResource(\Flarum\Api\Resource\DiscussionResource::class)->forEndpoint('create')->process(['data'=>['attributes'=>['title'=>'Management test discussion','content'=>'Test content']]], [], ['actor'=>$actor]);
    $event = $event->fresh(); $event->status='active'; $event->discussion_id=$discussion->id; $event->save();
    $edit = $payload($event); $edit['startDelayHours']=24;
    check($call($event,$edit)->getStatusCode()===409,'Active event timing immutable');
    $edit = $payload($event); $edit['title']='Active title';
    check($call($event,$edit)->getStatusCode()===200,'Active event title editable');
    $edit = $payload($event); $edit['draftAction']='clear';
    check($call($event,$edit)->getStatusCode()===409,'Active draft immutable');
    $edit = $payload($event);
    check($call($event,$edit,'DELETE')->getStatusCode()===200,'Explicit deletion succeeds');
    check(QuestEvent::find($event->id)===null && !$db->table('quest_event_participants')->where('event_id',$event->id)->exists(),'Event and registrations removed');
    check($db->table('discussions')->where('id',$discussion->id)->exists(),'Published discussion retained');
} finally { $db->rollBack(); }
echo "Management checks passed; database rolled back.\n";

