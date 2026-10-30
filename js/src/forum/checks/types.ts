export interface Definition {id: number; name: string; icon?: string; slug?: string; attribute_id?: number}
export interface CheckPayload {kind: 'attribute' | 'skill'; target_id: number; difficulty: number | null}
export interface RequestPayload extends CheckPayload {description: string; target: 'all' | 'selected'; characters: number[]}
export interface RollPayload extends CheckPayload {character_id: number; request_id: string | null; modifier: number; reason: string}
export interface CharacterChoice {id: number; name: string; avatarUrl?: string | null}
export interface CharacterCheck extends Definition {bonus: number; level?: number; value?: number; extra: number}
export interface CheckCharacter {id: number; name: string; revision: number; attributes: CharacterCheck[]; skills: CharacterCheck[]}
export interface CheckContext {discussionId: number | null; requestId: string | null; request: CheckPayload | null;
  attributes: Definition[]; skills: Definition[]; characters: CharacterChoice[]; defaultCharacterId: number | null;
  difficulties: Array<{value: number; name: string}>}
