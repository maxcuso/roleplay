import EffectAttachmentsEditor from '../catalog/components/EffectAttachmentsEditor';
import type {EditorContext} from '../catalog/types';
import {TextField, TextArea, SelectField} from '../catalog/components/FormFields';
import CardPreviews from '../catalog/components/CardPreviews';
import ImageUpload from '../catalog/components/ImageUpload';
import PerkCard from '../cards/PerkCard';
import {rarityOptions} from '../presentation';

export default function PerkEditor({state, lookups}: EditorContext): JSX.Element {
  return <div>
    <CardPreviews render={(mode: 'compact' | 'expanded') => <PerkCard mode={mode} perk={state.values}/>}/>
    <div className="RpGrid RpGrid--2">
      {TextField({state, field: 'name', label: 'Название', required: true})}
      {SelectField({state, field: 'rarity', label: 'Редкость', options: rarityOptions})}
    </div>
    {TextArea({state, field: 'description', label: 'Описание'})}
    <ImageUpload state={state}/>
    {EffectAttachmentsEditor({state, lookups, owner: 'perks'})}
  </div>;
}
