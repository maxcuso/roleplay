import Modal, {type IInternalModalAttrs, type ModalHeading} from '../../common/components/RoleplayModal';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import {type Fields, type Lookups} from '../../common/catalogData';
import {effectLabel, effectSource, effectIcon} from '../../common/character/effectPresentation';

interface Attrs extends IInternalModalAttrs {effects: Fields[]; lookups?: Lookups}

/** Только объяснение готовых эффектов. Окно не меняет состояние и не считает бонусы повторно. */
export default class CharacterEffectsModal extends Modal<Attrs> {
  protected modalClassName(): string { return 'RpEffectsModal'; }
  protected modalHeading(): ModalHeading { return {label: 'Эффекты', purpose: 'effects'}; }
  content(): Mithril.Children {
    const effects = this.attrs.effects;
    return <div className="Modal-body">
      {!effects.length ? <p className="RpEffects-empty">Эффектов нет.</p> : <ul className="RpEffects-list">
        {effects.map((effect, index) => {
          return <li key={index}>
            <span className="RpEffects-source" tabindex={0} title={effectSource(effect)} aria-label={effectSource(effect)}>
              <Icon name={effectIcon(effect, this.attrs.lookups)}/>
            </span>
            <span className="RpEffects-label">{effectLabel(effect, this.attrs.lookups)}</span>
          </li>;
        })}
      </ul>}
    </div>;
  }
}
