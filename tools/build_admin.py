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
  "https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js": "/assets/vendor/react-dom.production.min.js"
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
