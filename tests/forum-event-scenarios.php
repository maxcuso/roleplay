<?php
// Проверяем реальный менеджер транзакций Flarum; записываются только временные игровые таблицы.
$discussionId = (int) $db->table('roleplay_characters')->find($reviewId)->discussion_id;
$topics->accepted[$discussionId] = (int) $moderator->id;
$db->table('roleplay_characters')->where('id', $reviewId)->update([
    'mechanics_status' => 'approved', 'literature_status' => 'review', 'literature_hash' => null,
]);
$listener = new \Maxcuso\Roleplay\Character\Application\SynchronizeLiteratureEvent(
    new \Maxcuso\Roleplay\Character\Application\LiteratureSynchronizer($db, $topics), $originalDb);
$event = (object) ['actor' => $moderator, 'post' => (object) ['discussion_id' => $discussionId]];
$originalDb->transaction(function () use ($listener, $event, $db, $reviewId): void {
    $listener->handle($event);
    check($db->table('roleplay_characters')->find($reviewId)->status === 'creating',
        'Forum event does not synchronize until forum transaction commits');
});
check($db->table('roleplay_characters')->find($reviewId)->status === 'active',
    'Actual Flarum transaction manager runs accepted-topic synchronization after commit');
$topics->accepted[$discussionId] = null;
unset($topics->accepted[$discussionId]);
$db->table('roleplay_characters')->where('id', $reviewId)->update([
    'status' => 'creating', 'mechanics_status' => 'review', 'literature_status' => 'approved',
    'literature_hash' => null, 'literature_approved' => true,
]);
$listener->handle($event);
check($db->table('roleplay_characters')->find($reviewId)->literature_approved == true,
    'Administrator relink with preserved approval cannot be revoked by ordinary topic observation');
echo "Forum event ordering and administrator relink checks passed.\n";