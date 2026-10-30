/** В конце блока правил точка не нужна. Внутреннюю пунктуацию, многоточия
 * и сокращения сохраняем; данные справочников при этом не изменяются.
 */
export function guideText(value: unknown): string {
  const text = String(value ?? '').trimEnd();
  if (/(?:\.{3}|(?:^|\s)(?:т\.\s*[дпе]|др|ед|см|кг)\.)$/iu.test(text)) return text;
  return text.replace(/\.$/u, '');
}

export function guideParagraphs(value: string | null): string[] {
  return guideText(value).split(/\r?\n\s*\r?\n/).filter(Boolean);
}
