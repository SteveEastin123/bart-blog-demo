(() => {
  'use strict';
  const root = document.querySelector('#section-pilot');
  if (!root) return;
  const form = root.querySelector('form');
  const status = root.querySelector('#pilot-status');
  const results = root.querySelector('#pilot-results');
  const feedback = root.querySelector('#pilot-feedback');
  let requestIds = [];
  const node = (tag, text, className) => {
    const el = document.createElement(tag);
    if (text !== undefined) el.textContent = text;
    if (className) el.className = className;
    return el;
  };
  async function request(path, body) {
    const response = await fetch(ehrmanSectionPilot.url + path, {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', 'X-WP-Nonce': ehrmanSectionPilot.nonce},
      body: JSON.stringify(body)
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'The request failed.');
    return data;
  }
  function render(data, elapsed) {
    const column = node('section', undefined, 'pilot-result-column ' + data.mode);
    column.append(node('h2', data.mode === 'baseline' ? 'Summary baseline' : 'Sections: ' + data.mode + ' words'));
    column.append(node('p', data.posts.length + ' posts | ' + elapsed.toFixed(1) + ' seconds | ' + (data.costUsd * 100).toFixed(3) + ' cents estimated', 'pilot-metrics'));
    if (data.notice) column.append(node('p', data.notice));
    if (!data.posts.length) column.append(node('p', 'No matching posts.'));
    const list = node('ol');
    for (const post of data.posts) {
      const item = node('li');
      const link = node('a', post.title);
      const url = new URL(post.url);
      if (['https:', 'http:'].includes(url.protocol)) link.href = url.href;
      link.target = '_blank'; link.rel = 'noopener noreferrer';
      item.append(link, node('p', post.description));
      const summary = node('details');
      summary.append(node('summary', 'Search summary'), node('p', post.summary));
      item.append(summary);
      if (post.passage) {
        const details = node('details');
        details.append(node('summary', 'Matching passage'), node('blockquote', post.passage));
        item.append(details);
      }
      list.append(item);
    }
    column.append(list);
    results.append(column);
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const compare = event.submitter?.value === 'compare';
    const question = root.querySelector('#pilot-question').value.trim();
    const size = root.querySelector('input[name=size]:checked').value;
    const evidence = root.querySelector('#pilot-evidence').value === 'passages';
    if (!question) return;
    results.replaceChildren(); results.classList.toggle('comparing', compare);
    feedback.hidden = true; requestIds = [];
    root.querySelector('#pilot-feedback-status').textContent = '';
    form.querySelectorAll('button').forEach(button => button.disabled = true);
    try {
      for (const mode of compare ? ['baseline', size] : [size]) {
        status.textContent = mode === 'baseline' ? 'Searching the 500-post baseline...' : 'Searching ' + size + '-word sections...';
        const start = performance.now();
        const data = await request('search', {question, mode, evidence: mode !== 'baseline' && evidence});
        render(data, (performance.now() - start) / 1000);
        requestIds.push(data.requestId);
      }
      status.textContent = 'Complete. Both search and AI review ran without a WordPress result-cache hit.';
      feedback.hidden = !compare;
    } catch (error) { status.textContent = error.message; }
    finally { form.querySelectorAll('button').forEach(button => button.disabled = false); }
  });
  feedback.addEventListener('click', async event => {
    const winner = event.target.dataset.winner;
    if (!winner) return;
    const message = root.querySelector('#pilot-feedback-status');
    try { await request('feedback', {winner, requestIds}); message.textContent = 'Feedback saved.'; }
    catch (error) { message.textContent = error.message; }
  });
})();
