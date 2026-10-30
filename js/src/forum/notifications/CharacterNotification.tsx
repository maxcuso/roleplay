import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import {extend} from 'flarum/common/extend';
import Model from 'flarum/common/Model';
import type Mithril from 'mithril';
import type ItemList from 'flarum/common/utils/ItemList';
import {uiIcon} from '../../common/uiIcons';

interface Data {
  characterId: number; characterName: string; eventId: number; kind: string;
  name?: string; quantity?: number; experience?: number; gold?: number; discussionId?: number; postNumber?: number;
}
class NotificationEvent extends Model {}

/** Штатный Notification рисует аватар, время, непрочитанность и кнопку прочтения. */
export default class CharacterNotification extends Notification {
  private data(): Data {return this.attrs.notification.content() as Data;}
  icon(): string {
    const kind = this.data().kind;
    return uiIcon(kind.startsWith('rank_') ? 'management.rank'
      : kind === 'mechanics_changes' ? 'actions.edit'
        : kind === 'literature_review' ? 'profile.literature'
          : kind.startsWith('remove_') || kind === 'deduction' ? 'management.remove'
            : kind.startsWith('grant_') || kind === 'reward' || kind === 'episode_reward' || kind === 'topic_reward' ? 'management.title' : 'actions.select');
  }
  href(): string {
    const data = this.data();
    if (data.kind === 'literature_approved' && data.discussionId) {
      return app.forum.attribute<string>('baseUrl') + '/d/' + data.discussionId + (data.postNumber ? '/' + data.postNumber : '');
    }
    const user = app.session.user;
    return user ? app.route('user.roleplay', {username: user.slug()}) + '?character=' + data.characterId + '&event=' + data.eventId : '#';
  }
  content(): Mithril.Children {
    const data = this.data(), resources: Mithril.Children[] = [];
    for (const key of ['experience', 'gold'] as const) {
      if (!data[key]) continue;
      if (resources.length) resources.push(' и ');
      resources.push(app.translator.trans('maxcuso-roleplay.forum.notifications.' + key, {amount: data[key]}));
    }
    return app.translator.trans('maxcuso-roleplay.forum.notifications.' + data.kind, {
      character: <strong>{data.characterName}</strong>, name: <strong>{data.name ?? ''}</strong>, resources,
      quantity: data.quantity && data.quantity > 1 ? ' ×' + data.quantity : '',
    });
  }
  excerpt(): Mithril.Children {return null;}
}

export function registerCharacterNotifications(): void {
  app.store.models['roleplay-events'] = NotificationEvent;
  for (const [type, icon] of [['roleplayReview', 'actions.select'], ['roleplayGrants', 'management.title'], ['roleplayRank', 'management.rank']] as const) {
    app.notificationComponents[type] = CharacterNotification;
    // Как у Арены: в Flarum 2 настройки загружаются отложенно, используем штатный поздний extend.
    extend('flarum/forum/components/NotificationGrid' as any, 'notificationTypes', function (items: ItemList<{name: string; icon: string; label: Mithril.Children}>) {
      items.add(type, {name: type, icon: uiIcon(icon), label: app.translator.trans('maxcuso-roleplay.forum.notification_settings.' + type)});
    });
  }
}
