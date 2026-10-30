import app from 'flarum/forum/app';
import UserPage, {type IUserPageAttrs} from 'flarum/forum/components/UserPage';
import type Mithril from 'mithril';
import Button from 'flarum/common/components/Button';
import Feedback from '../../common/components/Feedback';
import WorkspaceCatalog from './WorkspaceCatalog';
import WorkspaceEpisodes from './WorkspaceEpisodes';
import type {WorkspaceCatalogKey} from './WorkspaceCatalogApi';

/** Личное рабочее пространство аккаунта: не загружает и не выбирает персонажа. */
export default class WorkspacePage extends UserPage {
  selected: 'episodes' | WorkspaceCatalogKey = 'episodes';
  error = '';

  oninit(vnode: Mithril.Vnode<IUserPageAttrs, this>): void {
    super.oninit(vnode);
    void this.loadUser(m.route.param('username')).catch(() => {
      this.error = 'Не удалось открыть профиль. Попробуйте обновить страницу';
      m.redraw();
    });
  }

  content(): Mithril.Children {
    if (this.error) return <Feedback message={this.error}/>;
    if (!this.user) return null;
    if (!app.forum.attribute('roleplayCanUseWorkspace') || app.session.user?.id() !== this.user.id()) {
      return <Feedback message="У вас нет доступа к этой модераторской" inline/>;
    }
    const tabs: Array<{key: 'episodes' | WorkspaceCatalogKey; title: string; icon: string}> = [
      {key: 'episodes', title: 'Мои эпизоды', icon: 'fas fa-calendar-alt'},
    ];
    if (app.forum.attribute('roleplayWorkspaceCatalogs')) tabs.push(
      {key: 'perks', title: 'Перки', icon: 'fas fa-star'},
      {key: 'spells', title: 'Заклинания', icon: 'fas fa-book'},
      {key: 'items', title: 'Предметы', icon: 'fas fa-toolbox'},
    );
    return <div className="RpWorkspace">
      <nav key="navigation" className="RpWorkspace-tabs" aria-label="Разделы модераторской">
        {tabs.map(tab => <Button key={tab.key} className={'Button ' + (this.selected === tab.key ? 'Button--primary' : '')}
          icon={tab.icon} aria-pressed={String(this.selected === tab.key)} onclick={() => {this.selected = tab.key;}}>{tab.title}</Button>)}
      </nav>
      {this.selected === 'episodes' ? <WorkspaceEpisodes key="episodes"/>
        : <WorkspaceCatalog key={this.selected} catalog={this.selected}/>}
    </div>;
  }
}
