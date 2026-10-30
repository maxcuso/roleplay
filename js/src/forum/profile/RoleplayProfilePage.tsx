import Feedback from '../../common/components/Feedback';
import app from 'flarum/forum/app';
import UserPage, {type IUserPageAttrs} from 'flarum/forum/components/UserPage';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';
import ProfileApi from './ProfileApi';
import ProfileState from './ProfileState';
import ProfilePanel from './ProfilePanel';
import CharacterHistoryModal from './CharacterHistoryModal';
import ReviewHistoryModal from './ReviewHistoryModal';
import showModal from '../../common/showModal';
import {requestMessage} from '../../common/requestError';
import type {HistoryEvent} from './historyPresentation';

/** Перенесён сценарий старой вкладки. Загрузка и переключение вынесены в тестируемое состояние. */
export default class RoleplayProfilePage extends UserPage {
  profile = new ProfileState(new ProfileApi(), () => m.redraw());
  userError = '';
  private disposed = false;
  private linkedEvent = '';
  private selectionLink = '';

  oninit(vnode: Mithril.Vnode<IUserPageAttrs, this>): void {
    super.oninit(vnode);
    void this.loadUser(m.route.param('username')).catch(() => {
      this.userError = 'Не удалось открыть профиль пользователя. Попробуйте обновить страницу';
      m.redraw();
    });
  }

  show(user: User): void {
    super.show(user);
    const requested = Number(m.route.param('character'));
    const preferred = Number.isSafeInteger(requested) && requested > 0 ? requested : null;
    this.selectionLink = String(m.route.param('character') ?? '') + '/' + String(m.route.param('event') ?? '');
    if (app.forum.attribute('roleplayCanView')) void this.profile.loadUser(Number(user.id()), preferred);
  }

  content(): Mithril.Children {
    if (!app.forum.attribute('roleplayCanView')) return <Feedback message="У вас нет доступа к ролевым персонажам" inline/>;
    if (this.userError) return <Feedback message={this.userError}/>;
    return <ProfilePanel state={this.profile} picker own={app.session.user?.id() === this.user?.id()}/>;
  }

  onupdate(): void {
    const character = String(m.route.param('character') ?? ''), event = String(m.route.param('event') ?? '');
    // Переход из колокольчика может остаться внутри уже открытого профиля.
    if (this.user && character + '/' + event !== this.selectionLink) {
      this.selectionLink = character + '/' + event; this.linkedEvent = '';
      const id = Number(character);
      void this.profile.loadUser(Number(this.user.id()), Number.isSafeInteger(id) && id > 0 ? id : null);
      return;
    }
    const key = character + '/' + event;
    if (!/^\d+$/.test(event) || !/^\d+$/.test(character) || this.linkedEvent === key
      || !this.profile.view || this.profile.detailLoading || this.profile.selected !== Number(character)) return;
    this.linkedEvent = key;
    void app.request<{data: HistoryEvent & {actor_id: number}}>({method: 'GET',
      url: app.forum.attribute<string>('apiUrl') + '/roleplay/characters/' + character + '/events/' + event,
      errorHandler: () => {},
    }).then(response => {
      if (this.disposed || this.profile.selected !== Number(character)
        || String(m.route.param('event') ?? '') !== event || String(m.route.param('character') ?? '') !== character) return;
      if (response.data.type === 'review_decided') showModal(ReviewHistoryModal, {events: [response.data]});
      else showModal(CharacterHistoryModal, {events: [response.data], lookups: this.profile.view?.lookups});
    }).catch(error => {
      if (!this.disposed && this.profile.selected === Number(character)) {
        app.alerts.show({type: 'error'}, requestMessage(error, 'Запись истории недоступна'));
      }
    });
  }

  onremove(vnode: Mithril.VnodeDOM<IUserPageAttrs, this>): void {
    this.disposed = true;
    this.profile.dispose();
    super.onremove(vnode);
  }
}
