import Feedback from '../../common/components/Feedback';
import {requestMessage} from '../../common/requestError';
import app from 'flarum/forum/app';
import Page, {type IPageAttrs} from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import icons from '../../../../resources/presentation/icons.json';
import {mountGuide} from '../../../../resources/guide/page.js';
import {guides} from '../../../../preview/role/content.js';
import {resolveIcon} from '../../common/uiIcons';
import CreationWizardModal from '../creation/CreationWizardModal';
import {mountPerkCards, type GuideCatalogData} from './GuidePerkCards';
import {mountDevelopment, type GuideDevelopmentData} from './GuideDevelopment';
import {mountRaces} from './GuideRaces';
import {observeGuideTheme} from './guideTheme';

interface GuideData extends GuideCatalogData, GuideDevelopmentData {
  html: string;
}

/** Оболочка Flarum. Сам HTML остаётся читаемым, без преобразования в JSX.
 * mountGuide управляет только содержимым этой страницы и снимает все обработчики.
 */
export default class GuidePage extends Page {
  private data: GuideData | null = null;
  private error = '';
  private disposed = false;
  private unmount?: () => void;
  private unmountTheme?: () => void;
  private unmountCards?: () => void;
  private unmountDevelopment?: () => void;
  private unmountRaces?: () => void;

  oninit(vnode: Mithril.Vnode<IPageAttrs, this>): void {
    super.oninit(vnode);
    this.bodyClass = 'GuidePage';
    app.setTitle('Ролевая');
    void this.load();
  }

  private async load(): Promise<void> {
    this.error = '';
    try {
      const result = await app.request<{data: GuideData}>({
        method: 'GET', url: app.forum.attribute<string>('apiUrl') + '/roleplay/guide', errorHandler: () => {},
      });
      if (!this.disposed) this.data = result.data;
    } catch (error) {
      if (!this.disposed) this.error = requestMessage(error, 'Не удалось загрузить путеводитель. Попробуйте обновить страницу');
    }
    if (!this.disposed) m.redraw();
  }

  view(): Mithril.Children {
    if (this.error) return <div className="container"><Feedback message={this.error}
      refresh={{label: 'Обновить путеводитель', run: () => void this.load()}}/></div>;
    if (!this.data) return <LoadingIndicator/>;
    // Доверенный HTML из файлов расширения: пользовательские сообщения сюда не подставляются.
    return <div className="RpGuide" oncreate={(vnode: Mithril.VnodeDOM) => this.mount(vnode.dom as HTMLElement)}
      onbeforeupdate={() => false}>{m.trust(this.data.html)}</div>;
  }

  private mount(root: HTMLElement): void {
    this.unmountTheme = observeGuideTheme(root);
    const resolvedIcons = Object.fromEntries(Object.entries(icons).map(([key, value]) => [key, resolveIcon(value)]));
    // Сначала карточки: общий обработчик затем оформит и новые значки уровней.
    this.unmountCards = mountPerkCards(root, this.data!);
    this.unmountDevelopment = mountDevelopment(root, this.data!);
    this.unmountRaces = mountRaces(root, this.data!);
    this.unmount = mountGuide(root, {
      icons: resolvedIcons,
      attributes: this.data!.attributes.map(row => ({...row, icon: row.icon ? resolveIcon(row.icon) : null})),
      guides,
      createCharacter: () => {
        if (!app.session.user) {
          // В Flarum 2 окно входа находится в отложенном чанке ядра.
          void app.modal.show(() => import('flarum/forum/components/LogInModal'), {});
          return;
        }
        if (app.forum.attribute('roleplayCanCreate')) void CreationWizardModal.open();
        else app.alerts.show({type: 'error'}, 'У вас нет доступа к созданию персонажей');
      },
    });
  }

  oncreate(vnode: Mithril.VnodeDOM<IPageAttrs, this>): void {
    super.oncreate(vnode);
    document.documentElement.classList.add('GuidePage');
  }

  onremove(vnode: Mithril.VnodeDOM<IPageAttrs, this>): void {
    this.disposed = true;
    this.unmount?.();
    this.unmountCards?.();
    this.unmountDevelopment?.();
    this.unmountRaces?.();
    this.unmountTheme?.();
    document.documentElement.classList.remove('GuidePage');
    super.onremove(vnode);
  }
}
