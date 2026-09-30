#!/usr/bin/env python3
"""Build the admin panel from the design handoff.

  design/admin.dc.html (prototype, untouched) + tools/admin_overrides.js (real backend)
  -> public/admin/index.html

Run from the repo root:  python3 tools/build_admin.py
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
s = (ROOT / 'design' / 'admin.dc.html').read_text(encoding='utf-8')


def rep(old, new, count=1):
    global s
    n = s.count(old)
    assert n == count, f'expected {count}x, found {n}x: {old[:90]!r}'
    s = s.replace(old, new)


rep('<html>\n<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width, initial-scale=1">\n<script src="./support.js"></script>',
    '''<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin – byereviews</title>
<link rel="icon" type="image/png" href="/assets/favicon.png">
<script>
window.__resources = {
  "https://unpkg.com/react@18.3.1/umd/react.production.min.js": "/assets/vendor/react.production.min.js",
  "https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js": "/assets/vendor/react-dom.production.min.js",
  "./LeadFinder.dc.html": "/admin/LeadFinder.dc.html"
};
</script>
<script src="/support.js"></script>''')
rep('<link rel="preconnect" href="https://fonts.googleapis.com">\n<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">',
    '<link href="/assets/fonts/fonts.css" rel="stylesheet">')
s = s.replace('src="assets/byereviews-logo.png"', 'src="/assets/byereviews-logo.png"')

# the dark "PROTOTYPE" bar is not part of the product
s, n = re.subn(r'<div style="position:sticky;top:0;z-index:90;background:#151515.*?</div>\n', '', s, count=1, flags=re.S)
assert n == 1
# customer dashboard + email previews are design references – the real ones are /dashboard/ and the mails in app/emails.php
s, n = re.subn(r'<sc-if value="\{\{ isCustDash \}\}".*?(?=<sc-if value="\{\{ hasToast \}\}")', '', s, count=1, flags=re.S)
assert n == 1
s, n = re.subn(r'\s*<button onClick="\{\{ simulatePaid \}\}"[^>]*>Simulate Stripe payment</button>', '', s)
assert n == 1

rep('<a href="#" onClick="{{ goOrders }}"', '<a href="/admin/" onClick="{{ goOrders }}"')
rep('justify-content:center;font-size:14px">M</span>{{ logoutLabel }}', 'justify-content:center;font-size:14px">{{ adminInitial }}</span>{{ logoutLabel }}')
rep('<span style="font-size:12px;color:#B5B5B5">{{ o.paidAt }} · via Stripe</span>', '<span style="font-size:12px;color:#B5B5B5">{{ o.paidAt }} · {{ o.paidVia }}</span>')
rep('''<span style="width:10px;height:10px;border-radius:50%;background:#151515;display:block"></span><span style="font-size:15px;font-weight:500;flex:1">Connected</span><span style="font-family:'Geist Mono',monospace;font-size:12px;color:#6B6B6B">acct_…8F2k</span>''',
    '''<span style="width:10px;height:10px;border-radius:50%;background:{{ stripeDot }};display:block"></span><span style="font-size:15px;font-weight:500;flex:1">{{ stripeLabel }}</span><span style="font-family:'Geist Mono',monospace;font-size:12px;color:#6B6B6B">{{ stripeAcct }}</span>''')
rep('<button onClick="{{ doLogin }}" style="height:56px;', '<sc-if value="{{ loginHint }}" hint-placeholder-val="{{ false }}"><span style="font-size:14px;color:#6B6B6B">{{ loginHint }}</span></sc-if>\n    <button onClick="{{ doLogin }}" style="height:56px;')
rep('<input value="{{ loginEmail }}" onChange="{{ onLoginEmail }}" placeholder="Email"', '<input type="email" autocomplete="username" value="{{ loginEmail }}" onChange="{{ onLoginEmail }}" placeholder="Email"')
rep('<input type="password" value="{{ loginPass }}"', '<input type="password" autocomplete="current-password" value="{{ loginPass }}"')

# logic: keep the design's methods under new names, inject the production layer
for old, new in [('  componentDidMount() {', '  _designDidMount() {'), ('  componentWillUnmount() {', '  _designWillUnmount() {'),
                 ('  renderVals() {', '  _designRenderVals() {'), ('  setStatus(oid, rid, status) {', '  _designSetStatus(oid, rid, status) {'),
                 ('  sendWa(o, list) {', '  _designSendWa(o, list) {'), ('  analytics(range, empty, mob) {', '  _designAnalytics(range, empty, mob) {')]:
    rep(old, new)
# no demo data; the customer-view preview needs a placeholder order when there are none
rep("orders: this.seed(),", "orders: [],")
rep("const co = s.orders.find(o => o.id === s.custId) || s.orders[0];",
    "const co = s.orders.find(o => o.id === s.custId) || s.orders[0] || { id: '', biz: { name: '' }, cust: { name: ' ', email: '' }, reviews: [], payment: { status: 'none' }, msgs: [] };")
overrides = (ROOT / 'tools' / 'admin_overrides.js').read_text(encoding='utf-8')
idx = s.rindex('\n}\n</script>')
s = s[:idx] + '\n' + overrides + s[idx:]

assert 'fonts.googleapis' not in s and 'PROTOTYPE' not in s
out = ROOT / 'public' / 'admin' / 'index.html'
out.parent.mkdir(parents=True, exist_ok=True)
out.write_text(s, encoding='utf-8')
print('built public/admin/index.html')


# ---------- Lead Finder (design/LeadFinder.dc.html, imported by the admin page via <dc-import name="LeadFinder">) ----------
s = (ROOT / 'design' / 'LeadFinder.dc.html').read_text(encoding='utf-8')
rep('<script src="./support.js"></script>', '<script src="/support.js"></script>')
rep('<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">',
    '<link href="/assets/fonts/fonts.css" rel="stylesheet">')
# the dark "STATE / DETAIL AS" bar is prototype-only
s, n = re.subn(r'\n  <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;background:#151515;border-radius:16px;padding:6px">.*?\n  </div>\n', '\n', s, count=1, flags=re.S)
assert n == 1
rep('<span style="font-size:28px;font-weight:600;letter-spacing:-.03em">€0.00</span>', '<span style="font-size:28px;font-weight:600;letter-spacing:-.03em">{{ cost }}</span>')
rep('{{ q.month }} / 930 this month', '{{ q.month }} / {{ q.monthMax }} this month')
rep('<span style="font-weight:600;color:{{ q.todayCol }}">{{ q.today }} / 30</span>', '<span style="font-weight:600;color:{{ q.todayCol }}">{{ q.todayText }}</span>')
s, n = re.subn(r'\n\s*<div style="height:8px;background:#F4F4F4;border-radius:4px;overflow:hidden"><div style="height:100%;border-radius:4px;width:\{\{ q\.todayPct \}\};[^\n]*', '', s, count=1)
assert n == 1
rep("Over 80 % of today's limit used", 'Over 80 % of the monthly limit used')
rep('Limit reached – next run tomorrow</span></sc-if>', '{{ quotaFullText }}</span></sc-if>')
rep('<sc-if value="{{ quotaWarn }}"', '''<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;background:#F4F4F4;border-radius:14px;padding:10px 12px;font-size:14px">
        <span style="color:#555">Monthly limit</span>
        <span style="display:flex;align-items:center;gap:8px"><input type="number" min="0" step="100" aria-label="Monthly search limit" value="{{ monthLimit }}" onChange="{{ onMonthLimit }}" style="width:84px;border:0;background:#FFFFFF;border-radius:8px;padding:7px 8px;font-size:14px;font-weight:600;text-align:center;outline:none"><span style="color:#6B6B6B;font-size:13px">{{ monthLimitCost }}</span></span>
      </div>
      <sc-if value="{{ quotaWarn }}"''')
s, n = re.subn(r'<strong style="font-weight:600;color:#D93025">Stopped at query 18 of 25\.</strong>[^<]*</div>',
               '<strong style="font-weight:600;color:#D93025">{{ quotaStopText }}</strong>{{ quotaStopMore }}</div>', s, count=1)
assert n == 1
rep('<a href="{{ d.maps }}" target="_blank" style="align-self:flex-start;background:#F4F4F4;border-radius:12px;padding:10px 14px;font-size:14px;font-weight:500">Open review on Google ↗</a>',
    '<a href="{{ rv.link }}" target="_blank" style="align-self:flex-start;background:#F4F4F4;border-radius:12px;padding:10px 14px;font-size:14px;font-weight:500">Open review on Google ↗</a>')
rep('Stored: <span style="font-family:\'Geist Mono\',monospace">place_id, status, notes</span>. Review text is loaded live from Google.',
    'Google details are kept for 30 days (Google Maps terms), after that only <span style="font-family:\'Geist Mono\',monospace">place_id, status, notes</span> stay.')
for old, new in [('  componentDidMount() {', '  _designDidMount() {'), ('  componentWillUnmount() {', '  _designWillUnmount() {'),
                 ('  renderVals() {', '  _designRenderVals() {'), ('  setLead(id, patch) {', '  _designSetLead(id, patch) {'),
                 ('  startRun() {', '  _designStartRun() {'), ('  open(id) {', '  _designOpen(id) {')]:
    rep(old, new)
rep('leads: this.leadsSeed(),', 'leads: [],')
# free-text queries → category and city checkboxes
s, n = re.subn(r'      <div style="display:flex;flex-direction:column;gap:6px">\n        <div style="display:flex;justify-content:space-between;font-size:13px"><span style="font-weight:500">Queries · one per line</span>.*?</textarea>\n      </div>\n',
    '''      <div style="display:flex;flex-direction:column;gap:8px">
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px"><span style="font-weight:500">Categories <span style="color:#6B6B6B;font-weight:400">{{ catCount }}</span></span><button onClick="{{ allCats }}" style="border:0;background:transparent;cursor:pointer;font-size:13px;color:#555;text-decoration:underline;text-underline-offset:3px;padding:2px">{{ allCatsLabel }}</button></div>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          <sc-for list="{{ catChips }}" as="c" hint-placeholder-count="12"><button onClick="{{ c.go }}" aria-pressed="{{ c.on }}" style="border:0;cursor:pointer;border-radius:12px;padding:9px 12px;font-size:14px;font-weight:500;background:{{ c.bg }};color:{{ c.fg }}">{{ c.label }}</button></sc-for>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px"><span style="font-weight:500">Cities <span style="color:#6B6B6B;font-weight:400">{{ cityCount }} · every area of a city is searched</span></span><button onClick="{{ allCities }}" style="border:0;background:transparent;cursor:pointer;font-size:13px;color:#555;text-decoration:underline;text-underline-offset:3px;padding:2px">{{ allCitiesLabel }}</button></div>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          <sc-for list="{{ cityChips }}" as="c" hint-placeholder-count="10"><button onClick="{{ c.go }}" aria-pressed="{{ c.on }}" style="border:0;cursor:pointer;border-radius:12px;padding:8px 12px;font-size:14px;font-weight:500;background:{{ c.bg }};color:{{ c.fg }};display:flex;gap:6px;align-items:baseline">{{ c.label }}<span style="font-size:11px;opacity:.6">{{ c.sub }}</span></button></sc-for>
        </div>
        <span style="font-size:13px;line-height:1.45;color:{{ planWarn }}">{{ planText }}</span>
      </div>
''', s, count=1, flags=re.S)
assert n == 1
rep('        Find Instagram via website\n      </button>\n    </div>',
    '''        Find Instagram via website
      </button>
      <div style="display:flex;flex-direction:column;gap:6px">
        <span style="font-size:13px;font-weight:500">Skip chains <span style="color:#6B6B6B;font-weight:400">· big chains are skipped automatically, add more comma-separated</span></span>
        <input value="{{ exclude }}" onChange="{{ onExclude }}" placeholder="e.g. Five Guys, Toni &amp; Guy" style="border:0;background:#F4F4F4;border-radius:14px;padding:12px 14px;font-size:14px;outline:none">
      </div>
    </div>''')
overrides = (ROOT / 'tools' / 'leads_overrides.js').read_text(encoding='utf-8')
idx = s.rindex('\n}\n</script>')
s = s[:idx] + '\n' + overrides + s[idx:]
assert 'fonts.googleapis' not in s and 'STATE</span>' not in s
(ROOT / 'public' / 'admin' / 'LeadFinder.dc.html').write_text(s, encoding='utf-8')
print('built public/admin/LeadFinder.dc.html')
