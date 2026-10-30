<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Notification/CharacterNotificationData.php';
use Maxcuso\Roleplay\Notification\CharacterNotificationData as Data;
$character=['id'=>20,'user_id'=>2,'first_name'=>'Риан','last_name'=>'Серебролап'];
function expect(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$make=fn($type,$details,$actor=1)=>Data::make($character,$type,$details,100,$actor);
expect($make('review_decided',['part'=>'mechanics','before'=>'review','after'=>'approved','activated'=>true])['kind']==='activated','Activation replaces the second approval');
expect($make('review_decided',['part'=>'mechanics','before'=>'review','after'=>'approved'])['kind']==='mechanics_approved','First approval');
expect($make('review_decided',['part'=>'literature','before'=>'review','after'=>'approved'])['kind']==='literature_approved','Literature approval');
expect($make('review_decided',['part'=>'literature','before'=>'approved','after'=>'approved'])===null,'Editing accepted content is not another approval');
expect($make('review_decided',['part'=>'literature','before'=>'approved','after'=>'review'])['kind']==='literature_review','Revocation');
$reward=$make('character_managed',['action'=>'reward','experience'=>25,'gold'=>10]);
expect($reward['experience']===25&&$reward['gold']===10&&$reward['kind']==='reward','Combined reward is one payload');
expect($make('character_managed',['action'=>'reward','experience'=>0,'gold'=>-10])['kind']==='deduction','Negative resources');
expect($make('character_managed',['action'=>'balance','resource'=>'experience_active','delta'=>12])['experience']===12,'Legacy balance action');
foreach(['perk','spell','item']as$kind)foreach(['grant','remove']as$action){
 $data=$make('character_managed',['action'=>$action.'_'.$kind,'name'=>'Название','quantity'=>2]);
 expect($data['kind']===$action.'_'.$kind&&$data['name']==='Название'&&$data['quantity']===2,'Grant/removal snapshot');
}
expect($make('character_managed',['action'=>'set_rank','after'=>['name'=>'Ранг'],'name'=>'Ранг'])['kind']==='rank_changed','Rank change');
expect($make('character_managed',['action'=>'set_rank','after'=>null])['kind']==='rank_removed','Rank removal');
foreach(['character_created','review_submitted','character_developed','item_equipped']as$type)expect($make($type,[])===null,'No self-action spam');
expect($make('character_managed',['action'=>'reward','gold'=>1],2)===null,'Staff does not notify themselves');
$episode=$make('character_managed',['action'=>'episode_reward','experience'=>19,'gold'=>2,
 'episode_title'=>'Переправа','grants'=>[['kind'=>'item','name'=>'Зелье']],'skipped'=>[['kind'=>'perk','id'=>1]]]);
expect($episode['kind']==='episode_reward'&&$episode['name']==='Переправа','Episode reward uses the existing grant notification');
expect(!isset($episode['grants'])&&!isset($episode['skipped']),'Notification does not expose the full receipt or skipped knowledge');
expect($make('character_managed',['action'=>'episode_reward','experience'=>0,'gold'=>0,'grants'=>[],
 'skipped'=>[['kind'=>'perk','id'=>1]],'episode_title'=>'Переправа'])===null,'Skipped knowledge alone sends no reward notification');
expect(!isset($reward['comment'])&&!isset($reward['payload']),'No full journal or private comment in notification');
$topic=$make('character_managed',['action'=>'topic_reward','experience'=>5,'gold'=>0,'grants'=>[]]);
expect($topic['kind']==='topic_reward'&&!isset($topic['name']),'Ordinary rewards use grant notifications without a quest title');
expect($make('character_managed',['action'=>'topic_reward','experience'=>0,'gold'=>0,'grants'=>[]])===null,'Empty ordinary rewards send no notification');
echo "Notification data: approvals, activation, revocation, rewards, removals, rank, self-actions passed\n";
