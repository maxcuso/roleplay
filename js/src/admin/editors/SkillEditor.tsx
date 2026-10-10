import type {EditorContext} from '../../common/catalog/types';
import LearningDefinitionFields from './LearningDefinitionFields';
import {options, object} from '../../common/catalog/helpers';
import {roman} from '../../common/presentation';
import {FieldLabel} from '../../common/catalog/components/FormFields';
import EditorSection from '../../common/catalog/components/EditorSection';

/** Меняются только ID привязок. Описание, изображение и редкость редактируются в самом перке. */
export default function SkillEditor(context: EditorContext): JSX.Element {
  const {state, lookups} = context;
  const levels = Array.isArray(state.values.levels) ? state.values.levels.map(object) : [];
  const perkOptions = {'': 'Выбери перк', ...options(lookups, 'perks')};
  return <div>
    {LearningDefinitionFields(context)}
    <EditorSection title="Перки за уровни" icon="sections.perks">
      <div className="RpGrid RpGrid--3">
        {[1, 2, 3].map(level => {
          const selected = levels.find(row => Number(row.level) === level)?.perk_id;
          return <label key={level} className="RpField">
            <FieldLabel label={roman(level) + ' уровень'} icon="sections.perks"/>
            <select className="FormControl" required={state.flag('enabled')} value={selected ?? ''}
              onchange={(event: Event) => {
                const value = (event.target as HTMLSelectElement).value;
                // Читаем текущий черновик: соседний селектор мог измениться до перерисовки.
                const currentLevels = Array.isArray(state.values.levels) ? state.values.levels.map(object) : [];
                // Новый массив не меняет исходную строку таблицы.
                state.set('levels', [1, 2, 3].map(current => ({
                  level: current,
                  perk_id: current === level ? value === '' ? null : Number(value) :
                    currentLevels.find(row => Number(row.level) === current)?.perk_id ?? null,
                })));
              }}>
              {Object.entries(perkOptions).map(([value, title]) =>
                <option key={value || 'empty'} value={value}>{title}</option>)}
            </select>
          </label>;
        })}
      </div>
      <p className="helpText">Для включения навыка нужны все три перка. При повышении прежние перки сохраняются.</p>
    </EditorSection>
  </div>;
}
