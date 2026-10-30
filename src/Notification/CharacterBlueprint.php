<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/** Штатный Blueprint, как у Арены. Subject события сохраняет отдельные операции отдельными строками. */
abstract class CharacterBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(private CharacterEvent $event, private ?User $actor, private array $data) {}
    public function getSubject(): ?AbstractModel { return $this->event; }
    public function getFromUser(): ?User { return $this->actor; }
    public function getData(): mixed { return $this->data; }
    public static function getSubjectModel(): string { return CharacterEvent::class; }
}
