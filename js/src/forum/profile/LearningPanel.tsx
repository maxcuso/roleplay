import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Tooltip from 'flarum/common/components/Tooltip';
import SpellCard from '../../common/cards/SpellCard';
import {roman} from '../../common/presentation';
import {uiIcon, type UiIconName} from '../../common/uiIcons';
import showModal from '../../common/showModal';
import type ProfileState from './ProfileState';
import type {LearningSpell} from './types';
import LearningConfirmModal from './LearningConfirmModal';

const categories: {id: string; label: string; icon: UiIconName}[] = [
  {id: 'spells', label: 'Заклинания', icon: 'sections.spells'},
  {id: 'recipes', label: 'Рецепты', icon: 'item.ingredient'},
  {id: 'blueprints', label: 'Чертежи', icon: 'fields.description'},
  {id: 'enchantments', label: 'Чары', icon: 'item.magical'},
];

/** Общая вкладка знаний. Карточки заклинаний остаются теми же, что в анкете и мастере. */
export default class LearningPanel extends Component<{profile: ProfileState}> {
  category = 'spells';
  search = '';
  path = '';
  circle = '';

  private confirm(option: LearningSpell): void {
    showModal(LearningConfirmModal, {profile: this.attrs.profile, option, onLearned: () => {
      app.alerts.show({type: 'success'}, 'Заклинание изучено и добавлено в анкету');
    }});
  }

  view(): JSX.Element {
    const profile = this.attrs.profile, learning = profile.view?.learning;
    if (!learning) return <div className="RpProfile-placeholder">Обучение доступно владельцу активного персонажа</div>;
    const spells = this.category === 'spells';
    const paths = [...new Map(learning.spells.filter(row => row.path).map(row => [row.path.id, row.path])).values()];
    const circles = [...new Set(learning.spells.map(row => Number(row.spell.level)))].sort((a, b) => a - b);
    const rows = learning.spells.filter(row => (!this.path || String(row.path?.id) === this.path)
      && (!this.circle || String(row.spell.level) === this.circle)
      && String(row.spell.name).toLocaleLowerCase().includes(this.search.trim().toLocaleLowerCase()));
    return <section className="RpLearning">
      <header className="RpLearning-intro">
        <div className="RpLearning-pattern" aria-hidden="true"><span/><span/><span/></div>
        <h2><span className="RpLearning-icon"><Icon name={uiIcon('item.literature')}/></span>Обучение</h2>
        <p>Изучайте знания из литературы, которая есть у персонажа</p>
      </header>
      <div className="RpLearning-categories" role="group" aria-label="Виды знаний">{categories.map(category =>
        <Button key={category.id} type="button" className="Button" aria-pressed={this.category === category.id}
          disabled={profile.actionsBlocked} onclick={() => {this.category = category.id;}}>
          <Icon name={uiIcon(category.icon)}/><span>{category.label}</span>
        </Button>)}</div>
      <div className={'RpLearning-filters' + (!spells ? ' RpLearning-filters--general' : '')}>
        <input className="FormControl" type="search" placeholder="Найти заклинание" aria-label="Поиск заклинаний"
          disabled={!spells} value={this.search} oninput={(event: Event) => this.search = (event.target as HTMLInputElement).value}/>
        {spells && <select className="FormControl" aria-label="Путь магии" value={this.path}
          onchange={(event: Event) => this.path = (event.target as HTMLSelectElement).value}>
          <option value="">Все пути</option>{paths.map(path => <option key={path.id} value={String(path.id)}>{path.name}</option>)}
        </select>}
        {spells && <select className="FormControl" aria-label="Круг заклинания" value={this.circle}
          onchange={(event: Event) => this.circle = (event.target as HTMLSelectElement).value}>
          <option value="">Все круги</option>{circles.map(circle => <option key={circle} value={String(circle)}>{roman(circle)} круг</option>)}
        </select>}
        <Tooltip text="Активный опыт персонажа — его можно потратить на изучение знаний и повышение характеристик">
          <Button type="button" className="Button Button--link RpLearning-experience" aria-label="Активный опыт персонажа">
            <Icon name={uiIcon('resources.activeExperience')}/>{learning.experience} опыта
          </Button>
        </Tooltip>
      </div>
      {!spells ? <p className="RpLearning-empty" role="status">Этот раздел в разработке</p>
        : <>
          <div className="RpLearning-count">Неизученные заклинания · {rows.length}</div>
          {rows.length === 0 && <p className="RpLearning-empty" role="status">{learning.spells.length
            ? 'По этим условиям заклинания не найдены' : 'Нет неизученных заклинаний в имеющейся литературе. Получите новую книгу или свиток'}</p>}
          <div className="RpLearning-grid">{rows.map(option => <article key={option.id} className={'RpLearning-entry' + (!option.allowed ? ' is-unavailable' : '')}>
            <div className="RpLearning-source"><Icon name={uiIcon('item.literature')}/>{option.sources.map(source => source.name).join(', ')}</div>
            <div className="RpLearning-card"><SpellCard mode="expanded" spell={option.spell} path={option.path}/></div>
            <div className="RpLearning-action">
              <span className="RpLearning-cost"><Icon name={uiIcon('resources.activeExperience')}/>{option.cost === null ? '—' : option.cost + ' опыта'}</span>
              {option.allowed ? <Button type="button" className="Button Button--primary" icon={uiIcon('item.magical')}
                disabled={profile.actionsBlocked} onclick={() => this.confirm(option)}>Изучить</Button>
                : <div className="RpLearning-reasons">{option.reasons.map((reason, index) => <span key={index}>{reason.replace(/Требуется (\d+) круг/, (_match, circle: string) => 'Требуется ' + roman(Number(circle)) + ' круг')}</span>)}</div>}
            </div>
          </article>)}</div>
        </>}
    </section>;
  }
}
