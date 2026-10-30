<?php
// Продолжение check-base-catalogs.php: используются только rpn_* таблицы.
use Maxcuso\Roleplay\Character\Moderation\CharacterReview;
use Maxcuso\Roleplay\Character\Moderation\CharacterIdentity;
use Maxcuso\Roleplay\Api\CharacterReviewController;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\ServerRequest;

$reviewId = $createdId;
$db->table('roleplay_characters')->where('id', $reviewId)->update([
    'status' => 'creating', 'mechanics_status' => 'review', 'literature_status' => 'review',
    'literature_approved' => false, 'literature_hash' => null,
]);
$reviewService = new CharacterReview($db, $topics);
$identity = new CharacterIdentity($db);
$reviewProfile = new \Maxcuso\Roleplay\Character\Profile\CharacterProfile($db, $topics);
$reviewController = new CharacterReviewController($reviewService, $identity, $reviewProfile);
$reviewKeyIndex = 0;
$reviewKey = function () use (&$reviewKeyIndex): string {
    return sprintf('88888888-8888-4888-8888-%012d', ++$reviewKeyIndex);
};
$reviewBody = function (string $part, string $action, string $comment = '') use ($reviewProfile, $reviewId, $moderator, $reviewKey): array {
    $view = $reviewProfile->read($reviewId, $moderator);
    return ['operation_key' => $reviewKey(), 'revision' => $view['snapshot']['character']['revision'],
        'catalog_revision' => $view['snapshot']['catalog_revision'], 'part' => $part, 'action' => $action,
        'comment' => $comment, 'topic_hash' => $view['review']['topic_hash']];
};
$correctBody = function () use ($reviewProfile, $reviewId, $owner, $reviewKey): array {
    $view = $reviewProfile->read($reviewId, $owner);
    return ['operation_key' => $reviewKey(), 'revision' => $view['snapshot']['character']['revision'],
        'catalog_revision' => $view['snapshot']['catalog_revision'], 'first_name' => 'Исправленное',
        'last_name' => 'Имя', 'age' => 21];
};
$view = $reviewProfile->read($reviewId, $owner);
check($view['review']['mechanics_status'] === 'review' && $view['review']['literature_status'] === 'review'
    && !$view['review']['permissions']['can_edit_identity'], 'Both independent checks open immediately, identity locked until return');
rejected(fn () => $reviewService->change($reviewId, $owner, $reviewBody('mechanics', 'approve')), 'review_forbidden');
rejected(fn () => $reviewService->change($reviewId, $visitor, $reviewBody('mechanics', 'approve')), 'character_not_found');
rejected(fn () => $identity->correct($reviewId, $owner, $correctBody()), 'identity_forbidden');
rejected(fn () => $reviewService->change($reviewId, $moderator, $reviewBody('mechanics', 'request_changes')), 'invalid_catalog_data');

$rejectedBody = $reviewBody('mechanics', 'request_changes', 'Исправь имя и возраст.');
$reviewService->change($reviewId, $moderator, $rejectedBody);
$view = $reviewProfile->read($reviewId, $owner);
check($view['review']['permissions']['can_edit_identity'] && $view['review']['literature_status'] === 'review'
    && $view['review']['history'][0]['details']['comment'] === 'Исправь имя и возраст.',
    'Return affects one part only and makes moderator remarks visible to owner');
$grantsBefore = [];
foreach (['attributes', 'skills', 'perks', 'spells', 'weapon_proficiencies', 'choices', 'balances', 'items'] as $tableName) {
    $grantsBefore[$tableName] = $db->table('roleplay_character_'.$tableName)->where('character_id', $reviewId)->get()->toJson();
}
$correct = $correctBody();
rejected(fn () => $identity->correct($reviewId, $owner, $correct + ['race_id' => $freeRace]), 'invalid_request');
// min_age — нижняя граница продолжительности жизни, а не минимальный возраст героя.
$badAge = $correct; $badAge['age'] = 91; // У стартовой расы max_age = 90.
rejected(fn () => $identity->correct($reviewId, $owner, $badAge), 'invalid_age');
$identityResult = $identity->correct($reviewId, $owner, $correct);
check($identity->correct($reviewId, $owner, $correct) === $identityResult, 'Identity correction exact retry is idempotent');
foreach ($grantsBefore as $tableName => $before) {
    check($before === $db->table('roleplay_character_'.$tableName)->where('character_id', $reviewId)->get()->toJson(),
        'Identity correction preserves '.$tableName);
}
$view = $reviewProfile->read($reviewId, $owner);
check($view['snapshot']['character']['first_name'] === 'Исправленное' && $view['review']['mechanics_status'] === 'draft',
    'Corrected identity waits for explicit resubmission');
$submit = $reviewBody('mechanics', 'submit');
$reviewService->change($reviewId, $owner, $submit);
$stale = $reviewBody('mechanics', 'approve'); $stale['revision']--;
rejected(fn () => $reviewService->change($reviewId, $moderator, $stale), 'character_revision_conflict');
$oldCatalog = $reviewBody('mechanics', 'approve'); $oldCatalog['catalog_revision']--;
rejected(fn () => $reviewService->change($reviewId, $moderator, $oldCatalog), 'character_revision_conflict');

$topicId = (int) $db->table('roleplay_characters')->find($reviewId)->discussion_id;
$synchronizer = new \Maxcuso\Roleplay\Character\Application\LiteratureSynchronizer($db, $topics);
$marker = '[success title=Принято font=#1E2019 bg=#A9C7AA border=#A9C7AA][/success]';
check(\Maxcuso\Roleplay\Character\Application\LiteratureAcceptance::matches($marker), 'Exact acceptance marker recognized');
check(!\Maxcuso\Roleplay\Character\Application\LiteratureAcceptance::matches('[quote]'.$marker.'[/quote]'), 'Quoted marker cannot approve');
check(!\Maxcuso\Roleplay\Character\Application\LiteratureAcceptance::matches(str_replace('Принято', 'Отказ', $marker)), 'Other panel cannot approve');
rejected(fn () => $reviewService->change($reviewId, $moderator, $reviewBody('literature', 'approve')), 'literature_topic_only');
$topics->accepted[$topicId] = (int) $moderator->id;
$synchronizer->synchronize($topicId, $moderator);
$view = $reviewProfile->read($reviewId, $owner);
check($view['review']['literature_status'] === 'approved'
    && $db->table('roleplay_characters')->find($reviewId)->status === 'creating', 'Topic approval leaves mechanics independent');
$revisionBefore = $db->table('roleplay_characters')->find($reviewId)->revision;
$synchronizer->synchronize($topicId, $moderator);
check($db->table('roleplay_characters')->find($reviewId)->revision === $revisionBefore, 'Repeated forum observation is a no-op');
// До активации открытая тема возвращает литературную часть на проверку.
unset($topics->accepted[$topicId]);
$synchronizer->synchronize($topicId, $moderator);
check(!$db->table('roleplay_characters')->find($reviewId)->literature_approved, 'Reopening pending topic revokes pending approval');
$approval = $reviewBody('mechanics', 'approve');
$request = RequestUtil::withActor((new ServerRequest())->withMethod('POST')->withQueryParams(['id' => $reviewId])
    ->withParsedBody($approval), $moderator);
$response = $reviewController->handle($request);
check($response->getStatusCode() === 200 && $db->table('roleplay_characters')->find($reviewId)->status === 'creating',
    'Mechanical approval alone does not activate');
check($reviewController->handle($request)->getStatusCode() === 200, 'Mechanical decision exact retry is idempotent');
$topics->accepted[$topicId] = (int) $moderator->id;
$synchronizer->synchronize($topicId, $moderator);
$ownerView = $reviewProfile->read($reviewId, $owner);
check($ownerView['snapshot']['character']['status'] === 'active' && $ownerView['permissions']['can_equip'],
    'Closing accepted topic after mechanics activates character');
$publicView = $reviewProfile->read($reviewId, $visitor);
check(!$publicView['review']['history'] && $publicView['review']['topic_hash'] === null,
    'Public profile hides moderation details');
rejected(fn () => $reviewService->change($reviewId, $moderator, $reviewBody('mechanics', 'request_changes', 'Поздно')), 'review_closed');
// До уточнения правил историческое одобрение активного персонажа сохраняется.
$db->table('roleplay_test_topics')->where('id', $topicId)->update(['content' => 'Дополнение после активации']);
check($reviewProfile->read($reviewId, $owner)['review']['literature_status'] === 'approved', 'Historical approval retained');
$db->table('roleplay_characters')->where('id', $reviewId)->update([
    'status' => 'creating', 'mechanics_status' => 'review', 'literature_status' => 'review', 'literature_approved' => false,
]);
file_put_contents(__DIR__.'/.runtime/review-view.json', json_encode($reviewProfile->read($reviewId, $moderator), JSON_THROW_ON_ERROR));
echo "Moderation, identity, topic observation and activation checks passed.\n";
