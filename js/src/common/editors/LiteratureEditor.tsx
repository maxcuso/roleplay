import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type {CatalogRow, EditorContext} from '../catalog/types';
import EditorSection from '../catalog/components/EditorSection';
import MultiPicker from '../catalog/components/MultiPicker';
import {CheckField, FieldLabel} from '../catalog/components/FormFields';
import {lookup, number, text} from '../catalog/helpers';
import {roman} from '../presentation';
import {catalogueIcon, uiIcon} from '../uiIcons';

export interface KnowledgeLink {type: 'spell'; knowledge_id: number}

/** Список хранит только ссылки. Параметры и названия читаются из общих справочников. */
export default class LiteratureEditor extends Component<EditorContext> {
  selecting = false;
  path = '';
  circle = '';

  contents(): KnowledgeLink[] {
    const value = this.attrs.state.values.literature_contents;
    return Array.isArray(value) ? value as KnowledgeLink[] : [];
  }

  view(): JSX.Element {
    const {state, lookups} = this.attrs;
    const contents = this.contents();
    const selected = contents.map(entry => entry.knowledge_id);
    const spells = lookups.spells ?? [];
    const circles = [...new Set(spells.map(spell => number(spell, 'level')))].sort((a, b) => a - b);
    const limit = state.flag('consumed_on_learning') ? 1 : 1000;
    return <EditorSection title="Содержимое" icon="item.literature">
      {CheckField({state, field: 'consumed_on_learning', label: 'Расходуется при изучении',
        icon: 'item.literature', disabled: contents.length > 1})}
      <p className="helpText">{contents.length > 1
        ? 'Для расходуемого источника оставьте одно знание. Многоразовая книга сохраняется после изучения'
        : state.flag('consumed_on_learning')
          ? 'Источник содержит одно знание. При изучении расходуется одна единица предмета'
          : 'Книга сохраняется после изучения. В неё можно добавить несколько знаний'}</p>
      {contents.length > 0 ? <div className="RpLiterature-list" role="list" aria-label="Содержимое литературы">
        {contents.map(entry => {
          const spell = lookup(lookups, 'spells', entry.knowledge_id);
          const path = lookup(lookups, 'paths', spell?.magic_path_id);
          return <div key={entry.type + ':' + entry.knowledge_id} className="RpLiterature-row" role="listitem">
            <Icon name={catalogueIcon(text(path ?? {}, 'icon'))}/>
            <span>{spell?.name ?? 'Недоступное знание'}{spell && !spell.enabled && <small>Отключено</small>}</span>
            <span className="RpLiterature-circle">{spell ? roman(number(spell, 'level')) + ' круг' : '—'}</span>
            <Button type="button" className="Button Button--icon" icon={uiIcon('actions.delete')}
              aria-label={'Убрать ' + (spell?.name ?? 'знание')}
              onclick={() => state.set('literature_contents', this.contents().filter(row => row.knowledge_id !== entry.knowledge_id))}/>
          </div>;
        })}
      </div> : <p className="helpText">Знания пока не добавлены</p>}
      <Button type="button" className="Button Button--link" icon={uiIcon(this.selecting ? 'actions.save' : 'actions.create')}
        aria-expanded={String(this.selecting)} onclick={() => this.selecting = !this.selecting}>
        {this.selecting ? 'Готово' : 'Добавить знания'}
      </Button>
      {this.selecting && <div className="RpLiterature-picker">
        <div className="RpGrid RpGrid--2">
          <label className="RpField"><FieldLabel label="Путь магии" icon="sections.paths"/>
            <select className="FormControl" value={this.path} onchange={(event: Event) => this.path = (event.target as HTMLSelectElement).value}>
              <option value="">Все пути</option>
              {(lookups.paths ?? []).map(path => <option key={path.id} value={String(path.id)}>{path.name}</option>)}
            </select>
          </label>
          <label className="RpField"><FieldLabel label="Круг" icon="fields.level"/>
            <select className="FormControl" value={this.circle} onchange={(event: Event) => this.circle = (event.target as HTMLSelectElement).value}>
              <option value="">Все круги</option>
              {circles.map(circle => <option key={circle} value={String(circle)}>{roman(circle)}</option>)}
            </select>
          </label>
        </div>
        <MultiPicker rows={spells.filter(spell => (!this.path || String(spell.magic_path_id) === this.path)
          && (!this.circle || String(spell.level) === this.circle))}
          selected={selected} blocked={contents.length >= limit ? spells.map(spell => spell.id) : []}
          icon={(spell: CatalogRow) => text(lookup(lookups, 'paths', spell.magic_path_id) ?? {}, 'icon')}
          label={(spell: CatalogRow) => spell.name + ' · ' + roman(number(spell, 'level')) + (!spell.enabled ? ' · Отключено' : '')}
          onchange={(ids: number[]) => state.set('literature_contents', ids.map(id => ({type: 'spell', knowledge_id: id})))}/>
      </div>}
    </EditorSection>;
  }
}
