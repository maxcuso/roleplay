import type {CatalogRow, Lookups, Fields} from '../../common/catalogData';
import type {UiIconName} from '../../common/uiIcons';

export const steps: {key: CreationStep; name: string; icon: UiIconName}[] = [
  {key: 'basics', name: 'Общие сведения', icon: 'wizard.basics'},
  {key: 'attributes', name: 'Характеристики', icon: 'wizard.attributes'},
  {key: 'training', name: 'Навыки и владения', icon: 'wizard.training'},
  {key: 'perks', name: 'Перки', icon: 'wizard.perks'},
  {key: 'spells', name: 'Заклинания', icon: 'wizard.spells'},
  {key: 'loadout', name: 'Снаряжение', icon: 'wizard.loadout'},
  {key: 'review', name: 'Итог', icon: 'wizard.review'},
];
export type CreationStep = 'basics' | 'attributes' | 'training' | 'perks' | 'spells' | 'loadout' | 'review';
export interface CreationPayload {
  first_name: string; last_name: string; age: number | null; race_id: number | null;
  base_attributes: Record<string, number | null>; racial_attributes: string[];
  skills: number[]; proficiencies: number[]; racial_proficiency_ids: number[];
  perk_ids: number[]; spell_ids: number[]; racial_spell_ids: number[]; loadout: Record<string, string[]>;
}
export interface LoadoutOption {key: string; name: string; items: {item_id: number; quantity: number}[]}
export interface LoadoutGroup {key: string; name: string; automatic: boolean; min: number; max: number; options: LoadoutOption[]}
export interface CreationCatalog {
  revision: number; rules_hash: string; definitions: Lookups;
  configuration: {enabled: boolean; attribute_distribution: number[]; loadout: LoadoutGroup[];
    perk_sources: {race?: number; skill?: number}; starting_gold: number; starting_experience: number};
}
export interface TrainingSlot {
  attribute_id: number; kind: 'skill' | 'proficiency'; threshold: number; ordinal: number;
  payload_index: number | null; selected_id: number | null; options: {id: number; name: string; level: number | null}[];
}
export interface Grant {id: number; source_id: number; rarity: string; kind: string; context: Fields}
export interface CharacterReview {snapshot: Fields; calculation: Fields}
export interface CreationPreview {
  valid: boolean; errors: Record<string, string[]>; step_validity: Record<CreationStep, boolean>;
  attributes: Record<string, Fields>; training_slots: TrainingSlot[]; racial_proficiency_ids: number[];
  perks: Grant[]; spells: CatalogRow[]; skills: Fields[]; proficiencies: Fields[];
  spell_choices: {ordinary_count?: number; ordinary_ids?: number[]; racial_count?: number; racial_ids?: number[]};
  review: CharacterReview | null;
}
export interface CreationView {
  catalog: CreationCatalog; preview: CreationPreview; existing_character_id: number | null;
  draft: {id: number; revision: number; catalog_revision: number; rules_hash: string; step: string; payload: CreationPayload};
}
export interface CreationCommand {
  operation_key: string; draft_id: number; draft_revision: number; catalog_revision: number; rules_hash: string;
}
export interface CreationTransport {
  read(): Promise<CreationView>;
  save(body: {revision: number; catalog_revision: number; rules_hash: string; step: CreationStep; payload: CreationPayload}): Promise<CreationView>;
  create(body: CreationCommand): Promise<{character_id: number}>;
}
