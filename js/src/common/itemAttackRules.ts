import modes from '../../../resources/rules/item-attacks.json';

/** Тот же контракт читает PHP. Название/ID нужного владения берётся из каталога, не из этого файла. */
export const itemAttackModes: ReadonlyArray<{
  mode: string; flag: string; proficiency: string; label: string;
}> = modes;