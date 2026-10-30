import type {CatalogDataSource, CatalogRow} from '../../common/catalog/types';

export type RewardKind = 'items' | 'perks' | 'spells';
export interface ExperienceBonus {name: string; percent: number}
export interface CompletionCharacter {
  id: number;
  name: string;
  avatarUrl?: string | null;
  available: boolean;
  bonuses: ExperienceBonus[];
  perkIds: number[];
  spellIds: number[];
}
export interface CompletionContext {
  eventId: number | null;
  isQuest: boolean;
  discussionId: number;
  title: string;
  revision: string;
  characters: CompletionCharacter[];
}

/** Только чтение. Права и поиск на сервере подключает вызывающий код;
 * форма не получает транспорт выдачи, закрытия темы или изменения персонажа.
 * Списки используют существующий контракт поиска и страниц справочников.
 */
export interface CompletionDataSource {
  characters: CatalogDataSource;
  catalog(kind: RewardKind): CatalogDataSource;
  character(id: number): Promise<CompletionCharacter>;
}
export interface RewardSelection {row: CatalogRow; quantity: number}
export interface CharacterRewardDraft {
  character: CompletionCharacter;
  experience: number;
  gold: number;
  items: RewardSelection[];
  perks: RewardSelection[];
  spells: RewardSelection[];
}
export interface CompletionPayload {
  event_id: number | null;
  discussion_id: number;
  revision: string;
  recipients: Array<{
    character_id: number;
    experience: number;
    gold: number;
    items: Array<{id: number; quantity: number}>;
    perks: number[];
    spells: number[];
  }>;
}
