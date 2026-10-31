<?php
// Продолжение check-base-catalogs.php: только изолированные таблицы этого сценария.
(function () use ($db, $schema, $create, $progressId, $owner, $moderator, $visitor, $topics, $characterOperations): void {
    $profile = new \Maxcuso\Roleplay\Character\Profile\CharacterProfile($db, $topics);
    $progression = new \Maxcuso\Roleplay\Character\Progression\CharacterProgression($db, $characterOperations);
    $db->table('roleplay_characters')->where('id', $progressId)->update(['status' => 'active']);
    $magicId = $db->table('roleplay_attributes')->where('slug', 'magic')->value('id');
    $db->table('roleplay_character_attributes')->where('character_id', $progressId)->where('attribute_id', $magicId)->update(['base_value' => 3, 'racial_bonus' => 0]);
    $db->table('roleplay_character_balances')->where('character_id', $progressId)->whereIn('resource_key', ['experience_active', 'experience_total'])->update(['amount' => 100]);
    $path = $create('paths', ['name' => 'Путь обучения', 'enabled' => true]);
    $one = $create('spells', ['name' => 'Из книги', 'magic_path_id' => $path, 'enabled' => true, 'is_starting' => false]);
    $two = $create('spells', ['name' => 'Из свитка', 'magic_path_id' => $path, 'enabled' => true]);
    $high = $create('spells', ['name' => 'Старший круг', 'magic_path_id' => $path, 'enabled' => true, 'level' => 2]);
    $absent = $create('spells', ['name' => 'Без источника', 'magic_path_id' => $path, 'enabled' => true]);
    $type = $create('item-types', ['name' => 'Учебная литература']);
    $link = fn ($id) => ['type' => 'spell', 'knowledge_id' => $id];
    $book = $create('items', ['name' => 'Учебная книга', 'item_type_id' => $type, 'enabled' => true, 'is_literature' => true,
        'literature_contents' => [$link($one), $link($high)]]);
    $scroll = $create('items', ['name' => 'Учебный свиток', 'item_type_id' => $type, 'enabled' => true, 'is_literature' => true,
        'consumed_on_learning' => true, 'max_stack' => 10, 'literature_contents' => [$link($two)]]);
    (new \Maxcuso\Roleplay\Character\State\CharacterGrants($db))->items($progressId, $book, 1, 1);
    (new \Maxcuso\Roleplay\Character\State\CharacterGrants($db))->items($progressId, $scroll, 2, 10);
    $bookInstance = (int) $db->table('roleplay_character_items')->where('character_id', $progressId)->where('item_id', $book)->value('id');
    $scrollInstance = (int) $db->table('roleplay_character_items')->where('character_id', $progressId)->where('item_id', $scroll)->value('id');
    $sequence = 0;
    $command = function ($spell, $source) use ($profile, $progressId, $owner, &$sequence): array {
        $view = $profile->read($progressId, $owner);
        return ['operation_key' => sprintf('90909090-9090-4090-8090-%012d', ++$sequence), 'kind' => 'spell', 'target_id' => $spell,
            'source_instance_id' => $source, 'revision' => $view['snapshot']['character']['revision'],
            'catalog_revision' => $view['snapshot']['catalog_revision'], 'rules_hash' => $view['learning']['rules_hash']];
    };
    $view = $profile->read($progressId, $owner);
    $options = array_column($view['learning']['spells'], null, 'id');
    check(isset($options[$one], $options[$two], $options[$high]) && !isset($options[$absent]), 'Learning shows only knowledge in owned literature, including non-starting spells');
    check($options[$one]['allowed'] && !$options[$high]['allowed'], 'Acquired Magic opens only its configured circles');
    check($profile->read($progressId, $visitor)['learning'] === null, 'Learning sources are private to the owner');
    rejected(fn () => $progression->change($progressId, $moderator, $command($one, $bookInstance)), 'progression_forbidden');
    rejected(fn () => $progression->change($progressId, $owner, $command($high, $bookInstance)), 'learning_unavailable');
    rejected(fn () => $progression->change($progressId, $owner, $command($absent, $bookInstance)), 'learning_unavailable');
    rejected(fn () => $progression->change($progressId, $owner, $command($one, $scrollInstance)), 'learning_source_missing');
    $db->table('roleplay_magic_paths')->where('id', $path)->update(['enabled' => false]);
    rejected(fn () => $progression->change($progressId, $owner, $command($two, $scrollInstance)), 'learning_unavailable');
    $db->table('roleplay_magic_paths')->where('id', $path)->update(['enabled' => true]);
    $request = $command($one, $bookInstance);
    $result = $progression->change($progressId, $owner, $request);
    check($progression->change($progressId, $owner, $request) === $result, 'Learning exact retry never charges or grants twice');
    $view = $profile->read($progressId, $owner);
    check($view['snapshot']['balances']['experience_active'] === 95 && $view['snapshot']['balances']['experience_total'] === 100
        && in_array($one, array_column($view['snapshot']['spells'], 'id'), true), 'Learning spends only active XP and immediately grants the spell');
    check(!in_array($one, array_column($view['learning']['spells'], 'id'), true)
        && $db->table('roleplay_character_items')->where('id', $bookInstance)->exists(), 'Learned spell disappears from training and the book stays');
    rejected(fn () => $progression->change($progressId, $owner, $command($one, $bookInstance)), 'learning_unavailable');
    // Сбой журнала обязан откатить и выдачу, и оплату, и расход свитка.
    $request = $command($two, $scrollInstance);
    $trigger = $db->getQueryGrammar()->wrap($db->getTablePrefix().'learning_failure');
    $table = $db->getQueryGrammar()->wrapTable('roleplay_character_events');
    $db->statement("CREATE TRIGGER $trigger BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'learning test failure'");
    try {
        try {$progression->change($progressId, $owner, $request); throw new RuntimeException('Journal failure accepted');}
        catch (\Illuminate\Database\QueryException $expected) {}
        check(!$db->table('roleplay_character_spells')->where('character_id', $progressId)->where('spell_id', $two)->exists()
            && (int) $db->table('roleplay_character_items')->where('id', $scrollInstance)->value('quantity') === 2
            && $profile->read($progressId, $owner)['snapshot']['balances']['experience_active'] === 95, 'Journal failure rolls back learning, payment and source consumption');
    } finally {$db->statement("DROP TRIGGER IF EXISTS $trigger");}
    $progression->change($progressId, $owner, $request);
    check((int) $db->table('roleplay_character_items')->where('id', $scrollInstance)->value('quantity') === 1, 'Successful learning consumes exactly one unit');
    $view = $profile->read($progressId, $owner);
    check($view['history'][0]['details']['source'] === 'Учебный свиток' && $view['history'][0]['details']['experience_spent'] === 5, 'History records knowledge, price and source');
    $db->table('roleplay_character_spells')->where('character_id', $progressId)->where('spell_id', $two)->delete();
    $progression->change($progressId, $owner, $command($two, $scrollInstance));
    check(!$db->table('roleplay_character_items')->where('id', $scrollInstance)->exists(), 'Last consumable unit is removed without a zero-quantity stack');
    // Нехватка опыта, обновление цен и исчезновение книги проверяются заново.
    $db->table('roleplay_character_balances')->where('character_id', $progressId)->where('resource_key', 'experience_active')->update(['amount' => 0]);
    $db->table('roleplay_character_attributes')->where('character_id', $progressId)->where('attribute_id', $magicId)->update(['base_value' => 7]);
    rejected(fn () => $progression->change($progressId, $owner, $command($high, $bookInstance)), 'learning_unavailable');
    $request = $command($high, $bookInstance);
    $db->table('roleplay_character_items')->where('id', $bookInstance)->delete();
    rejected(fn () => $progression->change($progressId, $owner, $request), 'learning_unavailable');
    $old = $command($high, $scrollInstance); $old['rules_hash'] = str_repeat('0', 64);
    rejected(fn () => $progression->change($progressId, $owner, $old), 'progression_rules_changed');
    echo "Spell learning sources, requirements, payment, history, retries and atomic rollback passed.\n";
})();
