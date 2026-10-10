import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import type {UiIconName} from '../../uiIcons';
import {FieldLabel} from './FormFields';

/** Раздел отделяется от соседнего одним отступом и тонкой линией.
 * Поля внутри остаются обычными полями: не создаём отдельные панели и вкладки. */
interface Attrs {title: string; icon: UiIconName}
export default class EditorSection extends Component<Attrs> {
  view(vnode: Mithril.Vnode<Attrs, this>): JSX.Element {
    return <section className="RpEditorSection">
      <h4><FieldLabel label={this.attrs.title} icon={this.attrs.icon}/></h4>
      {vnode.children}
    </section>;
  }
}
