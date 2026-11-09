document.querySelectorAll('[data-ehrman-search-menu]').forEach(menu => {
  const summary = menu.querySelector('summary');
  const close = () => {
    menu.open = false;
    menu.querySelectorAll('details').forEach(details => { details.open = false; });
  };

  menu.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const expanded = event.target.closest('details[open]');
    if (!expanded) return;
    expanded.open = false;
    expanded.querySelector('summary').focus();
    event.preventDefault();
    event.stopPropagation();
  });
  menu.addEventListener('toggle', () => {
    if (!menu.open) close();
  });
  document.addEventListener('click', event => {
    if (!menu.contains(event.target)) close();
  });
  document.addEventListener('focusin', event => {
    if (!menu.contains(event.target)) close();
  });
  window.addEventListener('pageshow', () => {
    close();
    // Avoid leaving focus on a now-hidden link after back/forward navigation.
    if (menu.contains(document.activeElement) && document.activeElement !== summary) summary.focus();
  });
});
