import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import ProfileApi from '../profile/ProfileApi';
import CreationWizardModal from './CreationWizardModal';
import {requestMessage} from '../../common/requestError';

/** Проверяем актуальный список при нажатии: создание и удаление не оставляют старую ссылку. */
export default class CharacterButton extends Component {
  private loading = false;
  private disposed = false;

  view(): Mithril.Children {
    return <Button className="Button" icon="fas fa-user" loading={this.loading}
      disabled={Boolean(app.session.user) && !app.forum.attribute('roleplayCanView')}
      onclick={() => void this.open()}>Персонаж</Button>;
  }

  onremove(): void {this.disposed = true;}

  private async open(): Promise<void> {
    if (this.loading) return;
    const user = app.session.user;
    if (!user) {
      void app.modal.show(() => import('flarum/forum/components/LogInModal'), {});
      return;
    }
    const route = m.route.get();
    const current = () => !this.disposed && app.session.user === user && m.route.get() === route;
    this.loading = true;
    try {
      const list = await new ProfileApi().list(Number(user.id()));
      if (!current()) return;
      if (list.characters.length) {
        m.route.set(app.route('user.roleplay', {username: user.slug()}));
      } else if (app.forum.attribute('roleplayCanCreate')) {
        await CreationWizardModal.open();
      } else {
        app.alerts.show({type: 'warning'}, 'У вас нет разрешения на создание персонажа');
      }
    } catch (error) {
      if (current()) app.alerts.show({type: 'error'},
        requestMessage(error, 'Не удалось открыть персонажа. Попробуйте ещё раз'));
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
}
