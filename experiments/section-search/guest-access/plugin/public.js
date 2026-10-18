/* global ehrmanSectionPublic */
(() => {
  'use strict';
  const form = document.querySelector('#section-public-form');
  if (!form) return;
  const question = document.querySelector('#section-public-question');
  const button = form.querySelector('button');
  const status = document.querySelector('#section-public-status');
  const error = document.querySelector('#section-public-error');
  const results = document.querySelector('#section-public-results');
  const tiers = {direct: 'Directly relevant', related: 'Related', background: 'Background'};

  function element(tag, text, className) {
    const node = document.createElement(tag);
    node.textContent = text;
    if (className) node.className = className;
    return node;
  }

  function render(data) {
    if (!Array.isArray(data.posts)) throw new Error('Unexpected search response. Please try again.');
    results.append(element('h2', `${data.posts.length} ${data.posts.length === 1 ? 'post' : 'posts'} found`));
    if (data.notice) results.append(element('p', data.notice));
    for (const post of data.posts) {
      const article = element('article', '', 'section-public-result');
      const heading = element('h3', '');
      const url = new URL(post.url, location.origin);
      if (!['http:', 'https:'].includes(url.protocol)) continue;
      const link = element('a', post.title);
      link.href = url.href;
      heading.append(link);
      article.append(heading);
      const tier = tiers[data.tiers?.[post.id]];
      if (tier) article.append(element('div', tier, 'section-public-tier'));
      if (post.description) article.append(element('p', post.description));
      results.append(article);
    }
  }

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (button.disabled) return;
    const value = question.value.trim();
    question.setCustomValidity(value ? '' : 'Enter a question or request.');
    if (!form.reportValidity()) return;
    button.disabled = true;
    question.readOnly = true;
    results.replaceChildren();
    results.setAttribute('aria-busy', 'true');
    error.hidden = true;
    status.textContent = 'Finding and reviewing posts...';
    try {
      const response = await fetch(ehrmanSectionPublic.url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'X-WP-Nonce': ehrmanSectionPublic.nonce},
        body: JSON.stringify({question: value})
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Search could not be completed. Please try again.');
      render(data);
      status.textContent = 'Search complete.';
    } catch (failure) {
      results.replaceChildren();
      error.textContent = failure.message || 'Search could not be completed. Please try again.';
      error.hidden = false;
      status.textContent = '';
    } finally {
      button.disabled = false;
      question.readOnly = false;
      results.setAttribute('aria-busy', 'false');
    }
  });

  question.addEventListener('input', () => question.setCustomValidity(''));
})();
