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
<meta property="og:image" content="https://byereviews.com/assets/media/og-image.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="628">
<meta name="twitter:card" content="summary_large_image">
<link rel="preload" href="/assets/fonts/geist-latin.woff2" as="font" type="font/woff2" crossorigin>
__PRELOAD__
<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","@id":"https://byereviews.com/#org","name":"byereviews","url":"https://byereviews.com/","logo":"https://byereviews.com/assets/byereviews-logo.png","email":"info@byereviews.com"},{"@type":"WebSite","name":"byereviews","url":"https://byereviews.com/","publisher":{"@id":"https://byereviews.com/#org"}},{"@type":"Service","name":"Google review removal","provider":{"@id":"https://byereviews.com/#org"},"areaServed":["US","GB","CA","AU","EU"],"offers":[{"@type":"Offer","name":"Removal of a review posted within the last 4 weeks","price":"90","priceCurrency":"USD","url":"https://byereviews.com/pricing/","description":"Charged only once the review is removed. No upfront payment, no retainer."},{"@type":"Offer","name":"Removal of a review older than 4 weeks","price":"125","priceCurrency":"USD","url":"https://byereviews.com/pricing/","description":"Charged only once the review is removed. No upfront payment, no retainer."}],"serviceType":"Google review removal","description":"Removal of fake, spam, off-topic and otherwise policy-violating Google reviews through the official Google reporting and legal-removal channels. Pay per removed review."}]}</script>
__FAQLD__<style>html.js #prerender{display:none}html:not(.js) x-dc{display:none!important}</style>
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
rep('assets/video/hero-desktop-poster.webp', '/assets/media/hero-desktop-poster-v2.webp')
rep('assets/video/hero-mobile-poster.webp', '/assets/media/hero-mobile-poster-v2.webp')
rep("assets/img/step-1-pick.png", "/assets/media/image-1.webp")
rep("assets/img/step-2-check.png", "/assets/media/image-2.webp")
rep("assets/img/step-3-pay.png", "/assets/media/image-3.webp")
s = s.replace('src="assets/byereviews-logo.png"', 'src="/assets/byereviews-logo-72.png"')  # 3x of the 24px max display height

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

# inert template: the browser must not fetch the template's videos/images/iframe before the runtime renders
rep('<x-dc>', '<x-dc><template>')
rep('</x-dc>', '</template></x-dc>')

assert 'cdn.openart.ai' not in s and 'fonts.googleapis' not in s and 'href="#"' not in s.split('data-dc-script')[0], 'leftover external/dead link'

import json as _json
# FAQ JSON-LD from the design's FAQ list (the same text the page shows)
_src = SRC.read_text(encoding='utf-8')
_block = _src[_src.index('      faqs: ['):_src.index('].map((f, i) => ({ ...f, open: s.faqOpen')]
_faqs = []
for _m in re.finditer(r"\{ q: '((?:[^'\\]|\\.)*)', a: (?:'((?:[^'\\]|\\.)*)'|`([^`]*)`) \}", _block):
    _a = (_m.group(2) or _m.group(3)).replace("\\'", "'").replace('${this.fmt(90)}', '$90').replace('${this.fmt(125)}', '$125')
    _faqs.append({'@type': 'Question', 'name': _m.group(1).replace("\\'", "'"), 'acceptedAnswer': {'@type': 'Answer', 'text': _a}})
assert len(_faqs) >= 5 and '${' not in _json.dumps(_faqs), _faqs
FAQ_LD = ('<script type="application/ld+json">'
          + _json.dumps({'@context': 'https://schema.org', '@type': 'FAQPage', 'mainEntity': _faqs}, ensure_ascii=False).replace('</', '<\\/')
          + '</script>\n')

HERO_PRELOAD = ('<link rel="preload" as="image" href="/assets/media/hero-mobile-poster-v2.webp" media="(max-width: 759px)" fetchpriority="high">\n'
                '<link rel="preload" as="image" href="/assets/media/hero-desktop-poster-v2.webp" media="(min-width: 760px)" fetchpriority="high">\n')
HOME_LIKE = ('index.html', 'pricing/index.html', 'how-it-works/index.html', 'faq/index.html', 'results/index.html')

# Intent landing pages for Google Ads ad groups: same page, hero + first section match the search.
# Only claims we can back: policy-violating reviews only, no success guarantee, prices as on /pricing/.
INTENTS = {
    'fake-review-removal/index.html': dict(
        title='Fake Google Review Removal – Pay Only Once It\'s Removed | byereviews',
        desc='Fake account, no real visit, a competitor posing as a customer? We report fake Google reviews through Google\'s official channels. $90 per removed review, nothing upfront.',
        grey='Fake Google review on your profile?', white='We get it removed.',
        sub='Fake accounts, people who never visited, competitors posing as customers: all of it breaks Google\'s policies. We document each case and report it through the official channels. You pay $90 only once the review is actually gone.',
        h2a='How to spot a fake review.', h2b='We check every one for free.',
        cards=[('No real visit', 'The reviewer was never your customer: no booking, no order, no record. Google removes reviews that aren\'t based on a real experience.'),
               ('Throwaway account', 'A new profile with one review, no photo and no history, often posted in a wave with others. A classic spam pattern.'),
               ('Competitor or ex-employee', 'Reviews from people with a conflict of interest are against Google\'s rules, even when they sound like a customer.')]),
    'remove-bad-google-reviews/index.html': dict(
        title='Remove Bad Google Reviews – No Removal, No Fee | byereviews',
        desc='Many 1-star reviews break Google\'s rules: off-topic rants, hate, conflicts of interest, reviews meant for someone else. Free check in 24h, pay only for reviews that are removed.',
        grey='Unfair 1-star review?', white='Get it removed.',
        sub='Not every bad review can go, but many break Google\'s rules: off-topic rants, insults, conflicts of interest, reviews meant for another business. We check yours for free and pursue removal of the ones that qualify. You pay only for what\'s actually removed.',
        h2a='Which bad reviews qualify.', h2b='And which don\'t.',
        cards=[('Off-topic or wrong business', 'Rants about politics, the city or the parking situation, or a review meant for someone else. Not about your business, so not allowed.'),
               ('Insults, hate, harassment', 'Personal attacks on you or your staff, profanity, discrimination or threats violate Google\'s content policy.'),
               ('Honest criticism stays', 'A fair review of a real visit is protected, even if it hurts. We tell you upfront, and you never pay for reviews we can\'t remove.')]),
    'review-removal-service/index.html': dict(
        title='Google Review Removal Service – $90 per Removed Review | byereviews',
        desc='Google review removal with the price on the page: $90 per removed review (posted within 4 weeks), $125 for older ones. No deposit, no retainer, no sales call.',
        grey='Google review removal service.', white='Pay only for what\'s gone.',
        sub='The price is on the page: $90 per removed review posted within the last 4 weeks, $125 for older ones, up to 15% off for several. No deposit, no retainer, no sales call. Order online in about 2 minutes.',
        h2a='Why businesses choose us.', h2b='No quote, no call, no risk.',
        cards=[('Price shown upfront', 'Most removal services only quote after a call. Ours is fixed per review and on this page, including the volume discount.'),
               ('No deposit, no retainer', 'Nothing is charged when you order. You get a payment link only for reviews that are actually removed.'),
               ('Free check within 24h', 'We assess every review against Google\'s policies and tell you honestly which ones qualify before any work starts.')]),
}


def esc(t):
    return t.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;').replace('"', '&quot;')


def intent_page(html, it):
    a = lambda t: esc(t).replace('&quot;', '"')
    html = html.replace('<title>byereviews – Remove fake &amp; unfair Google reviews</title>', f'<title>{esc(it["title"])}</title>', 1)
    html = html.replace('<meta property="og:title" content="byereviews – Remove fake &amp; unfair Google reviews">', f'<meta property="og:title" content="{esc(it["title"])}">', 1)
    html = re.sub(r'<meta name="description" content="[^"]*">', lambda m: f'<meta name="description" content="{esc(it["desc"])}">', html, count=1)
    html = re.sub(r'<meta property="og:description" content="[^"]*">', lambda m: f'<meta property="og:description" content="{esc(it["desc"])}">', html, count=1)
    # hero text (template + prerender snapshot)
    n0 = html.count('One fake review is costing you customers.')
    html = html.replace('>One fake review is costing you customers.</span>', f'>{a(it["grey"])}</span>')
    html, n1 = re.subn(r'>Get it removed\.</span>(\s*</h1>)', lambda m: f'>{a(it["white"])}</span>{m.group(1)}<p class="br-sub" style="margin:0;font-size:clamp(17px,1.5vw,20px);line-height:1.45;color:#CFCFCF;max-width:620px;text-wrap:pretty">{a(it["sub"])}</p>', html)
    assert n0 >= 1 and n1 == n0, (n0, n1)
    cards = ''.join(f'''
    <div style="background:{'#151515' if i == 2 else '#FFFFFF'};color:{'#fff' if i == 2 else '#151515'};border-radius:28px;padding:clamp(22px,2.6vw,30px);display:flex;flex-direction:column;gap:10px">
      <span style="font-family:'Geist Mono',monospace;font-size:13px;color:{'#8A8A8A' if i == 2 else '#6B6B6B'}">0{i + 1}</span>
      <h3 style="margin:0;font-size:24px;font-weight:500;letter-spacing:-.02em">{a(t)}</h3>
      <p style="margin:0;font-size:16px;line-height:1.5;color:{'#B5B5B5' if i == 2 else '#555'};text-wrap:pretty">{a(d)}</p>
    </div>''' for i, (t, d) in enumerate(it['cards']))
    section = f'''<section data-screen-label="Intent" style="padding:clamp(56px,8vw,96px) clamp(6px,2.6vw,36px) clamp(8px,1vw,16px);display:flex;flex-direction:column;gap:clamp(28px,4vw,48px)">
  <h2 style="margin:0;font-weight:500;font-size:clamp(36px,4.4vw,64px);line-height:1;letter-spacing:-.04em;max-width:900px"><span style="color:#9E9E9E">{a(it["h2a"])}</span> {a(it["h2b"])}</h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(300px,100%),1fr));gap:16px">{cards}
  </div>
</section>

'''
    html, n2 = re.subn(r'<section[^>]*\bid="how"', lambda m: section + m.group(0), html)
    assert n2 == n0, n2
    return html


pages = {
    'index.html': ('/', ''),
    'order/index.html': ('/order/', ''),
    'pricing/index.html': ('/', ''),
    'how-it-works/index.html': ('/', ''),
    'faq/index.html': ('/', ''),
    'results/index.html': ('/', ''),
    **{k: ('/' + k[:-len('index.html')], '') for k in INTENTS},
    'order/thanks/index.html': ('/order/', '<meta name="robots" content="noindex, nofollow">\n'),
    'login/index.html': ('/login/', '<meta name="robots" content="noindex, follow">\n'),
    'dashboard/index.html': ('/dashboard/', '<meta name="robots" content="noindex, nofollow">\n'),
}
# the homepage snapshot (made by tools/prerender.js) is reused for every landing-page URL
home = (PUBLIC / 'index.html').read_text(encoding='utf-8') if (PUBLIC / 'index.html').exists() else ''
snap = re.search(r'<div id="prerender">.*?</div><!--/prerender-->', home, flags=re.S)
if snap:  # hidden for JS visitors: its images must not download (display:none doesn't stop <img>)
    snap = re.sub(r'<img(?![^>]*\bloading=)', '<img loading="lazy"', snap.group(0))
for path, (canonical, robots) in pages.items():
    out = PUBLIC / path
    out.parent.mkdir(parents=True, exist_ok=True)
    html = s.replace('__CANONICAL__', canonical).replace('__ROBOTS__', robots)
    # hero poster = LCP element on landing-page URLs: fetch it before the runtime renders the hero
    html = html.replace('__FAQLD__', FAQ_LD if (path == 'index.html' or path in INTENTS) else '', 1)
    html = html.replace('__PRELOAD__\n', HERO_PRELOAD if (path in HOME_LIKE or path in INTENTS) else '', 1)
    if snap and (path in HOME_LIKE or path in INTENTS):
        html = html.replace('<!--PRERENDER-->', snap, 1)
    if path in INTENTS:
        html = html.replace('<meta property="og:url" content="https://byereviews.com/">', f'<meta property="og:url" content="https://byereviews.com{canonical}">', 1)
        html = intent_page(html, INTENTS[path])
    out.write_text(html, encoding='utf-8')
print('built', ', '.join(pages))
