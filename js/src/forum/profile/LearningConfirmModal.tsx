import type Mithril from 'mithril';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Modal, {type IInternalModalAttrs, type ModalHeading} from '../../common/components/RoleplayModal';
import {roman, pathColor} from '../../common/presentation';
import {catalogueIcon, uiIcon} from '../../common/uiIcons';
import type ProfileState from './ProfileState';
import type {LearningSpell} from './types';

interface Attrs extends IInternalModalAttrs {profile: ProfileState; option: LearningSpell; onLearned: () => void}
export default class LearningConfirmModal extends Modal<Attrs> {
  source = 0;
  character: number | null = null;
  busy = false;
  oninit(vnode: Mithril.Vnode<Attrs, this>): void {
    super.oninit(vnode);
    this.source = this.attrs.option.sources[0]?.instance_id ?? 0;
    this.character = this.attrs.profile.selected;
  }
  protected modalClassName(): string {return 'RpCatalogEditor RpLearningConfirm';}
  protected modalHeading(): ModalHeading {return {label: 'Изучение заклинания', purpose: 'learn'};}
  content(): Mithril.Children {
    const {profile, option} = this.attrs;
    const source = option.sources.find(row => row.instance_id === this.source);
    const color = pathColor(String(option.path?.color ?? ''));
    return <div className="Modal-body">
      <div className="RpLearningConfirm-spell" style={{'--learning-color': color}}>
        <span className="RpLearningConfirm-pathIcon"><Icon name={catalogueIcon(String(option.path?.icon ?? ''))}/></span>
        <div><span className="RpLearningConfirm-label">{option.path?.name ?? 'Заклинание'}</span>
          <h3>{String(option.spell.name)} <span>{roman(Number(option.spell.level))}</span></h3>
        </div>
      </div>
      <div className="RpLearningConfirm-costs">
        <div><span className="RpLearningConfirm-label">Стоимость изучения</span>
          <strong><Icon name={uiIcon('resources.activeExperience')}/>{option.cost} <small>опыта</small></strong>
        </div>
        <div><span className="RpLearningConfirm-label">Останется после изучения</span>
          <strong>{Number(profile.view?.learning?.experience) - Number(option.cost)} <small>опыта</small></strong>
        </div>
      </div>
      <div className="RpLearningConfirm-source">
        <Icon name={uiIcon('item.literature')}/>
        <div><span className="RpLearningConfirm-label">Источник знания</span>
          {option.sources.length > 1 ? <select className="FormControl" value={String(this.source)} aria-label="Источник знания" disabled={this.busy}
            onchange={(event: Event) => this.source = Number((event.target as HTMLSelectElement).value)}>
            {option.sources.map(row => <option key={row.instance_id} value={String(row.instance_id)}>{row.name}</option>)}
          </select> : <strong>{source?.name}</strong>}
          <p>{source?.consumed ? 'При изучении расходуется одна единица этого предмета' : 'Книга останется в инвентаре'}</p>
        </div>
      </div>
      <div className="RpEditorActions">
        <Button type="button" className="Button" disabled={this.busy} onclick={() => this.hide()}>Отмена</Button>
        <Button type="button" className="Button Button--primary" loading={this.busy}
          disabled={this.busy || profile.actionsBlocked || profile.selected !== this.character} onclick={async () => {
            this.busy = true;
            const learned = await profile.develop('spell', option.id, this.source);
            this.busy = false;
            this.hide();
            if (learned) this.attrs.onLearned();
          }}>Изучить</Button>
      </div>
    </div>;
  }
}
