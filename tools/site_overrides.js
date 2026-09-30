  // ===== byereviews production layer (injected by tools/build_site.py) =====
  // Replaces the prototype's simulated data with the real backend (/order.php?a=…) and gives every view a real URL.

  api(action, opts = {}) {
    const q = opts.query ? '&' + new URLSearchParams(opts.query).toString() : '';
    const init = opts.body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(opts.body) } : { credentials: 'same-origin' };
    return fetch('/order.php?a=' + action + q, init).then(r => r.json().catch(() => ({ ok: false, error: 'bad_response' }))).catch(() => ({ ok: false, error: 'network' }));
  }
  state = Object.assign({}, this.state, { places: [], placeReviews: [], reviewsStatus: 'idle', reviewsComplete: true, emailKnown: false,
    customer: null, orders: [], orderIdx: 0, submitting: false, submitError: '', loginBusy: false, resetSent: false });

  // ---------- routing: / · /order/ · /login/ · /dashboard/ ----------
  paths = { home: '/', order: '/order/', success: '/order/', login: '/login/', portal: '/dashboard/' };
  viewFromPath() {
    const p = location.pathname;
    if (p.startsWith('/order')) return 'order';
    if (p.startsWith('/login')) return 'login';
    if (p.startsWith('/dashboard')) return 'portal';
    return 'home';
  }
  componentDidMount() {
    this._designDidMount();
    const pre = document.getElementById('prerender'); if (pre) pre.remove();
    const hash = (location.hash || '').slice(1);
    let v = this.viewFromPath();
    if (v === 'home' && hash === 'order') v = 'order';
    if (v === 'home' && hash === 'login') v = 'login';
    const want = v;
    this._lastView = v === 'portal' ? 'login' : v;
    this.setState({ view: this._lastView });
    if (v === 'home' && /^[a-z]+$/.test(hash)) setTimeout(() => this.scrollToId(hash), 80);
    this.onHashNav = () => { const h = (location.hash || '').slice(1); if (h === 'order' || h === 'login') this.setState({ view: h }); else if (/^[a-z]+$/.test(h)) this.setState({ view: 'home' }, () => setTimeout(() => this.scrollToId(h), 60)); };
    window.addEventListener('hashchange', this.onHashNav);
    this.onPop = () => { const pv = this.viewFromPath(); const nv = pv === 'portal' && !this.state.portalIn ? 'login' : pv; this._lastView = nv; this.setState({ view: nv, menuOpen: false }); };
    window.addEventListener('popstate', this.onPop);
    this.api('me').then(r => {
      if (!r.ok) return;
      this.setState({ customer: r.customer, orders: r.orders || [], portalIn: true, loginEmail: r.customer.email,
        view: (want === 'portal' || want === 'login') ? 'portal' : this.state.view });
    });
  }
  scrollToId(id) { const el = document.getElementById(id); if (el) el.scrollIntoView(); }
  componentDidUpdate() {
    const v = this.state.view;
    if (v === this._lastView) return;
    this._lastView = v;
    const path = this.paths[v] || '/';
    if (location.pathname !== path || location.hash) history.pushState(null, '', path);
    const titles = { order: 'Remove a Google review', login: 'Log in', portal: 'Your dashboard', success: 'Order received' };
    document.title = titles[v] ? titles[v] + ' – byereviews' : this.homeTitle || document.title;
  }
  homeTitle = typeof document !== 'undefined' ? document.title : '';

  // ---------- hero video: keep it looping (the runtime drops the loop attribute) ----------
  vidRef = el => {
    if (!el) return;
    el.muted = true; el.defaultMuted = true; el.playsInline = true; el.loop = true; el.autoplay = true;
    el.setAttribute('muted', ''); el.setAttribute('playsinline', ''); el.setAttribute('loop', '');
    el.playbackRate = 0.6; el.defaultPlaybackRate = 0.6;
    if (el.__loopBound) return; el.__loopBound = true;
    const play = () => { if (!el.isConnected) return; el.playbackRate = 0.6; const p = el.play(); if (p && p.catch) p.catch(() => {}); };
    el.onplay = () => { el.playbackRate = 0.6; };
    el.addEventListener('ended', () => { el.currentTime = 0; play(); });
    el.addEventListener('pause', () => { if (!document.hidden) setTimeout(play, 50); });
    el.addEventListener('stalled', play); el.addEventListener('canplay', () => { if (el.paused) play(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden && el.paused) play(); });
    const kick = () => { if (el.paused) play(); window.removeEventListener('touchstart', kick); window.removeEventListener('scroll', kick); };
    window.addEventListener('touchstart', kick, { passive: true }); window.addEventListener('scroll', kick, { passive: true });
    play();
  };

  // ---------- step 1: real Google business search ----------
  findBiz() {
    const q = (this.state.bizQuery || '').trim(); if (!q) return this.setState({ tried: true });
    this.setState({ bizStatus: 'loading', places: [], biz: null });
    this.api('places', { query: { q } }).then(r => {
      if (!r.ok || !(r.places || []).length) return this.setState({ bizStatus: 'notfound', biz: null, searchError: !r.ok });
      this.pickPlace(r.places[0], r.places);
    });
  }
  pickPlace(p, list) {
    this.setState({ bizStatus: 'found', biz: { ...p }, places: list || this.state.places, selected: {}, placeReviews: [], reviewsStatus: 'loading', company: '', street: '', city: '', phone: '' }, () => this.autofill());
    this.api('reviews', { query: { place: p.id } }).then(r => {
      if (!this.state.biz || this.state.biz.id !== p.id) return;
      this.setState(r.ok ? { placeReviews: r.reviews || [], reviewsStatus: 'done', reviewsComplete: !!r.complete } : { placeReviews: [], reviewsStatus: 'error', reviewsComplete: false, showManual: true });
    });
  }
  poolReviews() { return (this.state.placeReviews || []).map(r => ({ id: r.id, name: r.name, stars: r.stars, days: r.days, text: r.text, link: r.link })); }

  // Pasted review links: no fake preview – the customer picks the age tier.
  lookup(id, value) { this.upd(id, { value, status: 'idle', preview: null }); }

  // ---------- step 3: does this email already have an account? ----------
  checkEmail(email) {
    clearTimeout(this.emailT);
    if (!/\S+@\S+\.\S+/.test(email)) return this.setState({ emailKnown: false });
    this.emailT = setTimeout(() => this.api('check-email', { query: { email } }).then(r => { if (this.state.email === email) this.setState({ emailKnown: !!(r.ok && r.exists) }); }), 450);
  }

  // ---------- submit ----------
  submitOrder() {
    const s = this.state;
    if (s.submitting) return;
    const reviews = [
      ...this.selectedList().map(p => ({ source: 'profile', googleId: p.id, author: p.name, stars: p.stars, days: p.days, text: p.text })),
      ...s.reviews.filter(r => this.valid(r)).map(r => ({ source: 'link', link: this.isUrl(r.value) ? r.value.trim() : '', text: this.isUrl(r.value) ? '' : r.value.trim(), author: r.name || '', tier: r.age === 'older' ? 'older' : 'recent' }))
    ];
    const body = { agree: !!s.agree, reviews,
      business: { placeId: s.biz && s.biz.id || '', name: s.biz && s.biz.name || s.company || '', query: s.bizQuery || '', profileUrl: s.profileUrl || '' },
      contact: { name: s.name, email: s.email, company: s.company, phone: s.phone, street: s.street, city: s.city, country: s.country } };
    this.setState({ submitting: true, submitError: '' });
    this.api('order', { body }).then(r => {
      if (!r.ok) {
        const msg = { too_many_requests: 'Too many orders from your connection – please try again later.', no_reviews: 'Please add at least one review with text.', invalid_email: 'Please enter a valid email address.' }[r.error] || 'Something went wrong – please try again or email info@byereviews.com.';
        return this.setState({ submitting: false, submitError: msg, tried: true });
      }
      this.setState({ submitting: false, submitted: true, view: 'success', confettiKey: Date.now(), orderId: r.orderId, newAccount: !!r.newAccount, orderedAt: Date.now(), loginEmail: s.email });
      window.scrollTo({ top: 0 });
    });
  }

  // ---------- login / dashboard ----------
  doLogin() {
    const s = this.state;
    if (s.loginBusy) return;
    this.setState({ loginBusy: true, loginError: false });
    this.api('login', { body: { email: (s.loginEmail || '').trim(), password: s.loginPass || '' } }).then(r => {
      if (!r.ok) return this.setState({ loginBusy: false, loginError: true, loginErrorText: r.error === 'too_many_requests' ? 'Too many attempts – please wait a few minutes.' : "Email or password doesn't match." });
      this.setState({ loginBusy: false, customer: r.customer, orders: r.orders || [], orderIdx: 0, portalIn: true, portalTab: 'overview', view: 'portal', loginPass: '' });
      window.scrollTo({ top: 0 });
    });
  }
  doReset() {
    const email = (this.state.loginEmail || '').trim();
    if (!/\S+@\S+\.\S+/.test(email)) return this.setState({ loginError: true, loginErrorText: 'Enter your email above first.' });
    this.api('reset', { body: { email } }).then(() => this.setState({ resetSent: true, loginError: false }));
  }
  realPortal() {
    const s = this.state, o = (s.orders || [])[s.orderIdx || 0];
    if (!s.customer || !o) return null;
    const cur = o.currency, fmt = n => cur === 'EUR' ? `${Number(n).toLocaleString('en-US')} €` : `$${Number(n).toLocaleString('en-US')}`;
    const stMap = { submitted: 'submitted', in_progress: 'progress', removed: 'removed', not_eligible: 'cancelled' };
    const paid = o.payment.status === 'paid';
    const look = { removed: ['✓ Removed', '#151515', '#FFFFFF', '#151515'], progress: ['In progress', '#FFFFFF', '#151515', '#151515'], submitted: ['Submitted', '#EFEFEF', '#555', '#EFEFEF'], cancelled: ['Not eligible', '#FFFFFF', '#8A8A8A', '#D2D2D2'] };
    const items = o.reviews.map(r => {
      const st = stMap[r.status] || 'submitted', price = r.tier === 'older' ? 125 : 90, m = look[st];
      return { initial: (r.author || 'R')[0], name: r.author || 'Review', stars: r.stars || '–', when: r.stars ? this.whenFor(r.days) : (r.tier === 'older' ? 'older than 4 weeks' : 'last 4 weeks'),
        text: r.text || r.link || 'Review link', st, price,
        textCol: st === 'removed' ? '#9E9E9E' : st === 'cancelled' ? '#8A8A8A' : '#333', deco: st === 'removed' ? 'line-through' : 'none',
        status: m[0], badgeBg: m[1], badgeFg: m[2], badgeBd: m[3],
        priceNote: st === 'removed' ? (paid ? 'Paid ' + fmt(price) : fmt(price) + ' due') : st === 'cancelled' ? 'Cancelled · free' : fmt(price) + ' if removed' };
    });
    const inv = o.invoice, removed = items.filter(i => i.st === 'removed'), inProg = items.filter(i => i.st === 'progress').length;
    const tl = o.timeline || {}, fd = iso => iso ? new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '—';
    const phase = paid && removed.length ? 4 : removed.length ? 3 : (inProg || tl.review) ? 2 : 1;
    const msgs = o.messages.map(m => ({ text: m.text, justify: m.from === 'me' ? 'flex-end' : 'flex-start', bg: m.from === 'me' ? '#151515' : '#F4F4F4', fg: m.from === 'me' ? '#FFFFFF' : '#151515',
      meta: (m.from === 'me' ? 'You' : 'byereviews team') + ' · ' + new Date(m.at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) }));
    const tab = s.portalTab || 'overview', lastTeam = o.messages.filter(m => m.from === 'team').length, unread = tab !== 'support' && lastTeam > (s.seenTeamMsgs || 1);
    const goTab = t => { this.setState({ portalTab: t, seenTeamMsgs: t === 'support' ? lastTeam : s.seenTeamMsgs }); window.scrollTo({ top: 0 }); };
    const send = () => { const t = (s.supportDraft || '').trim(); if (!t) return; this.setState({ supportDraft: '' });
      this.api('message', { body: { order: o.id, text: t } }).then(r => { if (r.ok) this.setState({ orders: r.orders }); }); };
    const pay = () => { location.href = '/order.php?a=pay&order=' + encodeURIComponent(o.id); };
    const rb = o.business.ratingBefore, rn = o.business.ratingNow;
    return {
      pOrderId: o.id, pBiz: o.business.name || 'Your business', pFirst: (s.customer.name || '').split(' ')[0] || 'there', pEmail: s.customer.email,
      pRemoved: removed.length, pTotal: items.filter(i => i.st !== 'cancelled').length, pProgress: inProg,
      pRatingBefore: rb != null ? Number(rb).toFixed(1) : '–', pRatingNow: rn != null ? Number(rn).toFixed(1) : '–',
      pDue: fmt(paid ? 0 : inv.total), pPayLabel: paid ? 'Nothing due' : inv.total > 0 ? 'Due now' + (inv.rate > 0 ? ' · incl. volume discount' : '') : 'Nothing due yet',
      pCanPay: !!o.payment.canPay, pPaid: paid && removed.length > 0, payNow: pay,
      pSteps: [['Order received', fd(tl.received || o.createdAt)], ['In review', phase >= 2 ? fd(tl.review || tl.received) : '—'], ['Removed', phase >= 3 ? fd(tl.removed) : '—'], ['Paid', phase >= 4 ? fd(tl.paid) : '—']]
        .map(([label, date], i) => ({ label, date, bar: i < phase ? '#151515' : '#E4E4E4', fg: i < phase ? '#151515' : '#8A8A8A' })),
      pReviews: items,
      pActive: items.filter(i => i.st === 'progress' || i.st === 'submitted'), pDone: items.filter(i => i.st === 'removed' || i.st === 'cancelled'),
      pActiveCount: items.filter(i => i.st === 'progress' || i.st === 'submitted').length, pDoneCount: items.filter(i => i.st === 'removed' || i.st === 'cancelled').length,
      pActiveEmpty: !items.some(i => i.st === 'progress' || i.st === 'submitted'), pDoneEmpty: !items.some(i => i.st === 'removed' || i.st === 'cancelled'),
      pHasInvoice: removed.length > 0, pInvoiceNo: 'INV-' + o.id.replace('BR-', ''), pDueDate: fd(tl.removed),
      pLines: removed.map(i => ({ label: i.name + ' · ' + (i.price === 90 ? '≤ 4 weeks' : '> 4 weeks'), amount: fmt(i.price) })),
      pHasDisc: inv.rate > 0, pDiscPct: Math.round(inv.rate * 100) + '%', pDiscAmt: '– ' + fmt(inv.discount), pInvTotal: fmt(inv.total),
      pMsgs: msgs, sendSupport: send, onSupportKey: e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } },
      pTabOverview: tab === 'overview', pTabSupport: tab === 'support', pShowChatFab: tab !== 'support', pUnread: unread, openSupport: () => goTab('support'),
      pTabs: [['overview', 'Overview'], ['support', 'Support']].map(([k, label]) => ({ label, go: () => goTab(k), bg: tab === k ? '#151515' : 'transparent', fg: tab === k ? '#FFFFFF' : '#555', hasDot: k === 'support' && unread, dot: '1', dotBg: '#151515', dotFg: '#FFFFFF' })),
      hasOrderSwitch: s.orders.length > 1,
      orderChips: s.orders.map((x, i) => ({ label: x.id, go: () => this.setState({ orderIdx: i }), bg: i === (s.orderIdx || 0) ? '#151515' : '#FFFFFF', fg: i === (s.orderIdx || 0) ? '#FFFFFF' : '#555' })),
      logout: () => { this.api('logout', { body: {} }); this.setState({ portalIn: false, customer: null, orders: [], view: 'login', loginPass: '' }); window.scrollTo({ top: 0 }); }
    };
  }

  renderVals() {
    const s = this.state, v = this._designRenderVals();
    const ok = this.stepValid(s.step);
    // no Desktop/Mobile preview switcher on the live site
    Object.assign(v, { showSwitch: false, showFrame: false, showSite: true });
    const designNext = v.next;
    Object.assign(v, {
      next: () => (s.step === 4 && ok && !s.submitted) ? this.submitOrder() : designNext(),
      nextLabel: s.step === 4 ? (s.submitting ? 'Submitting…' : 'Submit order') : 'Continue',
      error: s.submitError || v.error, hasError: !!s.submitError || v.hasError, navInfo: s.submitError || v.navInfo,
      emailKnown: !!s.emailKnown,
      onEmail: e => { const email = e.target.value; this.setState({ email }); this.checkEmail(email.trim()); },
      loginErrorText: s.loginErrorText || "Email or password doesn't match.",
      loginBtn: s.loginBusy ? 'Logging in…' : 'Log in',
      doReset: () => this.doReset(), resetLabel: s.resetSent ? '✓ If the email has an account, a new password is on its way' : 'Forgot your password?',
      openPortalAuto: () => { this.setState({ view: s.portalIn ? 'portal' : 'login', loginEmail: s.email, loginPass: '', loginError: false }); window.scrollTo({ top: 0 }); },
      successText: s.newAccount ? "We've created your personal dashboard and emailed your login details to " + s.email + '.' : 'This order was added to your existing account (' + s.email + '). Log in with your existing password.',
      reviewsLoading: s.reviewsStatus === 'loading', reviewsPartial: s.reviewsStatus === 'done' && !s.reviewsComplete, reviewsFailed: s.reviewsStatus === 'error',
      noResults: v.noResults && s.reviewsStatus !== 'loading',
      hasAlts: s.bizStatus === 'found' && (s.places || []).length > 1,
      altPlaces: (s.places || []).filter(p => !s.biz || p.id !== s.biz.id).map(p => ({ name: p.name, addr: p.address, go: () => this.pickPlace(p) })),
      bizNfTitle: s.searchError ? 'Search is unavailable right now' : 'No profile found'
    });
    const real = this.realPortal();
    if (real) Object.assign(v, real);
    else Object.assign(v, { hasOrderSwitch: false, orderChips: [], pOrderId: '', pBiz: '', pFirst: 'there', pEmail: '' });
    return v;
  }
