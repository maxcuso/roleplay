import app from 'flarum/forum/app';
import UserPage, {type IUserPageAttrs} from 'flarum/forum/components/UserPage';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';
import ProfileApi from './ProfileApi';
import ProfileState from './ProfileState';
import ProfilePanel from './ProfilePanel';

/** Перенесён сценарий старой вкладки. Загрузка и переключение вынесены в тестируемое состояние. */
export default class RoleplayProfilePage extends UserPage {
  profile = new ProfileState(new ProfileApi(), () => m.redraw());
  userError = '';

  oninit(vnode: Mithril.Vnode<IUserPageAttrs, this>): void {
    super.oninit(vnode);
    void this.loadUser(m.route.param('username')).catch(() => {
      this.userError = 'Не удалось открыть профиль пользователя.';
      m.redraw();
    });
  }

  show(user: User): void {
    super.show(user);
    const requested = Number(m.route.param('character'));
    const preferred = Number.isSafeInteger(requested) && requested > 0 ? requested : null;
    if (app.forum.attribute('roleplayCanView')) void this.profile.loadUser(Number(user.id()), preferred);
  }

  content(): Mithril.Children {
    if (!app.forum.attribute('roleplayCanView')) return <p>Нет доступа к ролевым персонажам.</p>;
    if (this.userError) return <p role="alert">{this.userError}</p>;
    return <ProfilePanel state={this.profile} picker own={app.session.user?.id() === this.user?.id()}/>;
  }

  onremove(vnode: Mithril.VnodeDOM<IUserPageAttrs, this>): void {
    this.profile.dispose();
    super.onremove(vnode);
  }
}