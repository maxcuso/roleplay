import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import type ProfileState from './ProfileState';
import {text, type Fields} from '../../common/catalogData';
import {catalogueIcon, uiIcon} from '../../common/uiIcons';
import {roman} from '../../common/presentation';

interface Attrs {profile: ProfileState}
/** Выбор только доступной записи. Повторное нажатие снимает оранжевую рамку. */
export default class ProgressionTraining extends Component<Attrs> {
  chosen: {kind: 'skill' | 'proficiency'; id: number} | null = null;
  characterId: number | null = null;
  view(): Mithril.Children {
    const state = this.attrs.profile, view = state.view!;
    if (this.characterId !== state.selected) {this.characterId = state.selected; this.chosen = null;}
    const list: (Fields & {kind: 'skill' | 'proficiency'})[] = [...(view.development?.skills ?? []).map(row => ({...row, kind: 'skill' as const})),
      ...(view.development?.proficiencies ?? []).map(row => ({...row, kind: 'proficiency' as const}))];
    const selected = list.find(row => row.kind === this.chosen?.kind && Number(row.id) === this.chosen.id && row.allowed);
    if (!selected) this.chosen = null;
    // Изученность не зависит от доступности повышения: навык может быть
    // изучен и доступен для повышения, а владение не имеет уровней.
    const ownedProficiencies = new Set((Array.isArray(view.snapshot.proficiencies)
      ? view.snapshot.proficiencies as Fields[] : []).map(row => Number(row.id)));
    const entry = (row: Fields & {kind: 'skill' | 'proficiency'}): Mithril.Children => {
      const learned = row.kind === 'skill' ? Number(row.level) > 0 : ownedProficiencies.has(Number(row.id));
      return <button key={row.kind + ':' + row.id} type="button"
        className={'RpDevelopment-entry ' + (learned ? 'is-learned' : 'is-unlearned')
          + (selected === row ? ' is-selected' : '')}
        disabled={!row.allowed || state.actionsBlocked} title={text(row, 'reason')}
        aria-pressed={selected === row} onclick={() => {
          this.chosen = selected === row ? null : {kind: row.kind, id: Number(row.id)};
        }}>
        {text(row, 'name')}{row.kind === 'skill' && Number(row.level) > 0 ? ' ' + roman(Number(row.level)) : ''}
        {row.allowed && <Icon className="RpDevelopment-arrow" name={uiIcon('actions.upgrade')}/>}
      </button>;
    };
    return <div>
      <div className="RpSheet-training">{view.lookups.attributes.map(attribute => <div key={attribute.id}>
        <Icon name={catalogueIcon(text(attribute, 'icon'))}/>
        {list.filter(row => row.attribute_id === attribute.id && row.kind === 'skill').map(entry)}
        <hr/>
        {list.filter(row => row.attribute_id === attribute.id && row.kind === 'proficiency').map(entry)}
      </div>)}</div>
      <div className="RpDevelopment-confirm RpDevelopment-confirm--reserved">{selected && <Button className="Button Button--primary"
        disabled={state.actionsBlocked} loading={state.changing} onclick={async () => {
          if (await state.develop(selected.kind, Number(selected.id))) this.chosen = null;
        }}>{Number(selected.level) > 0 ? 'Повысить' : 'Изучить'}</Button>}</div>
    </div>;
  }
}