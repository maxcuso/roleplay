import type CreationState from '../CreationState';
import CharacterSheet from '../../../common/character/CharacterSheet';

export default function ReviewStep(state: CreationState): JSX.Element {
  const view = state.view!;
  return <div>
    <p className="RpWizard-instruction">Проверьте анкету персонажа перед созданием.</p>
    {view.preview.review ? <CharacterSheet review={view.preview.review} lookups={view.catalog.definitions}/>
      : <p>Завершите предыдущие шаги, чтобы увидеть итог.</p>}
  </div>;
}
