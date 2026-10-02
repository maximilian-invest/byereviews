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
  "./LeadFinder.dc.html": "/admin/LeadFinder.dc.html",
  "./Inbox.dc.html": "/admin/Inbox.dc.html"
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
# Inbox (design/Inbox.dc.html, own component): nav item with unread badge + view
rep('color:{{ navOrdersFg }}">Orders</button>', 'color:{{ navOrdersFg }}">Orders</button>\n    <button onClick="{{ goInbox }}" style="border:0;cursor:pointer;border-radius:14px;padding:10px 14px;font-size:14px;font-weight:500;display:flex;align-items:center;gap:7px;background:{{ navInboxBg }};color:{{ navInboxFg }}">Inbox<sc-if value="{{ hasInboxBadge }}" hint-placeholder-val="{{ false }}"><span style="min-width:18px;height:18px;padding:0 5px;box-sizing:border-box;border-radius:9px;background:#D93025;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center">{{ inboxBadge }}</span></sc-if></button>')
rep('<sc-if value="{{ isLeads }}" hint-placeholder-val="{{ false }}">', '<sc-if value="{{ isInbox }}" hint-placeholder-val="{{ false }}">\n<dc-import name="Inbox" hint-size="100%,900px"></dc-import>\n</sc-if>\n<sc-if value="{{ isLeads }}" hint-placeholder-val="{{ false }}">')
rep('justify-content:center;font-size:14px">M</span>{{ logoutLabel }}', 'justify-content:center;font-size:14px">{{ adminInitial }}</span>{{ logoutLabel }}')
rep('<span style="font-size:12px;color:#B5B5B5">{{ o.paidAt }} · via Stripe</span>', '<span style="font-size:12px;color:#B5B5B5">{{ o.paidAt }} · {{ o.paidVia }}</span>')
rep('''<span style="width:10px;height:10px;border-radius:50%;background:#151515;display:block"></span><span style="font-size:15px;font-weight:500;flex:1">Connected</span><span style="font-family:'Geist Mono',monospace;font-size:12px;color:#6B6B6B">acct_…8F2k</span>''',
    '''<span style="width:10px;height:10px;border-radius:50%;background:{{ stripeDot }};display:block"></span><span style="font-size:15px;font-weight:500;flex:1">{{ stripeLabel }}</span><span style="font-family:'Geist Mono',monospace;font-size:12px;color:#6B6B6B">{{ stripeAcct }}</span>''')
# Stripe keys are pasted here by the owner (stored server-side in settings/stripe.json, never shown again)
rep("""{{ stripeAcct }}</span></div></div>""", """{{ stripeAcct }}</span></div>
      <sc-if value="{{ stripeNoWebhook }}" hint-placeholder-val="{{ false }}"><span style="font-size:13px;color:#D93025;line-height:1.45">Webhook secret missing – payments won't be marked as paid automatically.</span></sc-if>
      <input type="password" autocomplete="off" spellcheck="false" value="{{ stripeKeyDraft }}" onChange="{{ onStripeKey }}" placeholder="{{ stripeKeyPh }}" style="border:0;background:#F4F4F4;border-radius:14px;padding:14px 16px;font-size:14px;outline:none;font-family:'Geist Mono',monospace">
      <input type="password" autocomplete="off" spellcheck="false" value="{{ stripeWhDraft }}" onChange="{{ onStripeWh }}" placeholder="{{ stripeWhPh }}" style="border:0;background:#F4F4F4;border-radius:14px;padding:14px 16px;font-size:14px;outline:none;font-family:'Geist Mono',monospace">
      <span style="font-size:12px;color:#6B6B6B;line-height:1.5">Webhook URL: <span style="font-family:'Geist Mono',monospace;user-select:all">https://byereviews.com/order.php?a=stripe-webhook</span> · events checkout.session.completed + checkout.session.async_payment_succeeded</span>
      <button onClick="{{ saveStripe }}" style="align-self:flex-start;height:44px;border:0;cursor:pointer;background:#151515;color:#fff;border-radius:14px;padding:0 18px;font-size:14px;font-weight:600">{{ stripeSaveLabel }}</button></div>""")
rep('<button onClick="{{ doLogin }}" style="height:56px;', '<sc-if value="{{ loginHint }}" hint-placeholder-val="{{ false }}"><span style="font-size:14px;color:#6B6B6B">{{ loginHint }}</span></sc-if>\n    <button onClick="{{ doLogin }}" style="height:56px;')
rep('<input value="{{ loginEmail }}" onChange="{{ onLoginEmail }}" placeholder="Email"', '<input type="email" autocomplete="username" value="{{ loginEmail }}" onChange="{{ onLoginEmail }}" placeholder="Email"')
rep('<input type="password" value="{{ loginPass }}"', '<input type="password" autocomplete="current-password" value="{{ loginPass }}"')

# order actions: cancel / delete (not in the design yet) + cancelled banner
rep('''<a href="{{ o.biz.profile }}" target="_blank" style="font-weight:500;color:#151515">Google profile ↗</a>
        </div>
      </div>''', '''<a href="{{ o.biz.profile }}" target="_blank" style="font-weight:500;color:#151515">Google profile ↗</a>
        </div>
      </div>
      <div style="background:#FFFFFF;border-radius:28px;padding:clamp(18px,2.4vw,24px);display:flex;flex-direction:column;gap:10px;border:1px solid #E4E4E4">
        <span style="font-size:20px;font-weight:600;letter-spacing:-.02em">Order</span>
        <sc-if value="{{ o.cancelled }}" hint-placeholder-val="{{ false }}"><span style="font-size:14px;color:#6B6B6B;line-height:1.5">Cancelled {{ o.cancelledInfo }}</span></sc-if>
        <textarea value="{{ orderReason }}" onChange="{{ onOrderReason }}" rows="2" placeholder="Reason (optional, included in the customer email)" style="border:0;background:#F4F4F4;border-radius:14px;padding:12px 14px;font-size:14px;line-height:1.45;outline:none;resize:vertical"></textarea>
        <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:#555;cursor:pointer"><input type="checkbox" checked="{{ orderNotify }}" onChange="{{ onOrderNotify }}" style="accent-color:#151515">Email the customer</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
          <sc-if value="{{ o.canCancel }}" hint-placeholder-val="{{ true }}"><button onClick="{{ cancelOrder }}" style="border:1px solid #151515;background:#FFFFFF;cursor:pointer;border-radius:12px;padding:12px;font-size:14px;font-weight:500">Cancel order</button></sc-if>
          <button onClick="{{ deleteOrder }}" style="border:1px solid #D93025;background:#FFFFFF;color:#D93025;cursor:pointer;border-radius:12px;padding:12px;font-size:14px;font-weight:500">Delete order</button>
        </div>
        <span style="font-size:12px;color:#8A8A8A;line-height:1.5">Cancel stops all open reviews – removed ones stay billable. Delete removes the order from the list and the customer's account (archived for bookkeeping).</span>
      </div>''')
rep('''<h1 style="margin:0;font-size:clamp(30px,3.6vw,48px);font-weight:600;letter-spacing:-.045em;line-height:1">{{ o.company }}</h1>''',
    '''<h1 style="margin:0;font-size:clamp(30px,3.6vw,48px);font-weight:600;letter-spacing:-.045em;line-height:1">{{ o.company }}</h1>
        <sc-if value="{{ o.cancelled }}" hint-placeholder-val="{{ false }}"><span style="align-self:flex-start;font-size:13px;font-weight:600;border-radius:10px;padding:6px 10px;background:#FFFFFF;color:#D93025;border:1px solid #D93025">Cancelled {{ o.cancelledInfo }}</span></sc-if>''')

# logic: keep the design's methods under new names, inject the production layer
for old, new in [('  componentDidMount() {', '  _designDidMount() {'), ('  componentWillUnmount() {', '  _designWillUnmount() {'),
                 ('  renderVals() {', '  _designRenderVals() {'), ('  setStatus(oid, rid, status) {', '  _designSetStatus(oid, rid, status) {'),
                 ('  sendWa(o, list) {', '  _designSendWa(o, list) {'), ('  statusMeta(st) {', '  _designStatusMeta(st) {'), ('  payInfo(o) {', '  _designPayInfo(o) {'), ('  analytics(range, empty, mob) {', '  _designAnalytics(range, empty, mob) {')]:
    rep(old, new)
# no demo data; the customer-view preview needs a placeholder order when there are none
rep("orders: this.seed(),", "orders: [],")
rep("const co = s.orders.find(o => o.id === s.custId) || s.orders[0];",
    "const co = s.orders.find(o => o.id === s.custId) || s.orders[0] || { id: '', biz: { name: '' }, cust: { name: ' ', email: '' }, reviews: [], payment: { status: 'none' }, msgs: [] };")
# bulk price (app/lib.php BULK_MIN / BULK_PRICE): from 10 submitted reviews every removed review costs 50
rep("calc(o) { const rem = o.reviews.filter(r => r.status === 'removed'), sub = rem.reduce((a, r) => a + this.price(r), 0), rt = this.rate(rem.length), disc = Math.round(sub * rt); return { rem, sub, rt, disc, total: sub - disc }; }",
    "calc(o) { const rem = o.reviews.filter(r => r.status === 'removed'), sub = rem.reduce((a, r) => a + this.price(r), 0), bulk = o.reviews.length >= 10, rt = bulk ? 0 : this.rate(rem.length), total = bulk ? rem.length * 50 : sub - Math.round(sub * rt), disc = sub - total;"
    " return { rem, sub, rt, disc, total, bulk, label: bulk ? 'Bulk price 10+ (' + this.fmt(50) + ' each)' : 'Volume discount ' + Math.round(rt * 100) + '%' }; }")
rep('<span style="color:#6B6B6B">Volume discount {{ o.discPct }}</span>', '<span style="color:#6B6B6B">{{ o.discLabel }}</span>')
rep("discPct: Math.round(c.rt * 100) + '%', discAmt:", "discPct: Math.round(c.rt * 100) + '%', discLabel: c.label, discAmt:")

# Checks: every Google profile looked up on the order page (app/analytics.php checks_data, ?a=admin-checks)
rep('    <button onClick="{{ goAnalytics }}"',
    '    <button onClick="{{ goChecks }}" style="border:0;cursor:pointer;border-radius:14px;padding:10px 14px;font-size:14px;font-weight:500;display:flex;align-items:center;gap:7px;background:{{ navChkBg }};color:{{ navChkFg }}">Checks'
    '<sc-if value="{{ hasChkBadge }}" hint-placeholder-val="{{ false }}"><span style="min-width:18px;height:18px;border-radius:9px;background:#D93025;color:#fff;font-size:11px;font-weight:600;display:flex;align-items:center;justify-content:center;padding:0 5px">{{ chkBadge }}</span></sc-if></button>\n'
    '    <button onClick="{{ goAnalytics }}"')
rep('<sc-if value="{{ isLeads }}" hint-placeholder-val="{{ false }}">', '''<sc-if value="{{ isChecks }}" hint-placeholder-val="{{ false }}">
<section data-screen-label="Checks" style="display:flex;flex-direction:column;gap:14px">
  <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;padding:12px 4px 0">
    <div style="display:flex;flex-direction:column;gap:6px"><h1 style="margin:0;font-size:clamp(34px,4vw,52px);font-weight:600;letter-spacing:-.045em;line-height:1">Checks</h1><span style="font-size:13px;color:#6B6B6B">Every business someone looked up on the order page · {{ chk.periodLabel }}</span></div>
    <div style="display:flex;background:#FFFFFF;border-radius:16px;padding:4px;gap:2px">
      <sc-for list="{{ chk.ranges }}" as="t" hint-placeholder-count="4"><button onClick="{{ t.go }}" style="border:0;cursor:pointer;border-radius:12px;padding:9px 13px;font-size:14px;font-weight:500;white-space:nowrap;background:{{ t.bg }};color:{{ t.fg }}">{{ t.label }}</button></sc-for>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
    <sc-for list="{{ chk.stats }}" as="k" hint-placeholder-count="4"><div style="background:#FFFFFF;border-radius:22px;padding:16px 18px;display:flex;flex-direction:column;gap:6px"><span style="font-size:13px;color:#6B6B6B">{{ k.label }}</span><span style="font-size:34px;font-weight:600;letter-spacing:-.04em;line-height:1;color:{{ k.col }}">{{ k.value }}</span></div></sc-for>
  </div>
  <div style="background:#FFFFFF;border-radius:28px;padding:clamp(16px,2.2vw,24px);display:flex;flex-direction:column;gap:14px;min-width:0">
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
      <div style="display:flex;background:#F4F4F4;border-radius:14px;padding:4px;gap:2px">
        <sc-for list="{{ chk.filters }}" as="f" hint-placeholder-count="3"><button onClick="{{ f.go }}" style="border:0;cursor:pointer;border-radius:10px;padding:8px 12px;font-size:13px;font-weight:500;white-space:nowrap;background:{{ f.bg }};color:{{ f.fg }}">{{ f.label }} <span style="opacity:.6">{{ f.n }}</span></button></sc-for>
      </div>
      <button onClick="{{ chk.toggleInt }}" style="border:1px solid #E4E4E4;cursor:pointer;background:#FFFFFF;color:#555;border-radius:12px;padding:8px 12px;font-size:13px;font-weight:500">{{ chk.intLabel }}</button>
    </div>
    <sc-if value="{{ chk.empty }}" hint-placeholder-val="{{ false }}"><span style="font-size:14px;color:#8A8A8A;background:#F4F4F4;border-radius:14px;padding:16px">{{ chk.emptyText }}</span></sc-if>
    <sc-if value="{{ chk.has }}" hint-placeholder-val="{{ true }}">
    <div style="overflow-x:auto">
      <div style="min-width:1180px;display:flex;flex-direction:column">
        <div style="display:grid;grid-template-columns:minmax(200px,1.6fr) 110px 64px 70px 52px 70px 120px minmax(110px,1fr) minmax(130px,1.1fr) 92px 150px;gap:12px;padding:0 10px 10px;font-family:'Geist Mono',monospace;font-size:11px;color:#8A8A8A"><span>COMPANY</span><span>COUNTRY</span><span>RATING</span><span>REVIEWS</span><span>1–3★</span><span>SELECTED</span><span>SOURCE</span><span>LANDING PAGE</span><span>REACHED STEP</span><span>WHEN</span><span></span></div>
        <sc-for list="{{ chk.rows }}" as="c" hint-placeholder-count="6">
          <div style="display:grid;grid-template-columns:minmax(200px,1.6fr) 110px 64px 70px 52px 70px 120px minmax(110px,1fr) minmax(130px,1.1fr) 92px 150px;gap:12px;align-items:center;padding:12px 10px;border-top:1px solid #F0F0F0;font-size:14px;border-radius:12px;background:{{ c.bg }}">
            <span style="font-weight:600;display:flex;align-items:center;gap:8px;min-width:0"><sc-if value="{{ c.hot }}" hint-placeholder-val="{{ false }}"><span style="flex:none;width:8px;height:8px;border-radius:50%;background:#D93025;display:block"></span></sc-if><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ c.name }}">{{ c.name }}</span><sc-if value="{{ c.internal }}" hint-placeholder-val="{{ false }}"><span style="flex:none;font-size:11px;font-weight:500;background:#EFEFEF;color:#6B6B6B;border-radius:7px;padding:3px 6px">test</span></sc-if></span>
            <span style="color:#555" title="{{ c.visitorTip }}">{{ c.country }}</span><span>{{ c.rating }}</span><span>{{ c.count }}</span><span>{{ c.low }}</span><span>{{ c.sel }}</span>
            <span style="font-size:13px;color:#555">{{ c.src }}</span>
            <span style="font-family:'Geist Mono',monospace;font-size:12px;color:#6B6B6B;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ c.land }}">{{ c.land }}</span>
            <span style="font-size:13px;color:{{ c.stepCol }};font-weight:{{ c.stepW }}">{{ c.step }}</span>
            <span style="font-size:13px;color:#6B6B6B" title="{{ c.whenFull }}">{{ c.when }}</span>
            <div style="display:flex;gap:6px;justify-content:flex-end">
              <sc-if value="{{ c.hasOrder }}" hint-placeholder-val="{{ false }}"><button onClick="{{ c.openOrder }}" style="border:0;cursor:pointer;background:#F4F4F4;color:#151515;border-radius:10px;padding:8px 10px;font-size:12px;font-weight:500;white-space:nowrap">{{ c.orderId }}</button></sc-if>
              <a href="{{ c.profile }}" target="_blank" rel="noopener" style="background:{{ c.btnBg }};color:{{ c.btnFg }};border-radius:10px;padding:8px 10px;font-size:12px;font-weight:500;text-align:center;white-space:nowrap">Google profile ↗</a>
            </div>
          </div>
        </sc-for>
      </div>
    </div>
    </sc-if>
    <span style="font-size:12px;color:#8A8A8A;line-height:1.5"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#D93025;margin-right:6px"></span>Red = checked but no order. Country = the business, hover for the visitor's browser region. You get an email for every new check (once per business per 6 h, not for your own tests).</span>
  </div>
</section>
</sc-if>
<sc-if value="{{ isLeads }}" hint-placeholder-val="{{ false }}">''')

overrides = (ROOT / 'tools' / 'admin_overrides.js').read_text(encoding='utf-8')
idx = s.rindex('\n}\n</script>')
s = s[:idx] + '\n' + overrides + s[idx:]

assert 'fonts.googleapis' not in s and 'PROTOTYPE' not in s
# Settings: Google Ads conversions (cookie-free click IDs + CSV export for offline conversion import, app/ads.php)
rep('  <button onClick="{{ saveSettings }}" style="align-self:flex-start;height:54px;', """  <div style="background:#FFFFFF;border-radius:28px;padding:clamp(18px,2.4vw,26px);display:flex;flex-direction:column;gap:12px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
      <span style="font-size:18px;font-weight:600">Google Ads conversions</span>
      <button onClick="{{ adsToggle }}" aria-label="Store Google Ads click IDs" style="flex:none;width:52px;height:30px;border:0;cursor:pointer;border-radius:15px;padding:3px;background:{{ adsSwBg }};display:flex;justify-content:{{ adsSwJust }}"><span style="width:24px;height:24px;border-radius:50%;background:#fff;display:block"></span></button>
    </div>
    <span style="font-size:14px;line-height:1.5;color:#555">{{ adsText }}</span>
    <span style="font-family:'Geist Mono',monospace;font-size:12px;color:#6B6B6B">{{ adsStatsText }}</span>
    <sc-if value="{{ adsFeed }}" hint-placeholder-val="{{ false }}"><div style="background:#F4F4F4;border-radius:14px;padding:12px 14px;display:flex;flex-direction:column;gap:6px;font-size:13px;line-height:1.5">
      <span style="font-weight:600">Automatic upload (Google Ads → Goals → Conversions → Uploads → Schedules → HTTPS)</span>
      <span style="font-family:'Geist Mono',monospace;font-size:12px;word-break:break-all">{{ adsFeedOrder }}</span>
      <span style="font-family:'Geist Mono',monospace;font-size:12px;word-break:break-all">{{ adsFeedPaid }}</span>
      <span style="font-family:'Geist Mono',monospace;font-size:12px;word-break:break-all">user: googleads · password: {{ adsFeedPw }}</span>
    </div></sc-if>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="/order.php?a=admin-ads-export&amp;kind=order" style="background:#F4F4F4;border-radius:12px;padding:10px 14px;font-size:14px;font-weight:500;color:#151515">Download order conversions (CSV)</a>
      <a href="/order.php?a=admin-ads-export&amp;kind=paid" style="background:#F4F4F4;border-radius:12px;padding:10px 14px;font-size:14px;font-weight:500;color:#151515">Download payment conversions (CSV)</a>
    </div>
  </div>
  <button onClick="{{ saveSettings }}" style="align-self:flex-start;height:54px;""")

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
# free-text queries → two dropdowns with checkboxes + profiles per run
s, n = re.subn(r'      <div style="display:flex;flex-direction:column;gap:6px">\n        <div style="display:flex;justify-content:space-between;font-size:13px"><span style="font-weight:500">Queries · one per line</span>.*?</textarea>\n      </div>\n',
    lambda m: '      <sc-if value="{{ anyOpen }}" hint-placeholder-val="{{ false }}"><div onClick="{{ closeDrop }}" style="position:fixed;inset:0;z-index:20"></div></sc-if>\n      <div style="display:grid;grid-template-columns:{{ pickCols }};gap:10px">\n        <div style="position:relative;z-index:{{ catsZ }}">\n          <span style="display:block;font-size:13px;font-weight:500;margin-bottom:6px">Categories</span>\n          <button onClick="{{ openCats }}" aria-expanded="{{ catsOpen }}" aria-haspopup="listbox" style="width:100%;border:0;cursor:pointer;background:#F4F4F4;border-radius:14px;padding:13px 14px;font-size:14px;font-weight:500;display:flex;justify-content:space-between;align-items:center;gap:8px;color:#151515;text-align:left"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ catSummary }}</span><svg width="12" height="12" viewBox="0 0 16 16" fill="none" style="flex:none"><path d="M4 6l4 4 4-4" stroke="#151515" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>\n          <sc-if value="{{ catsIsOpen }}" hint-placeholder-val="{{ false }}">\n            <div role="listbox" aria-multiselectable="true" style="position:absolute;left:0;right:0;top:calc(100% + 6px);background:#FFFFFF;border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.18);padding:6px;max-height:340px;overflow:auto">\n              <sc-for list="{{ catOptions }}" as="o" hint-placeholder-count="8"><button onClick="{{ o.go }}" role="option" aria-selected="{{ o.on }}" style="width:100%;border:0;cursor:pointer;background:transparent;border-radius:10px;padding:9px 10px;display:flex;align-items:center;gap:10px;font-size:14px;color:#151515;text-align:left"><span style="flex:none;width:18px;height:18px;box-sizing:border-box;border-radius:6px;border:1.5px solid {{ o.bd }};background:{{ o.bg }};display:flex;align-items:center;justify-content:center;color:#FFFFFF;font-size:12px;font-weight:700">{{ o.tick }}</span><span style="flex:1">{{ o.label }}</span><span style="font-size:12px;color:#8A8A8A">{{ o.sub }}</span></button></sc-for>\n            </div>\n          </sc-if>\n        </div>\n        <div style="position:relative;z-index:{{ citiesZ }}">\n          <span style="display:block;font-size:13px;font-weight:500;margin-bottom:6px">Cities</span>\n          <button onClick="{{ openCities }}" aria-expanded="{{ citiesOpen }}" aria-haspopup="listbox" style="width:100%;border:0;cursor:pointer;background:#F4F4F4;border-radius:14px;padding:13px 14px;font-size:14px;font-weight:500;display:flex;justify-content:space-between;align-items:center;gap:8px;color:#151515;text-align:left"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ citySummary }}</span><svg width="12" height="12" viewBox="0 0 16 16" fill="none" style="flex:none"><path d="M4 6l4 4 4-4" stroke="#151515" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>\n          <sc-if value="{{ citiesIsOpen }}" hint-placeholder-val="{{ false }}">\n            <div role="listbox" aria-multiselectable="true" style="position:absolute;left:0;right:0;top:calc(100% + 6px);background:#FFFFFF;border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.18);padding:6px;max-height:340px;overflow:auto">\n              <sc-for list="{{ cityOptions }}" as="o" hint-placeholder-count="8"><button onClick="{{ o.go }}" role="option" aria-selected="{{ o.on }}" style="width:100%;border:0;cursor:pointer;background:transparent;border-radius:10px;padding:9px 10px;display:flex;align-items:center;gap:10px;font-size:14px;color:#151515;text-align:left"><span style="flex:none;width:18px;height:18px;box-sizing:border-box;border-radius:6px;border:1.5px solid {{ o.bd }};background:{{ o.bg }};display:flex;align-items:center;justify-content:center;color:#FFFFFF;font-size:12px;font-weight:700">{{ o.tick }}</span><span style="flex:1">{{ o.label }}</span><span style="font-size:12px;color:#8A8A8A">{{ o.sub }}</span></button></sc-for>\n            </div>\n          </sc-if>\n        </div>\n      </div>\n      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;background:#F4F4F4;border-radius:14px;padding:10px 12px;font-size:14px">\n        <span style="font-weight:500">Profiles to check per run</span>\n        <span style="display:flex;align-items:center;gap:8px"><input type="number" min="20" step="100" aria-label="Profiles to check per run" value="{{ target }}" onChange="{{ onTarget }}" style="width:90px;border:0;background:#FFFFFF;border-radius:8px;padding:7px 8px;font-size:14px;font-weight:600;text-align:center;outline:none"><span style="color:#6B6B6B;font-size:13px">{{ targetCost }}</span></span>\n      </div>\n      <span style="font-size:13px;line-height:1.45;color:{{ planWarn }}">{{ planText }}</span>\n', s, count=1, flags=re.S)
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

(ROOT / 'public' / 'admin' / 'Inbox.dc.html').write_text((ROOT / 'design' / 'Inbox.dc.html').read_text(encoding='utf-8'), encoding='utf-8')
print('built public/admin/Inbox.dc.html')
