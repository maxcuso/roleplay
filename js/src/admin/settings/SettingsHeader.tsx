import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import {uiIcon, type UiIconName} from '../../common/uiIcons';

interface Attrs {title: string; icon: UiIconName; caption?: string; disabled: boolean; refresh: () => void}

/** Общий заголовок форм настроек; обновление занимает одну иконку, а не отдельную строку. */
export default class SettingsHeader extends Component<Attrs> {
  view(vnode: Mithril.Vnode<Attrs, this>): JSX.Element {
    return <header className="RpSettings-header">
      <span className="RpSettings-headingIcon"><Icon name={uiIcon(this.attrs.icon)}/></span>
      <div><h2>{this.attrs.title}</h2>{this.attrs.caption && <p>{this.attrs.caption}</p>}</div>
      <div className="RpSettings-headerActions">
        {vnode.children}
        <Button className="Button Button--icon" icon={uiIcon('actions.refresh')} title="Обновить настройки"
          aria-label="Обновить настройки" disabled={this.attrs.disabled} onclick={this.attrs.refresh}/>
      </div>
    </header>;
  }
}
