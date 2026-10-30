<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;

/** Уже существующая запись журнала — subject штатного уведомления, без новой таблицы. */
final class CharacterEvent extends AbstractModel
{
    use ScopeVisibilityTrait;
    protected $table = 'roleplay_character_events';
    public $timestamps = false;
}
