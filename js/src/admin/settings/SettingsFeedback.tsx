import Feedback from '../../common/components/Feedback';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import {uiIcon} from '../../common/uiIcons';

interface Attrs {state: {loading: boolean; error: string; saved: boolean}}

/** Статусы всех форм выглядят одинаково. Пустой блок не занимает место. */
export default class SettingsFeedback extends Component<Attrs> {
  view(): JSX.Element {
    const state = this.attrs.state;
    return <div className="RpSettings-feedback">
      {state.loading && <p role="status"><Icon name={uiIcon('actions.refresh')}/> Загрузка…</p>}
      {state.error && <Feedback message={state.error}/>}
      {state.saved && <p role="status"><Icon name={uiIcon('actions.select')}/> Изменения сохранены.</p>}
    </div>;
  }
}
