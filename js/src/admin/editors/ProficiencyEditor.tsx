import type {EditorContext} from '../../common/catalog/types';
import LearningDefinitionFields from './LearningDefinitionFields';

/** У владения нет уровня и бонуса урона. Конкретные значения будут задаваться предметом. */
export default function ProficiencyEditor(context: EditorContext): JSX.Element {
  return <div>
    {LearningDefinitionFields(context)}
  </div>;
}
