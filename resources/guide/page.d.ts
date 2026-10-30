interface GuideOptions {
  icons: Readonly<Record<string, string>>;
  localIcons?: {enabled: boolean; replacements: Readonly<Record<string, string>>};
  guides: ReadonlyArray<{title: string; icon: string; tone: string}>;
  attributes?: ReadonlyArray<{slug: string; icon?: string | null}>;
  characters?: ReadonlyArray<unknown>;
  createCharacter?: () => void;
  storageKey?: string;
}
export function mountGuide(root: HTMLElement, options: GuideOptions): () => void;
export function applyGuideTypography(root: HTMLElement): void;
