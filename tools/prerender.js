// Snapshot the rendered homepage into public/index.html (<div id="prerender">) so crawlers that don't run
// JavaScript (AI search bots, link previews) see real content and links. Visitors with JS never see it.
//
// Usage (repo root):  php -S 127.0.0.1:8123 -t public &   then   node tools/prerender.js [http://127.0.0.1:8123]
const path = require('path');
const fs = require('fs');
let chromium;
try { ({ chromium } = require('playwright')); } catch (e) { ({ chromium } = require('/opt/node22/lib/node_modules/playwright')); }

const base = process.argv[2] || 'http://127.0.0.1:8123';
const file = path.join(__dirname, '..', 'public', 'index.html');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1360, height: 900 } });
  await page.route('**/analytics.ahrefs.com/**', r => r.abort());
  await page.goto(base + '/', { waitUntil: 'networkidle' });
  await page.waitForSelector('#dc-root h1');
  const html = await page.evaluate(async () => {
    // collect every FAQ answer (the accordion shows one at a time)
    const faqs = [];
    const section = document.getElementById('faq');
    if (section) {
      const buttons = [...section.querySelectorAll('button')];
      for (const b of buttons) {
        b.click(); await new Promise(r => setTimeout(r, 30));
        const p = b.parentElement.querySelector('p');
        faqs.push([b.childNodes[0].textContent.trim(), p ? p.textContent.trim() : '']);
      }
    }
    const root = document.getElementById('dc-root').cloneNode(true);
    root.querySelectorAll('script, canvas, video, button[aria-label]').forEach(n => n.remove());
    root.querySelectorAll('[ref]').forEach(n => n.removeAttribute('ref'));
    const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
    const faqHtml = faqs.length ? '<section><h2>Frequently asked questions</h2><dl>' + faqs.map(([q, a]) => `<dt>${esc(q)}</dt><dd>${esc(a)}</dd>`).join('') + '</dl></section>' : '';
    const nav = '<nav><a href="/">Home</a> · <a href="/order/">Remove a review</a> · <a href="/blog/">Guides</a> · <a href="/login/">Log in</a></nav>';
    return nav + root.innerHTML + faqHtml;
  });
  await browser.close();
  let src = fs.readFileSync(file, 'utf8');
  const block = '<div id="prerender">' + html + '</div><!--/prerender-->';
  if (/<div id="prerender">[\s\S]*?<\/div><!--\/prerender-->/.test(src)) src = src.replace(/<div id="prerender">[\s\S]*?<\/div><!--\/prerender-->/, () => block);
  else src = src.replace('<!--PRERENDER-->', () => block);
  fs.writeFileSync(file, src);
  console.log('prerendered', Math.round(block.length / 1024) + ' KB into public/index.html');
})();
