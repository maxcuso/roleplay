import type {Fields, Lookups} from '../../common/catalogData';

export interface CharacterSummary {id: number; name: string; status: string}
export interface RoleplayerStats {earned_experience: number; active_characters: number; started_at: string | null}
export interface CharacterList {characters: CharacterSummary[]; next_before: number | null; roleplayer?: RoleplayerStats}
export type ReviewPart = 'mechanics' | 'literature';
export type ReviewAction = 'approve' | 'request_changes' | 'submit' | 'withdraw';
export interface ReviewView {
  mechanics_status: string; literature_status: string; topic_available: boolean; topic_hash: string | null;
  permissions: {can_edit_mechanics?: boolean; can_moderate: boolean; can_edit_identity: boolean; can_submit_mechanics: boolean; can_submit_literature: boolean; can_withdraw_literature: boolean};
  history: {id: number; type: string; actor_id: number; created_at: string; details: Fields}[];
}
export interface ProfileView {
  roleplayer?: RoleplayerStats;
  snapshot: Fields; calculation: Fields; lookups: Lookups;
  permissions: {can_equip: boolean; can_manage?: boolean};
  review: ReviewView | null;
  development?: {rules_hash: string; attributes: Fields[]; skills: Fields[]; proficiencies: Fields[]} | null;
  history?: {id: number; type: string; actor_id: number; created_at: string; details: Fields}[];
}
interface VersionedCommand {operation_key: string; revision: number; catalog_revision: number}
export interface EquipmentCommand extends VersionedCommand {instance_id: number; equipped: boolean}
export interface ReviewCommand extends VersionedCommand {part: ReviewPart; action: ReviewAction; comment: string; topic_hash: string | null}
export interface IdentityCommand extends VersionedCommand {first_name: string; last_name: string; age: number}
export interface ProgressionCommand extends VersionedCommand {kind: 'attribute' | 'skill' | 'proficiency'; target_id: number; rules_hash: string}
export interface ManagementCommand extends VersionedCommand {action: string; data: Fields; comment: string}
/** Тело и UUID сохраняем до получения ответа, чтобы безопасно повторить любую команду. */
export type ProfileCommand =
  | {kind: 'equipment'; body: EquipmentCommand}
  | {kind: 'review'; body: ReviewCommand}
  | {kind: 'identity'; body: IdentityCommand}
  | {kind: 'progression'; body: ProgressionCommand}
  | {kind: 'management'; body: ManagementCommand};
export interface ProfileTransport {
  list(userId: number, before?: number): Promise<CharacterList>;
  read(characterId: number): Promise<ProfileView>;
  equip(characterId: number, command: EquipmentCommand): Promise<ProfileView>;
  review(characterId: number, command: ReviewCommand): Promise<ProfileView>;
  correct(characterId: number, command: IdentityCommand): Promise<ProfileView>;
  progress?(characterId: number, command: ProgressionCommand): Promise<ProfileView>;
  manage?(characterId: number, command: ManagementCommand): Promise<ProfileView>;
}
