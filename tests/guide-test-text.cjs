/** Смысловые проверки сравнивают текст без различий в ширине пробела.
 * Неразрывность отдельно проверяет check-guide-typography-forum.cjs.
 */
function normalizeGuideText(value) {
  if (typeof value === 'string') return value.replace(/[\u00a0\u202f]/g, ' ');
  return Array.isArray(value) ? value.map(normalizeGuideText) : value;
}
module.exports = {normalizeGuideText};
