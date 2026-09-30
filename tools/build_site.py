#!/usr/bin/env python3
"""Build the live site from the design handoff.

  design/site.dc.html  (prototype from the design tool, untouched)
  + tools/site_overrides.js (real backend, routing, fixes)
  -> public/index.html, public/order/, public/login/, public/dashboard/

Run from the repo root:  python3 tools/build_site.py   (then optionally: node tools/prerender.js)
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / 'design' / 'site.dc.html'
PUBLIC = ROOT / 'public'

s = SRC.read_text(encoding='utf-8')


def rep(old, new, count=1):
    global s
    n = s.count(old)
    assert n == count, f'expected {count}x, found {n}x: {old[:90]!r}'
    s = s.replace(old, new)


HEAD = '''<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>byereviews – Remove fake &amp; unfair Google reviews</title>
<meta name="description" content="byereviews removes fake, defamatory and policy-violating Google reviews. No cure, no pay: you only pay for reviews that are actually removed.">
<meta name="theme-color" content="#EFEFEF">
<link rel="canonical" href="https://byereviews.com__CANONICAL__">
__ROBOTS__<link rel="icon" type="image/png" href="/assets/favicon.png">
<link rel="apple-touch-icon" href="/assets/favicon.png">
<meta property="og:type" content="website">
<meta property="og:site_name" content="byereviews">
<meta property="og:title" content="byereviews – Remove fake &amp; unfair Google reviews">
<meta property="og:description" content="No cure, no pay: you only pay for reviews that are actually removed.">
<meta property="og:url" content="https://byereviews.com/">
<meta property="og:image" content="https://byereviews.com/assets/media/hero-desktop-poster.webp">
<link rel="preload" href="/assets/fonts/geist-latin.woff2" as="font" type="font/woff2" crossorigin>
<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","@id":"https://byereviews.com/#org","name":"byereviews","url":"https://byereviews.com/","logo":"https://byereviews.com/assets/byereviews-logo.png","email":"info@byereviews.com"},{"@type":"WebSite","name":"byereviews","url":"https://byereviews.com/","publisher":{"@id":"https://byereviews.com/#org"}},{"@type":"Service","name":"Google review removal","provider":{"@id":"https://byereviews.com/#org"},"areaServed":["US","GB","CA","AU","EU"],"offers":[{"@type":"Offer","name":"Review posted within the last 4 weeks","price":"90","priceCurrency":"USD"},{"@type":"Offer","name":"Review older than 4 weeks","price":"125","priceCurrency":"USD"}]}]}</script>
<style>html.js #prerender{display:none}html:not(.js) x-dc{display:none!important}</style>
<script>
document.documentElement.classList.add('js');
window.__resources = {
  "https://unpkg.com/react@18.3.1/umd/react.production.min.js": "/assets/vendor/react.production.min.js",
  "https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js": "/assets/vendor/react-dom.production.min.js"
};
</script>
<script src="/support.js"></script>
<script src="https://analytics.ahrefs.com/analytics.js" data-key="iOJ8JnCq/r29Kqn1CTPNgQ" async></script>
</head>
<body>
<!--PRERENDER-->'''

rep('<html>\n<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width, initial-scale=1">\n<script src="./support.js"></script>\n</head>\n<body>', HEAD)
rep('<link rel="preconnect" href="https://fonts.googleapis.com">\n<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">',
    '<link href="/assets/fonts/fonts.css" rel="stylesheet">')

# self-hosted media
rep('assets/video/hero-desktop.mp4', '/assets/media/hero-desktop.mp4')
rep('assets/video/hero-mobile.mp4', '/assets/media/hero-mobile.mp4')
rep('assets/video/hero-desktop-poster.webp', '/assets/media/hero-desktop-poster.webp')
rep('assets/video/hero-mobile-poster.webp', '/assets/media/hero-mobile-poster.webp')
rep("assets/img/step-1-pick.png", "/assets/media/image-1.webp")
rep("assets/img/step-2-check.png", "/assets/media/image-2.webp")
rep("assets/img/step-3-pay.png", "/assets/media/image-3.webp")
s = s.replace('src="assets/byereviews-logo.png"', 'src="/assets/byereviews-logo.png"')

# real links instead of href="#"
rep('<a href="#" onClick="{{ goHome }}" style="display:flex;align-items:center;padding:8px 14px 8px 8px">', '<a href="/" onClick="{{ goHome }}" style="display:flex;align-items:center;padding:8px 14px 8px 8px">')
for sec in ('how', 'pricing', 'cases', 'faq'):
    rep(f'<a href="#{sec}" onClick="{{{{ goHome }}}}"', f'<a href="/#{sec}" onClick="{{{{ goHome }}}}"')
rep('<a href="byereviews Blog.dc.html"', '<a href="/blog/"')
rep('<a href="#" onClick="{{ goPortal }}"', '<a href="/login/" onClick="{{ goPortal }}"')
rep('<a href="#" onClick="{{ closeMenuHome }}"', '<a href="/" onClick="{{ closeMenuHome }}"')
rep("['How it works', '#how'], ['Pricing', '#pricing'], ['Cases', '#cases'], ['FAQ', '#faq'], ['Guides', 'byereviews Blog.dc.html'], ['Log in', '#']",
    "['How it works', '/#how'], ['Pricing', '/#pricing'], ['Cases', '/#cases'], ['FAQ', '/#faq'], ['Guides', '/blog/'], ['Log in', '/login/']")

rep('<a href="#" onClick="{{ acc.goDash }}"', '<a href="/dashboard/" onClick="{{ acc.goDash }}"')
rep('<a href="#" onClick="{{ acc.goForgot }}"', '<a href="/login/" onClick="{{ acc.goForgot }}"')
rep('<a href="#" onClick="{{ acc.goLogin }}"', '<a href="/login/" onClick="{{ acc.goLogin }}"')

# account area: prototype-only buttons out (the real reset link comes by email)
s, n = re.subn(r'<button onClick="\{\{ acc\.(openResetLink|expireLink) \}\}"[^>]*>[^<]*</button>', '', s)
assert n == 2, n

# every "Remove a review" button becomes a real link to /order/
s, n = re.subn(r'<button onClick="\{\{ goOrder \}\}"(.*?)>(.*?)</button>', r'<a href="/order/" onClick="{{ goOrder }}"\1>\2</a>', s, flags=re.S)
assert n >= 5, n

# footer + legal links
rep('<a href="#">Imprint</a><a href="#">Terms</a><a href="#">Right of Withdrawal</a><a href="#">Privacy</a>',
    '<a href="mailto:info@byereviews.com">info@byereviews.com</a><a href="/imprint.html">Contact</a><a href="/terms.html">Terms</a><a href="/withdrawal.html">Right of Withdrawal</a><a href="/privacy.html">Privacy</a><a href="/blog/">Guides</a>')
rep('Not affiliated with Google.</span>', 'Not affiliated with Google.</span>\n    <span style="font-size:14px;color:#6B6B6B">Questions? <a href="mailto:info@byereviews.com" style="text-decoration:underline">info@byereviews.com</a></span>')
rep('<a href="#" style="text-decoration:underline">Terms</a>', '<a href="/terms.html" target="_blank" rel="noopener" style="text-decoration:underline">Terms</a>')
rep('<a href="#" style="text-decoration:underline">Right of Withdrawal</a>', '<a href="/withdrawal.html" target="_blank" rel="noopener" style="text-decoration:underline">Right of Withdrawal</a>')
s = s.replace('&lt;hello@byereviews.com&gt;', '&lt;info@byereviews.com&gt;')

# reviews older than 4 weeks: show the removal probability
rep('<span style="font-size:18px">Older than 4 weeks</span>',
    '<span style="font-size:18px">Older than 4 weeks</span>\n      <span style="align-self:flex-start;font-size:13px;font-weight:500;background:#EFEFEF;border-radius:10px;padding:6px 10px">~70% removal probability</span>')
rep("tag: noText ? '' : on ? '✓ ' + this.fmt(p.days <= 28 ? 90 : 125) : this.fmt(p.days <= 28 ? 90 : 125),",
    "tag: noText ? '' : (on ? '✓ ' : '') + this.fmt(p.days <= 28 ? 90 : 125) + (p.days > 28 ? ' · ~70% removal chance' : ''),")
rep('Older<span style="font-weight:500">{{ priceOlder }}</span>',
    'Older<span style="font-weight:500">{{ priceOlder }}</span><span style="opacity:.7">· ~70% chance</span>')
rep('<span>Older (&gt; 4 weeks) × {{ nOlder }}</span><span>{{ subOlder }}</span>', '<span>Older (&gt; 4 weeks) × {{ nOlder }}<span style="display:block;color:#8A8A8A;font-size:13px;margin-top:2px">~70% removal chance</span></span><span style="white-space:nowrap">{{ subOlder }}</span>')

# no unverified rating claims
s, n = re.subn(r'\s*<span[^>]*>Trustpilot ★ 4\.9</span>', '', s)
assert n == 1, n

# success page: no demo email preview
s, n = re.subn(r'<sc-if value="\{\{ isNewAccount \}\}" hint-placeholder-val="\{\{ false \}\}"><button onClick="\{\{ openMail \}\}".*?</button></sc-if>', '', s, flags=re.S)
assert n == 1, n

# login: real error text, busy label, password reset instead of the demo account
rep('<span style="font-size:14px;color:#6B6B6B">Email or password doesn\'t match.</span>', '<span style="font-size:14px;color:#6B6B6B">{{ loginErrorText }}</span>')
rep('font-size:16px;font-weight:500">Log in</button>', 'font-size:16px;font-weight:500">{{ loginBtn }}</button>')
rep('<button onClick="{{ fillDemo }}" style="border:0;background:transparent;cursor:pointer;font-size:14px;color:#6B6B6B;text-decoration:underline;text-underline-offset:3px">Use demo account</button>',
    '<button onClick="{{ doReset }}" style="border:0;background:transparent;cursor:pointer;font-size:14px;color:#6B6B6B;text-decoration:underline;text-underline-offset:3px">{{ resetLabel }}</button>')

# step 1: the customer must pick one of the real Google results (no auto-select)
m = re.search(r'(<sc-if value="\{\{ bizFound \}\}" hint-placeholder-val="\{\{ false \}\}">.*?</sc-if>)', s, flags=re.S)
rep(m.group(1), '''<sc-if value="{{ bizChoose }}" hint-placeholder-val="{{ false }}">
          <div style="display:flex;flex-direction:column;gap:8px">
            <span style="font-size:14px;color:#6B6B6B;padding:2px 4px">{{ chooseTitle }}</span>
            <sc-for list="{{ choosePlaces }}" as="cp" hint-placeholder-count="3">
              <button onClick="{{ cp.go }}" style="width:100%;text-align:left;border:0;cursor:pointer;background:#F4F4F4;border-radius:16px;padding:14px 16px;display:flex;align-items:center;gap:14px;color:#151515">
                <span style="width:44px;height:44px;border-radius:12px;background:#151515;color:#fff;flex:none;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:500">{{ cp.initial }}</span>
                <span style="flex:1;min-width:0;display:flex;flex-direction:column;gap:2px"><span style="font-size:16px;font-weight:500">{{ cp.name }}</span><span style="font-size:13px;color:#6B6B6B">{{ cp.meta }}</span></span>
                <span style="flex:none;font-size:13px;font-weight:500;background:#FFFFFF;border-radius:10px;padding:8px 12px">Select</span>
              </button>
            </sc-for>
          </div>
        </sc-if>
        ''' + m.group(1) + '''
        <sc-if value="{{ hasAlts }}" hint-placeholder-val="{{ false }}">
          <button onClick="{{ showAllMatches }}" style="align-self:flex-start;border:0;background:transparent;cursor:pointer;padding:2px 4px;font-size:14px;color:#6B6B6B;text-decoration:underline;text-underline-offset:3px">{{ altLabel }}</button>
        </sc-if>''')
rep('<span style="font-size:16px;font-weight:500">No profile found</span>', '<span style="font-size:16px;font-weight:500">{{ bizNfTitle }}</span>')

# step 2: loading / partial / failed states for the review list
rep('        <sc-for list="{{ profileReviews }}" as="p" hint-placeholder-count="5">', '''        <sc-if value="{{ reviewsLoading }}" hint-placeholder-val="{{ false }}">
          <div style="background:#FFFFFF;border-radius:20px;padding:18px;display:flex;align-items:center;gap:12px"><span style="width:38px;height:38px;border-radius:50%;background:#E4E4E4;flex:none"></span><div style="flex:1;display:flex;flex-direction:column;gap:8px"><span style="height:10px;width:40%;background:#E4E4E4;border-radius:5px;display:block"></span><span style="height:10px;width:80%;background:#E4E4E4;border-radius:5px;display:block"></span></div><span style="font-family:'Geist Mono',monospace;font-size:12px;color:#8A8A8A">loading reviews…</span></div>
        </sc-if>
        <sc-if value="{{ reviewsFailed }}" hint-placeholder-val="{{ false }}"><div style="background:#FFFFFF;border-radius:20px;padding:18px;font-size:15px;color:#6B6B6B">We couldn't load the reviews right now – add them by link below.</div></sc-if>
        <sc-for list="{{ profileReviews }}" as="p" hint-placeholder-count="5">''')
rep('<div style="background:#FFFFFF;border-radius:20px;padding:24px;font-size:15px;color:#6B6B6B;text-align:center">No reviews match.</div></sc-if>',
    '<div style="background:#FFFFFF;border-radius:20px;padding:24px;font-size:15px;color:#6B6B6B;text-align:center">No reviews match.</div></sc-if>\n        <sc-if value="{{ reviewsPartial }}" hint-placeholder-val="{{ false }}"><div style="padding:4px 6px;font-size:13px;color:#6B6B6B">Showing the reviews Google shares publicly. Missing one? Add it by link below.</div></sc-if>')

# logged-in customer area: app header, Settings tab, reset-password card
exec((ROOT / 'tools' / 'site_account_patch.py').read_text(encoding='utf-8'))

# logic: keep the design's methods under new names, inject the production layer
for old, new in [('  componentDidMount() {', '  _designDidMount() {'), ('  renderVals() {', '  _designRenderVals() {'),
                 ('  doLogin() {', '  _designDoLogin() {'), ('  accountVals(sw, mob) {', '  _designAccountVals(sw, mob) {'), ('  findBiz() {', '  _designFindBiz() {'), ('  lookup(id, value) {', '  _designLookup(id, value) {')]:
    rep(old, new)
s = s.replace('this.profilePool', 'this.poolReviews()')
overrides = (ROOT / 'tools' / 'site_overrides.js').read_text(encoding='utf-8')
idx = s.rindex('\n}\n</script>')
s = s[:idx] + '\n' + overrides + s[idx:]

assert 'cdn.openart.ai' not in s and 'fonts.googleapis' not in s and 'href="#"' not in s.split('data-dc-script')[0], 'leftover external/dead link'

pages = {
    'index.html': ('/', ''),
    'order/index.html': ('/order/', ''),
    'login/index.html': ('/login/', '<meta name="robots" content="noindex, follow">\n'),
    'dashboard/index.html': ('/dashboard/', '<meta name="robots" content="noindex, nofollow">\n'),
}
for path, (canonical, robots) in pages.items():
    out = PUBLIC / path
    out.parent.mkdir(parents=True, exist_ok=True)
    html = s.replace('__CANONICAL__', canonical).replace('__ROBOTS__', robots)
    prev = out.read_text(encoding='utf-8') if out.exists() else ''
    # keep an existing prerender snapshot (made by tools/prerender.js)
    snap = re.search(r'<div id="prerender">.*?</div><!--/prerender-->', prev, flags=re.S)
    if snap and path == 'index.html':
        html = html.replace('<!--PRERENDER-->', snap.group(0), 1)
    out.write_text(html, encoding='utf-8')
print('built', ', '.join(pages))
