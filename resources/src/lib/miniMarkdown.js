import DOMPurify from 'dompurify';

/**
 * Small Markdown renderer for AI answers: headings, bold/italic, inline and
 * fenced code, bullet/numbered lists, tables, links and paragraphs — the
 * subset the assistant is told to use. Input is HTML-escaped first and the
 * output is run through DOMPurify, so model text can never inject markup.
 */
const escapeHtml = s => s
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;');

function inline(text) {
  return text
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>')
    .replace(/(^|[\s(])_([^_]+)_(?=[\s).,!?]|$)/g, '$1<em>$2</em>')
    .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
}

const isTableRow = line => /^\s*\|.*\|\s*$/.test(line);
const isTableDivider = line => /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(line);
const cells = line => line.trim().replace(/^\||\|$/g, '').split('|').map(c => c.trim());

export function renderMarkdown(source) {
  const lines = escapeHtml(String(source ?? '')).replace(/\r\n?/g, '\n').split('\n');
  const out = [];
  let i = 0;

  while (i < lines.length) {
    const line = lines[i];

    // Fenced code block
    if (/^\s*```/.test(line)) {
      const code = [];
      i++;
      while (i < lines.length && !/^\s*```/.test(lines[i])) code.push(lines[i++]);
      i++;
      out.push(`<pre><code>${code.join('\n')}</code></pre>`);
      continue;
    }

    // Table: header row + divider row
    if (isTableRow(line) && i + 1 < lines.length && isTableDivider(lines[i + 1])) {
      const head = cells(line);
      i += 2;
      const rows = [];
      while (i < lines.length && isTableRow(lines[i])) rows.push(cells(lines[i++]));
      out.push(
        '<div class="md-table"><table><thead><tr>'
        + head.map(h => `<th>${inline(h)}</th>`).join('')
        + '</tr></thead><tbody>'
        + rows.map(r => '<tr>' + r.map(c => `<td>${inline(c)}</td>`).join('') + '</tr>').join('')
        + '</tbody></table></div>'
      );
      continue;
    }

    // Heading
    const h = line.match(/^\s*(#{1,4})\s+(.*)$/);
    if (h) {
      const level = Math.min(h[1].length + 2, 6); // keep them small inside a chat bubble
      out.push(`<h${level}>${inline(h[2])}</h${level}>`);
      i++;
      continue;
    }

    // Lists
    const ul = /^\s*[-*+]\s+(.*)$/;
    const ol = /^\s*\d+[.)]\s+(.*)$/;
    if (ul.test(line) || ol.test(line)) {
      const ordered = ol.test(line) && !ul.test(line);
      const re = ordered ? ol : ul;
      const items = [];
      while (i < lines.length && re.test(lines[i])) items.push(lines[i++].match(re)[1]);
      const tag = ordered ? 'ol' : 'ul';
      out.push(`<${tag}>${items.map(it => `<li>${inline(it)}</li>`).join('')}</${tag}>`);
      continue;
    }

    // Blank line
    if (!line.trim()) {
      i++;
      continue;
    }

    // Paragraph: consecutive plain lines
    const para = [];
    while (
      i < lines.length && lines[i].trim()
      && !/^\s*```/.test(lines[i]) && !/^\s*#{1,4}\s/.test(lines[i])
      && !ul.test(lines[i]) && !ol.test(lines[i])
      && !(isTableRow(lines[i]) && i + 1 < lines.length && isTableDivider(lines[i + 1]))
    ) {
      para.push(inline(lines[i++]));
    }
    out.push(`<p>${para.join('<br>')}</p>`);
  }

  return DOMPurify.sanitize(out.join(''), { ADD_ATTR: ['target', 'rel'] });
}
