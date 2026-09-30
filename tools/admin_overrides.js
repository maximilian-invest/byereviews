  // ===== byereviews production layer for the admin panel (injected by tools/build_admin.py) =====

  api(action, opts = {}) {
    const q = opts.query ? '&' + new URLSearchParams(opts.query).toString() : '';
    const init = opts.body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(opts.body) } : { credentials: 'same-origin' };
    return fetch('/order.php?a=' + action + q, init).then(r => r.json().catch(() => ({ ok: false, error: 'bad_response' }))).catch(() => ({ ok: false, error: 'network' }));
  }
  state = Object.assign({}, this.state, { screen: 'boot', orders: [], loginEmail: '', loginPass: '', partnerNo: '', sender: '', template: '', stripe: { connected: false, account: '' }, anData: null, anLoading: false, adminEmail: '' });

  componentDidMount() {
    this._designDidMount();
    this.api('admin-me').then(r => {
      if (r.ok && r.authed) { this.setState({ screen: 'admin', authed: true, adminEmail: r.email }); this.load(true); }
      else this.setState({ screen: 'login', notConfigured: r.ok && !r.configured });
    });
    this.poll = setInterval(() => { if (this.state.authed && !document.hidden) this.load(false); }, 20000);
    window.addEventListener('hashchange', this.onHash = () => this.fromHash());
  }
  componentWillUnmount() { this._designWillUnmount(); clearInterval(this.poll); window.removeEventListener('hashchange', this.onHash); }

  load(first) {
    return this.api('admin-orders').then(r => {
      if (!r.ok) { if (r.error === 'not_authed') this.setState({ screen: 'login', authed: false }); return; }
      const st = r.settings || {};
      this.setState(s => ({ orders: r.orders,
        ...(first ? { partnerNo: st.partnerNo || '', template: st.template || s.template, sender: st.sender || '' } : {}),
        stripe: st.stripe || s.stripe }), () => { if (first) this.fromHash(); });
    });
  }
  // #BR-12345 opens an order, #analytics / #settings the views
  fromHash() {
    const h = decodeURIComponent((location.hash || '').slice(1));
    this._hashReady = true;
    if (/^BR-\d{5}$/.test(h) && this.state.orders.some(o => o.id === h)) this.setState({ view: 'detail', openId: h, sel: {}, draft: '' });
    else if (h === 'analytics') { this.setState({ view: 'analytics', openId: null }); this.loadAnalytics(); }
    else if (h === 'settings') this.setState({ view: 'settings', openId: null });
    else if (h === 'leads') this.setState({ view: 'leads', openId: null });
  }
  componentDidUpdate() {
    const s = this.state, h = s.view === 'detail' && s.openId ? '#' + s.openId : s.view === 'analytics' ? '#analytics' : s.view === 'settings' ? '#settings' : s.view === 'leads' ? '#leads' : '';
    if (s.authed && this._hashReady && (location.hash || '') !== h) history.replaceState(null, '', location.pathname + h);
    if (s.view === 'analytics' && (s.range || 30) !== this._anRange) this.loadAnalytics();
  }
  loadAnalytics() {
    const range = this.state.range || 30; this._anRange = range;
    this.setState({ anLoading: true });
    this.api('admin-analytics', { query: { range } }).then(r => { if (r.ok && (this.state.range || 30) === range) this.setState({ anData: r, anLoading: false }); });
  }
  replaceOrder(o) { if (o) this.setState(s => ({ orders: s.orders.map(x => x.id === o.id ? o : x) })); }
  fail(r, fallback) {
    const m = { stripe_not_configured: 'Stripe is not connected – add the Stripe key on the server', stripe_failed: 'Stripe error – please try again', nothing_billable: 'Nothing to bill yet', not_authed: 'Session expired – please sign in again', invalid_sender: 'Invalid sender email' }[r.error];
    this.toast(m || fallback || 'Something went wrong');
    if (r.error === 'not_authed') this.setState({ screen: 'login', authed: false });
  }

  login() {
    const s = this.state;
    this.api('admin-login', { body: { email: s.loginEmail.trim(), password: s.loginPass } }).then(r => {
      if (!r.ok) return this.toast(r.error === 'not_configured' ? 'Admin login is not configured on the server' : r.error === 'too_many_requests' ? 'Too many attempts – wait a few minutes' : "Email or password doesn't match");
      this.setState({ screen: 'admin', authed: true, loginPass: '', adminEmail: r.email, view: 'orders' });
      this.load(true);
    });
  }

  setStatus(oid, rid, status) {
    this._designSetStatus(oid, rid, status); // optimistic update, row flash, toast
    this.api('admin-status', { body: { order: oid, review: rid, status } }).then(r => r.ok ? this.replaceOrder(r.order) : (this.fail(r, 'Could not save'), this.load(false)));
  }
  sendWa(o, list) {
    if (!list.length) return;
    if (!(this.state.partnerNo || '').replace(/[^\d]/g, '')) this.toast('No partner number in Settings – pick the chat in WhatsApp');
    // open WhatsApp (wa.me) with the template: one line per review with author, stars, a text excerpt and the link
    const lines = list.map((r, i) => `${i + 1}. ${r.author} – ${r.stars ? r.stars + '★' : 'no stars'}${r.text ? ' – "' + (r.text.length > 80 ? r.text.slice(0, 80) + '…' : r.text) + '"' : ''}\n   ${r.link}`).join('\n');
    const msg = (this.state.template || '').replace(/\{company\}/g, o.biz.name).replace(/\{profile_link\}/g, o.biz.profile).replace(/\{order_id\}/g, o.id).replace(/\{reviews\}/g, lines);
    const no = (this.state.partnerNo || '').replace(/[^\d]/g, '');
    try { window.open('https://wa.me/' + no + '?text=' + encodeURIComponent(msg), '_blank'); } catch (e) {}
    const ids = new Set(list.map(r => r.id));
    this.updOrder(o.id, x => ({ ...x, reviews: x.reviews.map(r => ids.has(r.id) ? { ...r, status: 'in_progress', sentAt: Date.now(), updatedAt: Date.now() } : r) }));
    this.setState({ sel: {} });
    this.toast(list.length + ' review' + (list.length > 1 ? 's' : '') + ' sent via WhatsApp · set to In progress');
    this.api('admin-wa', { body: { order: o.id, reviews: list.map(r => r.id) } }).then(r => r.ok ? this.replaceOrder(r.order) : this.fail(r, 'Could not save'));
  }

  statusMeta(st) { return this._designStatusMeta(st) || ['Cancelled', '#FFFFFF', '#8A8A8A', '#D2D2D2']; }
  payInfo(o) { const p = this._designPayInfo(o); return o.cancelled && (p[0] === '—' || p[0] === 'Paid') ? ['Cancelled', '#FFFFFF', '#D93025', '#D93025'] : p; }

  // real numbers for the analytics view, in the shape the design renders
  analytics(range, empty, mob) {
    const d = this.state.anData, s = this.state;
    const serpOf = sp => { const used = sp ? sp.used : 0, max = sp && sp.max ? sp.max : 0; return { serpUsed: used, serpMax: max || '—', serpPct: (max ? Math.min(100, used / max * 100) : 0) + '%', serpCol: max && used / max > .8 ? '#D93025' : '#151515' }; };
    if (!d || !d.hasData) return { empty: true, has: false, periodLabel: s.anLoading ? 'Loading…' : 'No data', ...serpOf(d && d.serp) };
    const fmt = n => '€' + Math.round(n).toLocaleString('en-US');
    const chip = (a, b) => { if (b == null || a == null) return { delta: '—', dBg: '#EEEEEE', dFg: '#6B6B6B' }; const v = b ? Math.round((a - b) / b * 100) : (a ? 100 : 0); return { delta: (v >= 0 ? '+' : '−') + Math.abs(v) + '%', dBg: v >= 0 ? '#E6F4EA' : '#FCE8E6', dFg: v >= 0 ? '#1E8E3E' : '#D93025' }; };
    const spark = arr => { const n = Math.min(arr.length, 30), a = arr.slice(-n), mx = Math.max(...a), mn = Math.min(...a); return a.map((v, i) => (i / Math.max(1, n - 1) * 100).toFixed(1) + ',' + (26 - (v - mn) / ((mx - mn) || 1) * 24).toFixed(1)).join(' '); };
    const k = d.kpi, se = d.series;
    const conv = se.checks.map((c, i) => c ? se.orders[i] / c : 0);
    const kpis = [
      ['Profiles checked', k.checks[0].toLocaleString('en-US'), se.checks, chip(k.checks[0], k.checks[1])],
      ['Orders', k.orders[0].toLocaleString('en-US'), se.orders, chip(k.orders[0], k.orders[1])],
      ['Conversion', (k.conv[0] * 100).toFixed(1) + '%', conv, chip(k.conv[0], k.conv[1])],
      ['Revenue paid', fmt(k.revenue[0]), se.revenue, chip(k.revenue[0], k.revenue[1])],
      ['Removal rate', k.removal[0] == null ? '—' : Math.round(k.removal[0] * 100) + '%', se.orders, chip(k.removal[0], k.removal[1])]
    ].map(([label, value, arr, c]) => ({ label, value, spark: spark(arr), ...c }));
    const steps = d.funnel, start = steps[0][1] || 1;
    let worst = 1, worstDrop = -1;
    steps.forEach((st, i) => { if (i && steps[i - 1][1]) { const dr = 1 - st[1] / steps[i - 1][1]; if (dr > worstDrop) { worstDrop = dr; worst = i; } } });
    const funnel = steps.map(([label, n], i) => { const prev = i ? steps[i - 1][1] : 0, dr = i && prev ? 1 - n / prev : 0, isW = i === worst && worstDrop > 0;
      return { label, n: n.toLocaleString('en-US'), pct: Math.round(n / start * 100) + '%', w: Math.max(2, n / start * 100) + '%', bar: isW ? '#D93025' : '#151515',
        hasDrop: i > 0, drop: Math.round(Math.max(0, dr) * 100) + '%', dropCol: isW ? '#D93025' : '#8A8A8A', dropW: isW ? 600 : 400 }; });
    const days = d.days, sum = a => a.reduce((x, y) => x + y, 0), b = Math.max(1, Math.ceil(days / 30));
    const buck = arr => { const out = []; for (let i = 0; i < arr.length; i += b) out.push(sum(arr.slice(i, i + b))); return out; };
    const bc = buck(se.checks), bo = buck(se.orders), mx = Math.max(1, ...bc, ...bo), n = bc.length;
    const pt = (v, i) => (i / Math.max(1, n - 1) * 300).toFixed(1) + ',' + (130 - v / mx * 115).toFixed(1);
    const lab = kk => new Date(d.from * 1000 + kk * 86400000).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
    const wmx = Math.max(1, ...d.weeks.map(w => w.paid + w.open));
    const weeks = d.weeks.map((w, i) => ({ paidH: (w.paid / wmx * 100) + '%', openH: (w.open / wmx * 100) + '%', tip: 'Week ' + (i + 1) + ': ' + fmt(w.paid) + ' paid, ' + fmt(w.open) + ' open' }));
    const cEntries = Object.entries(d.countries), cTotal = sum(cEntries.map(e => e[1])) || 1;
    const top = cEntries.slice(0, 5), rest = sum(cEntries.slice(5).map(e => e[1]));
    const cList = (rest ? [...top, ['Other', rest]] : top).map(([label, v]) => [label, Math.round(v / cTotal * 100)]);
    const cMax = Math.max(1, ...cList.map(c => c[1]));
    const countries = cList.map(([label, p]) => ({ label, pct: p + '%', w: (p / cMax * 100) + '%' }));
    const srcNames = { google: 'Google search', blog: 'Blog articles', direct: 'Direct', instagram: 'Instagram', other: 'Other' }, sTotal = sum(Object.values(d.sources)) || 1;
    const sources = Object.entries(d.sources).sort((x, y) => y[1] - x[1]).map(([kk, v]) => ({ label: srcNames[kk] || kk, n: v.toLocaleString('en-US'), pct: Math.round(v / sTotal * 100) + '%' }));
    const rv = [['Submitted', d.reviews.submitted, '#EEEEEE', '#D2D2D2'], ['In progress', d.reviews.in_progress, '#9E9E9E', '#9E9E9E'], ['Removed', d.reviews.removed, '#151515', '#151515'], ['Not eligible', d.reviews.not_eligible, '#D93025', '#D93025']];
    const rt = sum(rv.map(r => r[1])); let acc = 0;
    const donut = rv.map(([label, nn, col, bd]) => { const p = rt ? nn / rt * 100 : 0, off = 100 - acc; acc += p; return { label, n: nn, col, bd, dash: p.toFixed(2) + ' ' + (100 - p).toFixed(2), off: off.toFixed(2) }; });
    const ago = t => { const m = Math.round((Date.now() / 1000 - t) / 60); return m < 60 ? m + ' min ago' : m < 1440 ? Math.round(m / 60) + ' h ago' : Math.round(m / 1440) + ' d ago'; };
    const checked = d.recent.map(c => ({ name: c.name, country: c.country || '—', rating: c.rating != null ? Number(c.rating).toFixed(1) : '—', count: c.count, low: c.low, step: c.reached, ordered: c.ordered, hot: !c.ordered,
      stepCol: c.ordered ? '#151515' : '#555', stepW: c.ordered ? 600 : 400, bg: c.ordered ? 'transparent' : '#FFF7F6', profile: c.mapsUrl, when: ago(c.t) }));
    return { empty: false, has: true, periodLabel: (range ? 'Last ' + range + ' days' : 'All time'), kpis, funnel,
      worstLabel: worstDrop > 0 ? steps[worst - 1][0] + ' → ' + steps[worst][0] + ' (' + Math.round(worstDrop * 100) + '%)' : '—',
      lineChecks: bc.map(pt).join(' '), lineOrders: bo.map(pt).join(' '), lineArea: 'M0,140 L' + bc.map(pt).join(' L') + ' L300,140 Z',
      lineLabels: [0, .33, .66, 1].map(f => lab(Math.round(f * (days - 1)))), weeks, barGap: weeks.length > 8 ? '4px' : '8px', weekFirst: 'W1', weekLast: 'W' + weeks.length,
      weekTotal: fmt(sum(d.weeks.map(w => w.paid))) + ' paid', countries, sources, donut, revTotal: rt, avgDays: d.avgDays == null ? '—' : d.avgDays.toFixed(1), checked, ...serpOf(d.serp) };
  }

  renderVals() {
    const s = this.state, v = this._designRenderVals();
    const od = s.orders.find(o => o.id === s.openId);
    const cur = od && od.currency === 'USD' ? '$' : '€', money = n => cur + Number(n).toLocaleString('en-US');
    Object.assign(v, {
      isLogin: s.screen === 'login', isAdmin: s.screen === 'admin', isCustDash: false, isEmail: false,
      adminInitial: (s.adminEmail || 'A')[0].toUpperCase(),
      doLogin: () => this.login(), onLoginKey: e => { if (e.key === 'Enter') this.login(); },
      logout: () => { this.api('admin-logout', { body: {} }); this.setState({ screen: 'login', authed: false, loginPass: '', orders: [], view: 'orders', openId: null }); },
      goAnalytics: () => { this.setState({ view: 'analytics', openId: null }); this.loadAnalytics(); window.scrollTo({ top: 0 }); },
      saveSettings: () => this.api('admin-settings', { body: { partnerNo: s.partnerNo, template: s.template, sender: s.sender } }).then(r => r.ok ? this.toast('Settings saved') : this.fail(r)),
      stripeLabel: s.stripe.connected ? 'Connected' : 'Not connected', stripeAcct: s.stripe.account ? s.stripe.account.slice(0, 5) + '…' + s.stripe.account.slice(-4) : 'add key on server', stripeDot: s.stripe.connected ? '#151515' : '#D93025',
      loginHint: s.notConfigured ? 'Admin login is not configured on the server yet.' : ''
    });
    if (od) {
      const busy = fnName => { if (this._busy) return true; this._busy = fnName; setTimeout(() => { this._busy = null; }, 1500); return false; };
      Object.assign(v, {
        sendMsg: () => { const t = s.draft.trim(); if (!t) return; this.setState({ draft: '' });
          this.api('admin-message', { body: { order: od.id, text: t } }).then(r => r.ok ? (this.replaceOrder(r.order), this.toast('Sent to customer dashboard & email')) : this.fail(r, 'Message not sent')); },
        sendPayLink: () => { if (busy('pay')) return; this.toast('Creating payment link…');
          this.api('admin-paylink', { body: { order: od.id } }).then(r => r.ok ? (this.replaceOrder(r.order), this.toast('Payment link created · emailed to customer')) : this.fail(r)); },
        resendLink: () => { if (busy('resend')) return; this.api('admin-paylink', { body: { order: od.id, resend: true } }).then(r => r.ok ? (this.replaceOrder(r.order), this.toast('Payment email resent')) : this.fail(r)); },
        markPaid: () => { if (!confirm('Mark ' + od.id + ' as paid?')) return; this.api('admin-markpaid', { body: { order: od.id } }).then(r => r.ok ? (this.replaceOrder(r.order), this.toast('Marked as paid')) : this.fail(r)); },
        orderReason: s.orderReason || '', onOrderReason: e => this.setState({ orderReason: e.target.value }),
        orderNotify: s.orderNotify !== false, onOrderNotify: e => this.setState({ orderNotify: e.target.checked }),
        cancelOrder: () => { if (!confirm('Cancel ' + od.id + '? All open reviews stop.')) return;
          this.api('admin-cancel', { body: { order: od.id, reason: s.orderReason || '', notify: s.orderNotify !== false } }).then(r => r.ok ? (this.replaceOrder(r.order), this.setState({ orderReason: '' }), this.toast('Order cancelled' + (s.orderNotify !== false ? ' · customer notified' : ''))) : this.fail(r, 'Could not cancel')); },
        deleteOrder: () => { if (!confirm('Delete ' + od.id + '? It disappears from the list and the customer\'s account.')) return;
          this.api('admin-delete', { body: { order: od.id, reason: s.orderReason || '', notify: s.orderNotify !== false } }).then(r => { if (!r.ok) return this.fail(r, 'Could not delete');
            this.setState(st => ({ orders: st.orders.filter(x => x.id !== od.id), view: 'orders', openId: null, orderReason: '' })); this.toast('Order deleted' + (s.orderNotify !== false ? ' · customer notified' : '')); }); },
        sendPw: () => { if (!confirm('Send a new password to ' + od.cust.email + '?')) return; this.api('admin-resetpw', { body: { order: od.id } }).then(r => r.ok ? this.toast('New password emailed to ' + r.email) : this.fail(r)); }
      });
      // "Send all" = every review that is still active (submitted or already in progress), with its link
      const active = od.reviews.filter(r => r.status === 'submitted' || r.status === 'in_progress');
      v.sendAllWa = () => this.sendWa(od, active);
      if (v.o) {
        v.o.hasOpen = active.length > 0;
        const c = this.calc(od);
        Object.assign(v.o, { canCancel: !od.cancelled, cancelled: !!od.cancelled,
          cancelledInfo: od.cancelled ? (od.cancelledAt ? this.fmtD(od.cancelledAt) : '') + ' · by ' + (od.cancelledBy === 'customer' ? 'customer' : 'team') + (od.cancelReason ? ' – ' + od.cancelReason : '') : '',
          subtotal: money(c.sub), discAmt: '– ' + money(c.disc), total: money(c.total),
          mailto: 'mailto:' + od.cust.email + '?subject=' + encodeURIComponent('Your order ' + od.id), waCustomer: 'https://wa.me/' + (od.cust.phone || '').replace(/[^\d]/g, ''), hasPhone: !!(od.cust.phone || '').replace(/[^\d]/g, ''),
          paidVia: od.payment.via === 'manual' ? 'marked manually' : 'via Stripe' });
        v.o.reviews = v.o.reviews.map(r => ({ ...r, price: money(r.age === 'recent' ? 90 : 125),
          canQuick: r.canQuick && r.status !== 'cancelled',
          textShown: !r.text ? (r.status === 'cancelled' ? 'Cancelled · ' : '') + 'Submitted by link – open it on Google' : (r.status === 'cancelled' ? 'Cancelled · ' : '') + r.textShown }));
      }
    }
    return v;
  }
