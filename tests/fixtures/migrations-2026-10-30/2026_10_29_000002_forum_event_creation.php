<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Новое право добавляется один раз. Последующие изменения групп в админке сохраняются.
return Migration::addPermissions(['quest-events.createForum' => Group::MEMBER_ID]);
