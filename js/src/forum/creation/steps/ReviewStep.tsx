import type CreationState from '../CreationState';
import Icon from 'flarum/common/components/Icon';
import {object, text, lookup, type Fields} from '../../../common/catalogData';
import {catalogueIcon, uiIcon} from '../../../common/uiIcons';
import {roman, safeImage, pathColor} from '../../../common/presentation';
import {sourceLabel} from '../../../common/perkSource';
import CharacterAvatarUpload from '../CharacterAvatarUpload';

const rows = (value: unknown): Fields[] => Array.isArray(value) ? value as Fields[] : [];

/** Краткий итог выбора. Полная анкета с расчётами открывается уже в профиле. */
export default function ReviewStep(state: CreationState): JSX.Element {
  const view = state.view!, review = view.preview.review, lookups = view.catalog.definitions;
  const character = view.draft.payload;
  const snapshot = object(review?.snapshot), calculation = object(review?.calculation);
  const skills = [...rows(snapshot.skills), ...rows(snapshot.proficiencies)];
  return <div className="RpWizard-review">
    <p className="RpWizard-instruction">Проверьте основные сведения и выбранные способности</p>
    <div className="RpWizard-reviewSummary">
      <section className="RpWizard-panel">
        <CharacterAvatarUpload state={state}/>
        <dl className="RpWizard-identity">{[
          ['Имя', character.first_name], ['Фамилия или род', character.last_name || '—'],
          ['Возраст', character.age ?? '—'], ['Раса', text(lookup(lookups, 'races', character.race_id) ?? {}, 'name') || '—'],
        ].map(([label, value]) => <div key={String(label)}><dt>{label}</dt><dd>{value}</dd></div>)}</dl>
      </section>
      {review &&
        <section className="RpWizard-panel">
          <h3>Характеристики</h3>
          <div className="RpWizard-reviewAttributes">{Object.entries(object(calculation.effective_attributes)).map(([slug, raw]) => {
            const row = object(raw), definition = lookup(lookups, 'attributes', row.id) ?? {};
            return <div key={slug} data-attribute={slug}><Icon name={catalogueIcon(text(definition, 'icon'))}/>
              <strong>{Number(row.level)}</strong><span>{text(row, 'name')}</span></div>;
          })}</div>
          <h3>Навыки и владения</h3>
          {skills.length ? <ul className="RpWizard-reviewTraining">{skills.map((row, index) => {
            const attribute = lookup(lookups, 'attributes', object(row.definition).attribute_id) ?? {};
            return <li key={index} data-attribute={text(attribute, 'slug')}><Icon name={catalogueIcon(text(attribute, 'icon'))}/>
              {text(row, 'name')}{row.level ? ' ' + roman(Number(row.level)) : ''}</li>;
          })}</ul> : <p className="RpWizard-help">Не выбрано — доступные выборы сохранятся в профиле</p>}
        </section>}
    </div>
    {review ? <>
      <div className="RpWizard-reviewAbilities">
        <section className="RpWizard-panel"><h3>Перки</h3>
          <ul>{rows(snapshot.perks).map((row, index) => {
            const definition = object(row.definition), origin = object(row.source);
            const source = sourceLabel(object(origin.definition), object(origin.context), lookups.attributes);
            const image = safeImage(text(definition, 'image'));
            return <li key={index} title={text(definition, 'description')}>
              {image ? <img src={image} alt=""/> : <Icon name={catalogueIcon(source.icon)}/>}<span>{text(row, 'name')}</span></li>;
          })}</ul>{!rows(snapshot.perks).length && <p className="RpWizard-help">Не выбрано</p>}
        </section>
        <section className="RpWizard-panel"><h3>Заклинания</h3>
          <ul>{rows(snapshot.spells).map(row => <li key={Number(row.id)} title={text(object(row.definition), 'description')}>
            <Icon style={{color: pathColor(text(object(row.path), 'color'))}}
              name={row.path ? catalogueIcon(text(object(row.path), 'icon')) : uiIcon('sections.spells')}/>
            <span>{text(row, 'name')} {roman(Number(object(row.definition).level))}</span></li>)}</ul>
          {!rows(snapshot.spells).length && <p className="RpWizard-help">Не выбрано</p>}
        </section>
      </div>
    </> : <p className="RpWizard-help">Завершите предыдущие шаги, чтобы увидеть итог</p>}
  </div>;
}
