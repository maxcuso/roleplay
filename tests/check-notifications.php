<?php
declare(strict_types=1);

// Только локальная учебная копия. Отдельные персонажи клонируют состояние GuideDemo,
// реальные персонажи и черновики не изменяются. --cleanup удаляет только эти фикстуры.
$c = (require __DIR__.'/../../../site.php')->bootApp()->getContainer();
set_exception_handler(function ($error) { fwrite(STDERR, (string) $error); exit(1); });
if (getenv('ROLEPLAY_TEST_COPY') !== '1') throw new RuntimeException('Local test copy only');
$db = $c->make(\Illuminate\Database\ConnectionInterface::class);
$manifest = __DIR__.'/.runtime/notification-fixtures.json';
$children = ['attributes', 'skills', 'weapon_proficiencies', 'perks', 'spells', 'items', 'balances', 'choices'];
$hash = fn () => hash('sha256', json_encode([(array)$db->table('roleplay_characters')->where('id',3)->first(),
    ...array_map(fn($table) => $db->table('roleplay_character_'.$table)->where('character_id',3)->orderBy('id')->get()->all(),$children),
    (array)$db->table('roleplay_creation_drafts')->where('user_id',1)->first()]));
if (in_array('--cleanup', $argv, true)) {
    $m = json_decode(file_get_contents($manifest),true,flags:JSON_THROW_ON_ERROR);
    foreach($m['characters'] as $id) {
        $row = $db->table('roleplay_characters')->where('id',$id)->first();
        if(!$row || (int)$row->user_id !== 129 || $row->first_name !== 'Проверка уведомлений') throw new RuntimeException('Unsafe fixture cleanup');
        $events=$db->table('roleplay_character_events')->where('character_id',$id)->pluck('id')->all();
        $db->table('notifications')->whereIn('type',['roleplayReview','roleplayGrants','roleplayRank'])->whereIn('subject_id',$events)->delete();
        $db->table('roleplay_character_events')->where('character_id',$id)->delete();
        $db->table('roleplay_operations')->where('character_id',$id)->delete();
        foreach($children as $table)$db->table('roleplay_character_'.$table)->where('character_id',$id)->delete();
        $db->table('roleplay_characters')->where('id',$id)->delete();
    }
    foreach(['unread_notification_count','new_notification_count']as$key)$c->make('cache.store')->forget('user.129.'.$key);
    if($hash()!==$m['baseline'])throw new RuntimeException('Original data changed');
    unlink($manifest);echo "Notification fixtures removed; original character and Admin draft unchanged\n";exit;
}
if(is_file($manifest))throw new RuntimeException('Clean up earlier fixtures first');
$m=['characters'=>[],'baseline'=>$hash()];
$save=function()use(&$m,$manifest){file_put_contents($manifest,json_encode($m,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));};
$clone=function(string $status)use($db,$children,&$m,$save):int{
    $row=(array)$db->table('roleplay_characters')->where('id',3)->first();unset($row['id']);
    $row['first_name']='Проверка уведомлений';$row['last_name']=$status==='active'?'Награды':'Анкета';$row['discussion_id']=null;
    $row['status']=$status;$row['mechanics_status']=$status==='active'?'approved':'review';
    $row['literature_status']=$status==='active'?'approved':'draft';$row['literature_approved']=$status==='active';$row['revision']=1;
    $id=(int)$db->table('roleplay_characters')->insertGetId($row);$m['characters'][]=$id;$save();
    foreach($children as$table)foreach($db->table('roleplay_character_'.$table)->where('character_id',3)->get()as$child){
        $data=(array)$child;unset($data['id']);$data['character_id']=$id;$db->table('roleplay_character_'.$table)->insert($data);
    }return $id;
};
$assert=function(bool $ok,string $message){if(!$ok)throw new RuntimeException($message);echo 'OK: '.$message.PHP_EOL;};
$active=$clone('active');$creating=$clone('creating');
$admin=\Flarum\User\User::findOrFail(1);$owner=\Flarum\User\User::findOrFail(129);
$management=$c->make(\Maxcuso\Roleplay\Character\Moderation\CharacterManagement::class);
$review=$c->make(\Maxcuso\Roleplay\Character\Moderation\CharacterReview::class);
$command=fn(int$id)=>['operation_key'=>(string)\Ramsey\Uuid\Uuid::uuid4(),
    'revision'=>(int)$db->table('roleplay_characters')->where('id',$id)->value('revision'),
    'catalog_revision'=>(int)$db->table('roleplay_catalog_state')->where('id',1)->value('revision')];
$notifications=fn()=>$db->table('notifications')->where('user_id',129)->whereIn('type',['roleplayReview','roleplayGrants','roleplayRank'])
    ->whereIn('subject_id',$db->table('roleplay_character_events')->whereIn('character_id',$m['characters'])->select('id'));
$body=$command($active)+['action'=>'reward','data'=>['gold'=>10,'experience'=>0],'comment'=>'Проверка штатного уведомления'];
$management->change($active,$admin,$body);
$assert($notifications()->count()===1,'Reward sends one native notification');
$management->change($active,$admin,$body);
$assert($notifications()->count()===1,'Exact command retry does not duplicate notification or reward');
$db->beginTransaction();
$management->change($active,$admin,$command($active)+['action'=>'reward','data'=>['gold'=>1,'experience'=>0],'comment'=>'Rollback test']);
$assert($notifications()->count()===1,'No delivery before outer transaction commits');$db->rollBack();
$assert($notifications()->count()===1,'Rolled back event sends nothing');
$management->change($active,$admin,$command($active)+['action'=>'reward','data'=>['gold'=>-1,'experience'=>0],'comment'=>'Проверка списания']);
$review->change($creating,$admin,$command($creating)+['part'=>'mechanics','action'=>'approve','comment'=>null,'topic_hash'=>null]);
$review->change($creating,$admin,$command($creating)+['part'=>'mechanics','action'=>'request_changes','comment'=>'Уточните описание персонажа','topic_hash'=>null]);
$ranks=$db->table('roleplay_ranks')->where('enabled',true)->pluck('id')->all();
$rank=next($ranks)?:reset($ranks);
$management->change($active,$admin,$command($active)+['action'=>'set_rank','data'=>['rank_id'=>(int)$rank],'comment'=>'Проверка ранга']);
$items=$db->table('roleplay_items')->where('enabled',true)->first();
$management->change($active,$admin,$command($active)+['action'=>'grant_item','data'=>['target_id'=>(int)$items->id,'quantity'=>1],'comment'=>'Проверка выдачи предмета']);
$assert($notifications()->count()===6,'Review, return, rank and item use native notifications');
$preference=\Flarum\User\User::getNotificationPreferenceKey('roleplayGrants','alert');
$preferences=$db->table('users')->where('id',129)->value('preferences');
try{
    $owner->setPreference($preference,false)->save();
    $management->change($active,$admin,$command($active)+['action'=>'reward','data'=>['gold'=>1,'experience'=>0],'comment'=>'Disabled preference test']);
    $assert($notifications()->count()===6,'Native user preference disables new notifications');
}finally{$db->table('users')->where('id',129)->update(['preferences'=>$preferences]);}
$api=$c->make(\Flarum\Api\Client::class);
$response=$api->withActor($owner->fresh())->send('GET','/notifications');
$payload=json_decode((string)$response->getBody(),true);
$assert($response->getStatusCode()===200,'Native notification API serializes event subjects');
$own=array_values(array_filter($payload['data']??[],fn($r)=>in_array($r['attributes']['contentType']??'',['roleplayReview','roleplayGrants','roleplayRank'],true)));
$assert(count($own)===6,'Separate event subjects prevent unrelated operations from collapsing');
$eventId=(int)$notifications()->first()->subject_id;
$response=$api->withActor($owner->fresh())->send('GET','/roleplay/characters/'.$active.'/events/'.$eventId);
$assert($response->getStatusCode()===200,'Owner can open exact history event');
$response=$api->withActor(new \Flarum\User\User())->send('GET','/roleplay/characters/'.$active.'/events/'.$eventId);
$assert($response->getStatusCode()>=400,'Guest cannot read private event');
$assert($hash()===$m['baseline'],'Original character and Admin draft unchanged');
$m['notifications']=$notifications()->pluck('id')->all();$m['eventId']=$eventId;$save();
echo "Notification checks passed; dedicated fixtures retained for browser verification\n";
