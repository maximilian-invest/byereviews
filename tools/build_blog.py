#!/usr/bin/env python3
"""Build the static blog (public/blog/**) from content/blog-posts.json.

Run from the repo root:  python3 tools/build_blog.py
Then commit the generated files in public/blog/ and public/sitemap.xml.
"""
import html
import json
import re
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PUBLIC = ROOT / 'public'
SITE = 'https://byereviews.com'
CTA_HREF = '/#order'
ANALYTICS = '<script src="https://analytics.ahrefs.com/analytics.js" data-key="iOJ8JnCq/r29Kqn1CTPNgQ" async></script>'

data = json.loads((ROOT / 'content' / 'blog-posts.json').read_text(encoding='utf-8'))
posts = data['posts']
by_n = {p['n']: p for p in posts}
updated_iso = datetime.strptime(data['updated'], '%b %d, %Y').date().isoformat()
author = data['author']
# Placeholder bios ("[AUTHOR] …") are not shown until they are filled in
author_bio = '' if author['bio'].lstrip().startswith('[') else author['bio']
cta = data['cta']

e = lambda s: html.escape(str(s), quote=True)
ARROW = lambda c='#fff', s=16: f'<svg width="{s}" height="{s}" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8h9M8.5 4l4 4-4 4" stroke="{c}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'


def url(p):
    return f'/blog/{p["slug"]}/'


def cover(p):
    return '/' + p['img'].replace('.png', '.webp')


def inline(text):
    """Escape text and turn [[n|label]] into internal links."""
    out, last = [], 0
    for m in re.finditer(r'\[\[(\d+)\|([^\]]+)\]\]', text):
        out.append(e(text[last:m.start()]))
        target = by_n.get(int(m.group(1)))
        label = e(m.group(2))
        out.append(f'<a class="in" href="{url(target)}">{label}</a>' if target else label)
        last = m.end()
    out.append(e(text[last:]))
    return ''.join(out)


def plain(text):
    return re.sub(r'\[\[\d+\|([^\]]+)\]\]', r'\1', text)


def head(title, desc, canonical, og_image, schema):
    return f'''<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{e(title)}</title>
<meta name="description" content="{e(desc)}">
<link rel="canonical" href="{SITE}{canonical}">
<meta name="theme-color" content="#EFEFEF">
<link rel="icon" type="image/png" href="/assets/favicon.png">
<link rel="apple-touch-icon" href="/assets/favicon.png">
<meta property="og:type" content="{'article' if canonical != '/blog/' else 'website'}">
<meta property="og:site_name" content="byereviews">
<meta property="og:title" content="{e(title)}">
<meta property="og:description" content="{e(desc)}">
<meta property="og:url" content="{SITE}{canonical}">
<meta property="og:image" content="{SITE}{og_image}">
<meta name="twitter:card" content="summary_large_image">
<link rel="preload" href="/assets/fonts/geist-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/blog.css">
{ANALYTICS}
<script type="application/ld+json">{json.dumps(schema, ensure_ascii=False)}</script>
</head>
<body>
<div class="wrap"><div class="canvas">
{header()}'''


def header():
    return f'''<header class="hdr">
  <nav class="nav">
    <a class="logo" href="/"><img src="/assets/byereviews-logo.png" alt="byereviews" width="107" height="22"></a>
    <a class="lnk" href="/#how">How it works</a>
    <a class="lnk" href="/#pricing">Pricing</a>
    <a class="lnk" href="/#cases">Cases</a>
    <a class="lnk" href="/#faq">FAQ</a>
    <a class="lnk strong" href="/blog/">Guides</a>
    <a class="lnk strong" href="/#login">Log in</a>
  </nav>
  <div style="display:flex;align-items:center;gap:8px">
    <button class="burger" type="button" aria-label="Menu" aria-controls="menu" aria-expanded="false" onclick="brMenu(true)"><svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M3 6h14M3 14h14" stroke="#151515" stroke-width="1.8" stroke-linecap="round"/></svg></button>
    <a class="hdr-cta" href="{CTA_HREF}"><span class="ico">{ARROW()}</span>Remove a review</a>
  </div>
</header>
<div class="menu" id="menu" role="dialog" aria-label="Menu">
  <div class="menu-top">
    <a href="/" style="display:flex;align-items:center;padding:8px 0"><img src="/assets/byereviews-logo.png" alt="byereviews"></a>
    <button class="menu-close" type="button" aria-label="Close menu" onclick="brMenu(false)"><svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M5 5l10 10M15 5L5 15" stroke="#FFFFFF" stroke-width="1.8" stroke-linecap="round"/></svg></button>
  </div>
  {''.join(f'<a class="item" href="{h}">{l}<span>→</span></a>' for l, h in [('How it works', '/#how'), ('Pricing', '/#pricing'), ('Cases', '/#cases'), ('FAQ', '/#faq'), ('Guides', '/blog/'), ('Log in', '/#login')])}
  <a class="menu-cta" href="{CTA_HREF}">Remove a review<span class="ico">{ARROW()}</span></a>
  <small>No upfront payment · pay only for removed reviews</small>
</div>'''


def footer():
    return f'''<section class="band">
  <h2><span>Pay only when it's gone.</span> Start with a free audit.</h2>
  <a href="{CTA_HREF}"><span class="ico">{ARROW(s=18)}</span>Get my free review audit</a>
</section>
<footer>
  <div class="l">
    <img src="/assets/byereviews-logo.png" alt="byereviews" width="116" height="24">
    <span>Removal of individual false, unfair or policy-violating Google reviews. Not affiliated with Google.</span>
    <span>Questions? <a href="mailto:info@byereviews.com" style="text-decoration:underline">info@byereviews.com</a></span>
  </div>
  <div class="r"><a href="mailto:info@byereviews.com">info@byereviews.com</a><a href="/imprint.html">Imprint</a><a href="/terms.html">Terms</a><a href="/withdrawal.html">Right of Withdrawal</a><a href="/privacy.html">Privacy</a></div>
</footer>
<a class="mbar" href="{CTA_HREF}"><span><span>Free review audit</span><small>Answer within 24 hours</small></span><span class="ico w">{ARROW('#151515')}</span></a>
</div></div>
<script>
function brMenu(o){{var m=document.getElementById('menu');m.classList.toggle('open',o);document.body.style.overflow=o?'hidden':'';document.querySelector('.burger').setAttribute('aria-expanded',o)}}
document.addEventListener('keydown',function(ev){{if(ev.key==='Escape')brMenu(false)}});
</script>
</body>
</html>
'''


ORG = {'@type': 'Organization', '@id': SITE + '/#org', 'name': 'byereviews', 'url': SITE + '/',
       'logo': SITE + '/assets/byereviews-logo.png', 'email': 'info@byereviews.com'}
PERSON = {'@type': 'Person', 'name': author['name'], 'jobTitle': author['role'], 'worksFor': {'@id': SITE + '/#org'}}


def card(p):
    return f'''<a class="card" href="{url(p)}" data-cluster="{e(p['cluster'])}">
  <div class="img" style="background-image:url('{cover(p)}')" role="img" aria-label="{e(p['title'])}"></div>
  <div class="body">
    <div class="row"><span class="chip">{e(p['cluster'])}</span><span class="mono">{p['read']} min</span></div>
    <span class="t">{e(p['title'])}</span>
    <span class="more">Read →</span>
  </div>
</a>'''


def build_index():
    first, rest = posts[0], posts[1:]
    clusters = ['All'] + [p['cluster'] for p in rest]
    schema = {'@context': 'https://schema.org', '@graph': [
        ORG,
        {'@type': 'Blog', 'name': 'byereviews guides', 'url': SITE + '/blog/', 'publisher': {'@id': SITE + '/#org'},
         'blogPost': [{'@type': 'BlogPosting', 'headline': p['title'], 'url': SITE + url(p)} for p in posts]},
        {'@type': 'BreadcrumbList', 'itemListElement': [
            {'@type': 'ListItem', 'position': 1, 'name': 'Home', 'item': SITE + '/'},
            {'@type': 'ListItem', 'position': 2, 'name': 'Guides', 'item': SITE + '/blog/'}]},
    ]}
    body = f'''<section class="intro">
  <span class="meta">// guides · updated {e(data['updated'])}</span>
  <h1><span class="grey">Bad Google review?</span> Here's every honest way to get it removed.</h1>
  <p>Plain-English guides on Google's review policies, appeals, fake reviews and legal removal – written for business owners in the US, UK, Canada and Australia.</p>
</section>
<section class="list">
  <a class="featured" href="{url(first)}">
    <div class="img" style="background-image:url('{cover(first)}')" role="img" aria-label="{e(first['title'])}"></div>
    <div class="txt">
      <span class="chip-w">{e(first['cluster'])} · start here</span>
      <span class="t">{e(first['title'])}</span>
      <span class="l">{e(first['lead'])}</span>
      <span class="r">Read the guide · {first['read']} min {ARROW()}</span>
    </div>
  </a>
  <div class="filters" role="group" aria-label="Filter guides">
    {''.join(f'<button type="button" class="{"on" if c == "All" else ""}" data-f="{e(c)}">{e(c)}</button>' for c in clusters)}
  </div>
  <div class="grid">
    {''.join(card(p) for p in rest)}
  </div>
</section>
<script>
document.querySelectorAll('.filters button').forEach(function(b){{b.addEventListener('click',function(){{
  var f=b.dataset.f;document.querySelectorAll('.filters button').forEach(function(x){{x.classList.toggle('on',x===b)}});
  document.querySelectorAll('.grid .card').forEach(function(c){{c.hidden=!(f==='All'||c.dataset.cluster===f)}});
}})}});
</script>
'''
    title = 'Guides: how to remove Google reviews – byereviews'
    desc = 'Plain-English guides on removing Google reviews: policy violations, fake reviews, appeals, extortion, review bombing and legal removal.'
    out = PUBLIC / 'blog' / 'index.html'
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(head(title, desc, '/blog/', cover(first), schema) + body + footer(), encoding='utf-8')


def block(b):
    if 'p' in b:
        return f'<p>{inline(b["p"])}</p>'
    if 'ul' in b:
        return '<ul class="ul">' + ''.join(f'<li><span>{inline(t)}</span></li>' for t in b['ul']) + '</ul>'
    if 'ol' in b:
        return '<ol class="ol">' + ''.join(f'<li><span>{inline(t)}</span></li>' for t in b['ol']) + '</ol>'
    if 'table' in b:
        t = b['table']
        rows = ''.join('<tr>' + ''.join(f'<td>{inline(c)}</td>' for c in r) + '</tr>' for r in t['rows'])
        return '<div class="tbl"><table><thead><tr>' + ''.join(f'<th>{e(h)}</th>' for h in t['head']) + f'</tr></thead><tbody>{rows}</tbody></table></div>'
    # 'shot' = [SCREENSHOT] placeholder: not published until a real screenshot exists
    return ''


def build_post(p):
    related = [by_n[n] for n in dict.fromkeys([1, 2, *data['links'].get(str(p['n']), [])]) if n != p['n']][:3]
    secs = []
    for i, s in enumerate(p['sections']):
        inner = ''.join(block(b) for b in s['blocks'])
        if i == 1:
            inner += f'<a class="inline-cta" href="{CTA_HREF}"><span><b>Want us to check your reviews?</b><small>Free audit · answer within 24 hours</small></span><span class="btn-dark">Free review audit →</span></a>'
        secs.append(f'<section class="sec" id="sec-{i}"><h2>{e(s["h"])}</h2>{inner}</section>')
    faq = ''.join(f'<details{" open" if i == 0 else ""}><summary>{e(f["q"])}</summary><p>{inline(f["a"])}</p></details>' for i, f in enumerate(p['faq']))
    toc = ''.join(f'<a href="#sec-{i}">{e(s["h"])}</a>' for i, s in enumerate(p['sections']))
    rel = ''.join(f'''<a class="rel" href="{url(r)}"><div class="img" style="background-image:url('{cover(r)}')"></div><div><small>{e(r['cluster'])} · {r['read']} min</small><b>{e(r['title'])}</b></div></a>''' for r in related)
    words = sum(len(plain(json.dumps(s)).split()) for s in p['sections'])

    schema = {'@context': 'https://schema.org', '@graph': [
        ORG,
        {'@type': 'Article', 'headline': p['title'], 'description': p['lead'], 'image': SITE + cover(p),
         'author': PERSON, 'publisher': {'@id': SITE + '/#org'}, 'datePublished': updated_iso, 'dateModified': updated_iso,
         'mainEntityOfPage': SITE + url(p), 'keywords': p['kw'], 'articleSection': p['cluster'], 'wordCount': words,
         'inLanguage': 'en'},
        {'@type': 'BreadcrumbList', 'itemListElement': [
            {'@type': 'ListItem', 'position': 1, 'name': 'Home', 'item': SITE + '/'},
            {'@type': 'ListItem', 'position': 2, 'name': 'Guides', 'item': SITE + '/blog/'},
            {'@type': 'ListItem', 'position': 3, 'name': p['title'], 'item': SITE + url(p)}]},
        {'@type': 'FAQPage', 'mainEntity': [{'@type': 'Question', 'name': f['q'],
                                             'acceptedAnswer': {'@type': 'Answer', 'text': plain(f['a'])}} for f in p['faq']]},
    ]}

    body = f'''<article>
  <nav class="crumbs" aria-label="Breadcrumb"><a href="/blog/">Guides</a><span>/</span><span>{e(p['cluster'])}</span></nav>
  <h1>{e(p['title'])}</h1>
  <div class="byline">
    <span class="av">{e(author['name'][0])}</span>
    <span><strong>{e(author['name'])}</strong> · {e(author['role'])}</span>
    <span>·</span><span>Last updated <time datetime="{updated_iso}">{e(data['updated'])}</time></span><span>·</span><span>{p['read']} min read</span>
  </div>
  <div class="cover" style="background-image:url('{cover(p)}')" role="img" aria-label="{e(p['title'])}"></div>
  <div class="cols">
    <div class="main">
      <div class="answer"><span class="mono">SHORT ANSWER</span><p>{inline(p['lead'])}</p></div>
      {''.join(secs)}
      <section class="faq"><h2>FAQ</h2>{faq}</section>
      <div class="author"><span class="av">{e(author['name'][0])}</span><div><b>{e(author['name'])}</b><span>{e(author['role'])}</span>{f'<em>{e(author_bio)}</em>' if author_bio else ''}</div></div>
      <div class="cta-block">
        <span class="t">{e(cta['title'])}</span>
        <p>{e(cta['text'])}</p>
        <a class="pill" href="{CTA_HREF}"><span class="ico">{ARROW(s=18)}</span>{e(cta['button'])}</a>
      </div>
    </div>
    <aside>
      <nav class="toc" aria-label="On this page"><span class="mono">ON THIS PAGE</span>{toc}</nav>
      <div class="side-cta"><b>Which of your reviews can go?</b><span>Free audit of your profile. Pay only for reviews we actually remove.</span><a href="{CTA_HREF}">Get my free audit →</a></div>
    </aside>
  </div>
  <section class="related"><h2><span class="grey">Keep reading.</span> Related guides</h2><div class="grid">{rel}</div></section>
</article>
'''
    out = PUBLIC / 'blog' / p['slug'] / 'index.html'
    out.parent.mkdir(parents=True, exist_ok=True)
    title = f'{p["title"]} – byereviews'
    out.write_text(head(title, p['lead'][:300], url(p), cover(p), schema) + body + footer(), encoding='utf-8')


def build_sitemap():
    urls = [('/', 'weekly', '1.0'), ('/blog/', 'weekly', '0.8')] + [(url(p), 'monthly', '0.9' if p['n'] <= 2 else '0.7') for p in posts] \
        + [('/terms.html', 'yearly', '0.2'), ('/withdrawal.html', 'yearly', '0.2')]
    items = ''.join(f'  <url><loc>{SITE}{u}</loc><lastmod>{updated_iso}</lastmod><changefreq>{c}</changefreq><priority>{pr}</priority></url>\n' for u, c, pr in urls)
    (PUBLIC / 'sitemap.xml').write_text(f'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n{items}</urlset>\n', encoding='utf-8')


build_index()
for p in posts:
    build_post(p)
build_sitemap()
print(f'Built blog index + {len(posts)} posts + sitemap')
