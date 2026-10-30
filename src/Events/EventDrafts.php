<?php

namespace Maxcuso\Roleplay\Events;

use DomainException;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use FoF\Drafts\Draft;

class EventDrafts
{
    public const LIST_LIMIT = 10;

    public function __construct(private ExtensionManager $extensions)
    {
    }

    public function available(User $actor): bool
    {
        return $this->extensions->isEnabled('fof-drafts')
            && class_exists(Draft::class)
            && $actor->can('user.saveDrafts');
    }

    public function listing(User $actor): array
    {
        $actor->assertRegistered();
        if (!$actor->can('quest-events.manage')) EventAccess::assertCreateAny($actor);
        if (!$this->available($actor)) return [];

        $items = [];
        foreach (Draft::whereVisibleTo($actor)->where('user_id', $actor->id)->orderByDesc('updated_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get() as $draft) {
            $items[] = [
                'id' => (int) $draft->id,
                'title' => trim((string) $draft->title) ?: 'Без названия',
                'unavailableReason' => $this->unavailableReason($draft),
            ];
        }
        return $items;
    }

    public function read(User $actor, int $draftId): array
    {
        $actor->assertRegistered();
        if (!$this->available($actor)) throw new DomainException('Черновики недоступны.');

        $draft = Draft::whereVisibleTo($actor)->where('user_id', $actor->id)->find($draftId);
        if ($draft === null) throw new DomainException('Черновик не найден или недоступен.');
        if ($reason = $this->unavailableReason($draft)) throw new DomainException($reason);

        return [
            'id' => (int) $draft->id,
            'title' => trim($draft->title),
            'content' => $draft->content,
            'relationships' => $draft->relationships ?: [],
        ];
    }

    private function unavailableReason(Draft $draft): ?string
    {
        $relationships = $draft->relationships ?: [];
        if (array_key_exists('discussion', $relationships)) return 'Это черновик ответа, а не новой темы.';
        if (array_key_exists('recipientUsers', $relationships) || array_key_exists('recipientGroups', $relationships)) {
            return 'Черновик личного обсуждения нельзя использовать для события.';
        }
        if (array_diff(array_keys($relationships), ['tags']) || !empty($draft->extra)) {
            return 'Дополнительные настройки этого черновика пока не поддерживаются.';
        }
        if ($draft->scheduled_for !== null) return 'У черновика уже назначена собственная публикация.';
        $length = mb_strlen(trim((string) $draft->title));
        if ($length < 3 || $length > 80) return 'Заголовок темы должен содержать от 3 до 80 символов.';
        if (trim((string) $draft->content) === '') return 'Текст черновика пуст.';
        if (mb_strlen($draft->content) > 60000) return 'Текст слишком длинный: оставьте место для списка участников.';
        if (preg_match('/\[\/?quest(?:\s|=|\])/i', $draft->content)) return 'Текст будущей темы не должен содержать коды событий.';
        return null;
    }
}
