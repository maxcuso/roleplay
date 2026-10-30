import app from 'flarum/forum/app';

/** Обе страницы используют одну палитру и следуют переключателю темы форума. */
export function observeGuideTheme(root: HTMLElement): () => void {
  const sync = () => {
    root.dataset.theme = app.colorScheme.startsWith('dark') || document.documentElement.style.colorScheme === 'dark'
      || document.documentElement.dataset.theme === 'dark' || document.body.classList.contains('dark') ? 'dark' : 'light';
  };
  sync();
  const observer = new MutationObserver(sync);
  observer.observe(document.documentElement, {attributes: true, attributeFilter: ['style', 'data-theme', 'class']});
  observer.observe(document.body, {attributes: true, attributeFilter: ['class']});
  return () => observer.disconnect();
}
