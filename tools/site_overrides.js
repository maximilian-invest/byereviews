  // ===== byereviews production layer (injected by tools/build_site.py) =====
  // Replaces the prototype's simulated data with the real backend (/order.php?a=…) and gives every view a real URL.

  api(action, opts = {}) {
    const q = opts.query ? '&' + new URLSearchParams(opts.query).toString() : '';
    const init = opts.body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(opts.body) } : { credentials: 'same-origin' };
    return fetch('/order.php?a=' + action + q, init).then(r => r.json().catch(() => ({ ok: false, error: 'bad_response' }))).catch(() => ({ ok: false, error: 'network' }));
  }
  state = Object.assign({}, this.state, { places: [], placeReviews: [], reviewsStatus: 'idle', reviewsComplete: true, emailKnown: false,
    customer: null, orders: [], orderIdx: 0, submitting: false, submitError: '', loginBusy: false, resetSent: false,
    account: null, rsToken: '', rsEmail: '' });

  // ---------- funnel tracking (first-party, no cookies, no IP stored; see app/analytics.php) ----------
  track(ev, extra) {
    try {
      const ss = window.sessionStorage;
      let sid = ss.getItem('br_sid'); if (!sid) { sid = (crypto.randomUUID ? crypto.randomUUID() : String(Math.random()).slice(2) + Date.now()).replace(/-/g, ''); ss.setItem('br_sid', sid); }
      const once = ev !== 'profile';
      if (once) { const done = JSON.parse(ss.getItem('br_ev') || '[]'); if (done.includes(ev)) return; done.push(ev); ss.setItem('br_ev', JSON.stringify(done)); }
      let src = ss.getItem('br_src');
      if (!src) {
        const u = new URLSearchParams(location.search), utm = (u.get('utm_source') || '').toLowerCase(), ref = document.referrer || '';
        src = /instagram/.test(utm + ref) ? 'instagram' : /google\./.test(ref) || utm === 'google' ? 'google' : /byereviews\.com\/blog\//.test(ref) || (ref && new URL(ref).host === location.host && ref.includes('/blog/')) ? 'blog' : !ref || new URL(ref).host === location.host ? 'direct' : 'other';
        ss.setItem('br_src', src);
      }
      const region = ((navigator.language || '').split('-')[1] || '').toUpperCase();
      fetch('/order.php?a=track', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ sid, ev, src, region, ...(extra || {}) }), keepalive: true }).catch(() => {});
    } catch (e) {}
  }

  // ---------- routing: / · /order/ · /login/ · /dashboard/ ----------
  paths = { home: '/', order: '/order/', success: '/order/', login: '/login/', forgot: '/login/', reset: '/login/', portal: '/dashboard/' };
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
    try { if (!sessionStorage.getItem('br_src')) { this.track('__init'); } } catch (e) {}
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
    const qs = new URLSearchParams(location.search);
    const rt = qs.get('reset');
    if (rt) { this.setState({ rsToken: rt, rsState: 'form', view: 'reset' }); this.api('reset-check', { query: { t: rt } }).then(r => this.setState(r.ok ? { rsEmail: r.email || '' } : { rsState: 'expired', rsResend: 'idle' })); }
    if (qs.has('forgot')) this.setState({ view: 'forgot', fpState: 'form' });
    const em = qs.get('email');
    if (em) this.setState({ accNotice: em === 'changed' ? '✓ Your new email address is confirmed.' : 'That confirmation link is invalid or has expired.' });
    this.api('me').then(r => {
      if (!r.ok) return;
      this.setAccount(r.account);
      this.setState({ customer: r.customer, orders: r.orders || [], portalIn: true, loginEmail: r.customer.email,
        view: (want === 'portal' || want === 'login') && !this.state.rsToken && this.state.view !== 'forgot' ? 'portal' : this.state.view });
    });
  }
  scrollToId(id) { const el = document.getElementById(id); if (el) el.scrollIntoView(); }
  componentDidUpdate() {
    const st = this.state;
    if (st.view === 'order') this.track('visit');
    if (st.view === 'order' && st.step !== this._lastStep) { this._lastStep = st.step; if (st.step >= 3) this.track('review'); if (st.step >= 4) this.track('contact'); }
    const v = this.state.view;
    if (v === this._lastView) return;
    this._lastView = v;
    const path = this.paths[v] || '/';
    if ((location.pathname !== path || location.hash) && !(v === 'reset' && location.pathname === path)) history.pushState(null, '', path);
    const titles = { order: 'Remove a Google review', login: 'Log in', forgot: 'Forgot your password?', reset: 'Set a new password', portal: 'Your dashboard', success: 'Order received' };
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
    clearTimeout(this.sugT);
    const q = (this.state.bizQuery || '').trim(); if (!q) return this.setState({ tried: true });
    this.track('search');
    this.setState({ bizStatus: 'loading', places: [], biz: null });
    this.api('places', { query: { q } }).then(r => {
      if (!r.ok || !(r.places || []).length) return this.setState({ bizStatus: 'notfound', biz: null, searchError: !r.ok });
      this.setState({ bizStatus: 'choose', places: r.places, biz: null, searchError: false });
    });
  }
  // live suggestions while typing (Places Autocomplete; one session token per search → cheap billing)
  onBizType(q) {
    this.setState({ bizQuery: q, tried: false });
    clearTimeout(this.sugT);
    if (q.trim().length >= 3) this.track('search');
    const t = q.trim();
    if (t.length < 3) { if (this.state.bizStatus === 'choose' || this.state.bizStatus === 'loading') this.setState({ bizStatus: 'idle', places: [] }); return; }
    if (!this.acSession) this.acSession = (crypto.randomUUID ? crypto.randomUUID() : String(Math.random()).slice(2) + Date.now()).replace(/-/g, '');
    this.sugT = setTimeout(() => {
      this.api('suggest', { query: { q: t, session: this.acSession } }).then(r => {
        if ((this.state.bizQuery || '').trim() !== t || this.state.bizStatus === 'found') return;
        if (!r.ok) return this.setState({ bizStatus: 'idle', places: [] });
        this.setState((r.places || []).length ? { bizStatus: 'choose', places: r.places, biz: null } : { bizStatus: 'idle', places: [] });
      });
    }, 350);
  }
  pickPlace(p, list) {
    if (p.partial) {
      // suggestion → load the full profile (rating, address, phone) before showing it as found
      this.setState({ bizStatus: 'loading' });
      const session = this.acSession; this.acSession = null;
      return this.api('place', { query: { id: p.id, session: session || '' } }).then(r => {
        if (!r.ok) return this.setState({ bizStatus: 'notfound', searchError: true });
        this.pickPlace(r.place, (list || this.state.places || []).map(x => x.id === p.id ? r.place : x));
      });
    }
    this.setState({ bizStatus: 'found', biz: { ...p }, places: list || this.state.places, selected: {}, placeReviews: [], reviewsStatus: 'loading', company: '', street: '', city: '', phone: '' }, () => this.autofill());
    this.api('reviews', { query: { place: p.id } }).then(r => {
      if (!this.state.biz || this.state.biz.id !== p.id) return;
      this.setState(r.ok ? { placeReviews: r.reviews || [], reviewsStatus: 'done', reviewsComplete: !!r.complete } : { placeReviews: [], reviewsStatus: 'error', reviewsComplete: false, showManual: true });
      this.track('profile', { place: { id: p.id, name: p.name, country: p.country, rating: p.rating, count: p.reviewCount, mapsUrl: p.mapsUrl, low: (r.reviews || []).filter(x => x.stars <= 3 && x.text).length } });
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
      this.track('submit');
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
      this.setAccount(r.account);
      this.setState({ loginBusy: false, customer: r.customer, orders: r.orders || [], orderIdx: 0, portalIn: true, portalTab: 'overview', view: 'portal', loginPass: '' });
      window.scrollTo({ top: 0 });
    });
  }
  doReset() {
    const email = (this.state.loginEmail || '').trim();
    if (!/\S+@\S+\.\S+/.test(email)) return this.setState({ loginError: true, loginErrorText: 'Enter your email above first.' });
    this.api('reset', { body: { email } }).then(() => this.setState({ resetSent: true, loginError: false }));
  }
  // ---------- account area (design: accountVals) wired to app/account.php ----------
  setAccount(a) { if (a) this.setState({ account: a, accProf: null, accSaved: null, emailMode: 'view' }); }
  accErr(r) {
    return { wrong_password: 'Wrong password.', password_too_short: 'Use at least 10 characters.', email_taken: 'This email already has an account.',
      invalid_email: 'Enter a valid email address.', same_email: "That's already your email.", too_many_requests: 'Too many attempts – please wait a few minutes.',
      name_missing: 'Required', not_logged_in: 'Your session has expired – please log in again.' }[r.error] || 'Something went wrong – please try again.';
  }
  accountVals(sw, mob) {
    const d = this._designAccountVals(sw, mob), s = this.state, a = s.account || {}, set = p => this.setState(p);
    const fromAcc = { name: a.name || '', company: a.company || '', phone: a.phone || '', street: a.street || '', zip: a.city || '', country: a.country || 'United States' };
    const prof = s.accProf || fromAcc, saved = s.accSaved || fromAcc;
    const dirty = ['name', 'company', 'phone', 'street', 'zip', 'country'].some(k => (prof[k] || '') !== (saved[k] || ''));
    const bd = e => e ? '#151515' : '#D2D2D2', vEm = e => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((e || '').trim());
    const expired = r => { if (r.error === 'not_logged_in') { this.setState({ portalIn: false, customer: null, view: 'login' }); this.accToast(this.accErr(r)); return true; } return false; };
    const tab = s.portalTab || 'overview', settings = tab === 'settings';
    const setTab = t => { this.setState({ portalTab: t, view: 'portal', accMenu: false, seenTeamMsgs: t === 'support' ? 999 : s.seenTeamMsgs }); window.scrollTo({ top: 0 }); };
    const em = s.emailMode === 'edit' ? 'edit' : a.pendingEmail ? 'pending' : 'view';
    const first = (a.name || (s.customer && s.customer.name) || 'there').split(' ')[0];
    const today = iso => new Date(iso || Date.now()).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
    const logout = () => { this.api('logout', { body: {} }); this.setState({ view: 'login', portalIn: false, customer: null, orders: [], account: null, loginPass: '', accMenu: false }); window.scrollTo({ top: 0 }); };
    const profFields = d.profFields.map((f, i) => { const k = ['name', 'company', 'phone', 'street', 'zip'][i], req = ['name', 'street', 'zip'].includes(k), err = !!s.profErr && req && !String(prof[k] || '').trim();
      return { ...f, value: prof[k] || '', err, bd: bd(err), on: e => set({ accProf: { ...prof, [k]: e.target.value }, accSaved: saved, profErr: false }) }; });
    return Object.assign(d, {
      name: a.name || 'Your account', initial: ((a.name || '?').trim()[0] || '?').toUpperCase(), email: a.email || '',
      menu: d.menu.map(m => m.label === 'Log out' ? { ...m, go: logout } : m.label === 'Settings' ? { ...m, go: () => setTab('settings') } : { ...m, go: () => setTab('overview') }),
      goDash: e => { e && e.preventDefault && e.preventDefault(); if (!this.state.portalIn) return this.setState({ view: 'login' }); setTab('overview'); },
      eyebrow: settings ? '// account · ' + (a.email || '') : '// order ' + (sw.pOrderId || '') + ' · ' + (sw.pBiz || ''),
      h1a: settings ? 'Your' : 'Hi ' + first + ',', h1b: settings ? 'settings.' : "here's your status.",
      // profile
      profFields, country: prof.country || 'United States', onCountry: e => set({ accProf: { ...prof, country: e.target.value }, accSaved: saved }),
      countries: d.countries.includes(prof.country) || !prof.country ? d.countries : [prof.country, ...d.countries],
      profDirty: dirty && !s.profBusy,
      saveProfile: () => { if (s.profBusy) return; if (['name', 'street', 'zip'].some(k => !String(prof[k] || '').trim())) return set({ profErr: true }); set({ profBusy: true });
        this.api('account-profile', { body: { name: prof.name, company: prof.company, phone: prof.phone, street: prof.street, city: prof.zip, country: prof.country } }).then(r => {
          this.setState({ profBusy: false }); if (!r.ok) return expired(r) || this.accToast(this.accErr(r));
          this.setAccount(r.account); this.setState(st => ({ customer: st.customer ? { ...st.customer, name: r.account.name } : st.customer })); this.accToast('Profile saved'); }); },
      // email
      emailView: em === 'view', emailEdit: em === 'edit', emailPending: em === 'pending', pendingEmail: a.pendingEmail || '',
      startEmail: () => set({ emailMode: 'edit', newEmail: '', emailPw: '', emailErr: null }), cancelEmailEdit: () => set({ emailMode: 'view', emailErr: null }),
      saveEmail: () => { if (s.emailBusy) return; const n = (s.newEmail || '').trim().toLowerCase(); if (!vEm(n)) return set({ emailErr: 'invalid' }); set({ emailBusy: true });
        this.api('account-email', { body: { email: n, password: s.emailPw || '' } }).then(r => {
          if (!r.ok) { if (expired(r)) return; return set({ emailBusy: false, emailErr: r.error === 'wrong_password' ? 'pw' : r.error === 'email_taken' ? 'exists' : 'invalid' }); }
          this.setState({ emailBusy: false, emailMode: 'view', account: r.account, emailResent: false, emailPw: '' }); this.accToast('Confirmation link sent to ' + n); }); },
      resendEmail: () => { if (s.emailResent) return;
        if (!s.emailPw) return this.setState({ emailMode: 'edit', newEmail: a.pendingEmail, emailErr: null }, () => this.accToast('Enter your password to send the link again'));
        this.api('account-email', { body: { email: a.pendingEmail, password: s.emailPw } }).then(r => r.ok ? (set({ emailResent: true }), this.accToast('Confirmation link resent')) : (expired(r) || this.accToast(this.accErr(r)))); },
      cancelPending: () => this.api('account-email-cancel', { body: {} }).then(r => { if (!r.ok) return expired(r) || this.accToast(this.accErr(r)); this.setAccount(r.account); this.accToast('Email change cancelled'); }),
      // password
      savePw: () => { if (s.pwBusy) return; if (!s.pwCur) return set({ pwErr: 'cur' }); if ((s.pwNew || '').length < 10) return set({ pwErr: 'short' }); if (s.pwNew !== s.pwRep) return set({ pwErr: 'match' }); set({ pwBusy: true });
        this.api('account-password', { body: { current: s.pwCur, password: s.pwNew } }).then(r => {
          if (!r.ok) { if (expired(r)) return; return set({ pwBusy: false, pwErr: r.error === 'wrong_password' ? 'cur' : r.error === 'password_too_short' ? 'short' : null }); }
          set({ pwBusy: false, pwCur: '', pwNew: '', pwRep: '' }); this.accToast('Password changed – other devices were logged out'); }); },
      // sessions
      sessText: 'Lost a device or used a shared computer? Log out everywhere except here.',
      logoutAll: () => { if (s.sessBusy) return; set({ sessBusy: true });
        this.api('account-logout-all', { body: {} }).then(r => { set({ sessBusy: false }); if (!r.ok) return expired(r) || this.accToast(this.accErr(r)); this.accToast('Logged out on all other devices'); }); },
      // deletion
      delNone: !a.deletionRequestedAt, delDone: !!a.deletionRequestedAt, delDate: a.deletionRequestedAt ? today(a.deletionRequestedAt) : '',
      confirmDel: () => { if (s.delBusy) return; if (!s.delPw) return set({ delErr: true }); set({ delBusy: true });
        this.api('account-delete', { body: { password: s.delPw } }).then(r => {
          if (!r.ok) { if (expired(r)) return; return set({ delBusy: false, delErr: true }); }
          this.setState({ delBusy: false, delOpen: false, delPw: '', account: r.account }); this.accToast('Deletion requested – confirmation sent by email'); }); },
      // forgot password → reset link by email
      goForgot: e => { e && e.preventDefault && e.preventDefault(); set({ view: 'forgot', fpState: 'form', fpEmail: s.loginEmail || '', fpErr: false }); window.scrollTo({ top: 0 }); },
      sendReset: () => { const fpS = s.fpState || 'form'; if (fpS === 'loading') return; if (!vEm(s.fpEmail)) return set({ fpErr: true }); set({ fpState: 'loading' });
        this.api('reset', { body: { email: (s.fpEmail || '').trim() } }).then(r => set({ fpState: r.ok || r.error !== 'too_many_requests' ? 'sent' : 'form', fpErr: false })); },
      // set a new password (from /login/?reset=…)
      saveReset: () => { const rsS = s.rsState || 'form'; if (rsS === 'loading') return; if ((s.rsNew || '').length < 10) return set({ rsErr: 'short' }); if (s.rsNew !== s.rsRep) return set({ rsErr: 'match' }); set({ rsState: 'loading' });
        this.api('reset-confirm', { body: { token: s.rsToken, password: s.rsNew } }).then(r => {
          if (!r.ok) return set({ rsState: r.error === 'link_expired' ? 'expired' : 'form', rsErr: r.error === 'password_too_short' ? 'short' : null, rsResend: 'idle' });
          history.replaceState(null, '', '/login/');
          this.setAccount(r.account);
          this.setState({ rsState: 'done', rsNew: '', rsRep: '', rsToken: '', customer: r.customer, orders: r.orders || [], orderIdx: 0, portalIn: true, portalTab: 'overview' }); }); },
      resendReset: () => { if (s.rsResend && s.rsResend !== 'idle') return;
        const email = s.rsEmail || s.loginEmail || '';
        if (!vEm(email)) return set({ view: 'forgot', fpState: 'form', fpEmail: '' });
        set({ rsResend: 'loading' }); this.api('reset', { body: { email } }).then(() => set({ rsResend: 'sent' })); }
    });
  }

  orderStatus(x) {
    const active = x.reviews.some(r => r.status === 'submitted' || r.status === 'in_progress');
    if (x.cancelled) return ['Cancelled', '#FFFFFF', '#D93025', '#D93025'];
    if (x.payment.status === 'paid') return ['Paid', '#151515', '#FFFFFF', '#151515'];
    if (x.invoice.total > 0) return ['Payment due', '#FFFFFF', '#151515', '#151515'];
    if (active) return ['In progress', '#EFEFEF', '#151515', '#EFEFEF'];
    return ['Completed', '#EFEFEF', '#555', '#EFEFEF'];
  }
  realPortal() {
    const s = this.state, o = (s.orders || [])[s.orderIdx || 0];
    if (!s.customer || !o) return null;
    const cur = o.currency, fmt = n => cur === 'EUR' ? `${Number(n).toLocaleString('en-US')} €` : `$${Number(n).toLocaleString('en-US')}`;
    const stMap = { submitted: 'submitted', in_progress: 'progress', removed: 'removed', not_eligible: 'cancelled', cancelled: 'stopped' };
    const paid = o.payment.status === 'paid';
    const look = { removed: ['✓ Removed', '#151515', '#FFFFFF', '#151515'], progress: ['In progress', '#FFFFFF', '#151515', '#151515'], submitted: ['Submitted', '#EFEFEF', '#555', '#EFEFEF'], cancelled: ['Not eligible', '#FFFFFF', '#8A8A8A', '#D2D2D2'], stopped: ['Cancelled', '#FFFFFF', '#8A8A8A', '#D2D2D2'] };
    const items = o.reviews.map(r => {
      const st = stMap[r.status] || 'submitted', price = r.tier === 'older' ? 125 : 90, m = look[st];
      return { initial: (r.author || 'R')[0], name: r.author || 'Review', stars: r.stars || '–', when: r.stars ? this.whenFor(r.days) : (r.tier === 'older' ? 'older than 4 weeks' : 'last 4 weeks'),
        text: r.text || r.link || 'Review link', st, price,
        textCol: st === 'removed' ? '#9E9E9E' : st === 'cancelled' ? '#8A8A8A' : '#333', deco: st === 'removed' ? 'line-through' : 'none',
        status: m[0], badgeBg: m[1], badgeFg: m[2], badgeBd: m[3],
        priceNote: (r.updatedAt && new Date(r.updatedAt).toDateString() === new Date().toDateString() ? '● Updated today · ' : '') + (st === 'removed' ? (paid ? 'Paid ' + fmt(price) : fmt(price) + ' due') : st === 'cancelled' || st === 'stopped' ? 'Cancelled · free' : fmt(price) + ' if removed') };
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
      pRemoved: removed.length, pTotal: items.filter(i => i.st !== 'cancelled' && i.st !== 'stopped').length, pProgress: inProg,
      pRatingBefore: rb != null ? Number(rb).toFixed(1) : '–', pRatingNow: rn != null ? Number(rn).toFixed(1) : '–',
      pDue: fmt(paid ? 0 : inv.total), pPayLabel: paid ? 'Nothing due' : inv.total > 0 ? 'Due now' + (inv.rate > 0 ? ' · incl. volume discount' : '') : 'Nothing due yet',
      pCanPay: !!o.payment.canPay, pPaid: paid && removed.length > 0, payNow: pay,
      pSteps: [['Order received', fd(tl.received || o.createdAt)], ['In review', phase >= 2 ? fd(tl.review || tl.received) : '—'], ['Removed', phase >= 3 ? fd(tl.removed) : '—'], ['Paid', phase >= 4 ? fd(tl.paid) : '—']]
        .map(([label, date], i) => ({ label, date, bar: i < phase ? '#151515' : '#E4E4E4', fg: i < phase ? '#151515' : '#8A8A8A' })),
      pReviews: items,
      ...(() => { const mob = (s.vw || 1200) < 760, t = s.rvTab || 'all', isA = i => i.st === 'progress' || i.st === 'submitted';
        const f = { all: () => true, active: isA, done: i => !isA(i) }, list = items.filter(f[t]);
        return { rvTabs: [['all', 'All'], ['active', 'Active'], ['done', 'Done']].map(([k, label]) => ({ label, count: items.filter(f[k]).length, bg: t === k ? '#151515' : 'transparent', fg: t === k ? '#FFFFFF' : '#555', go: () => this.setState({ rvTab: k }) })),
          rvRows: list.map(i => ({ ...i, mobStars: mob ? ' · ' + i.stars + ' ★' : '', priceShort: i.st === 'cancelled' || i.st === 'stopped' ? '—' : fmt(i.price) })),
          rvEmpty: list.length === 0, rvEmptyText: t === 'done' ? 'No finished reviews yet.' : 'Nothing in progress.',
          rvCols: mob ? 'minmax(0,1fr) auto' : 'minmax(150px,1fr) 64px minmax(0,2.4fr) 96px 132px', rvGap: mob ? '8px 12px' : '16px' }; })(),
      pActive: items.filter(i => i.st === 'progress' || i.st === 'submitted'), pDone: items.filter(i => i.st === 'removed' || i.st === 'cancelled' || i.st === 'stopped'),
      pActiveCount: items.filter(i => i.st === 'progress' || i.st === 'submitted').length, pDoneCount: items.filter(i => i.st === 'removed' || i.st === 'cancelled' || i.st === 'stopped').length,
      pActiveEmpty: !items.some(i => i.st === 'progress' || i.st === 'submitted'), pDoneEmpty: !items.some(i => i.st === 'removed' || i.st === 'cancelled' || i.st === 'stopped'),
      pHasInvoice: removed.length > 0, pInvoiceNo: 'INV-' + o.id.replace('BR-', ''), pDueDate: fd(tl.removed),
      pLines: removed.map(i => ({ label: i.name + ' · ' + (i.price === 90 ? '≤ 4 weeks' : '> 4 weeks'), amount: fmt(i.price) })),
      pHasDisc: inv.rate > 0, pDiscPct: Math.round(inv.rate * 100) + '%', pDiscAmt: '– ' + fmt(inv.discount), pInvTotal: fmt(inv.total),
      pMsgs: msgs, sendSupport: send, onSupportKey: e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } },
      pTabOverview: tab === 'overview', pTabSupport: tab === 'support', pShowChatFab: tab === 'overview', pUnread: unread, openSupport: () => goTab('support'),
      pTabSettings: tab === 'settings', pNotSettings: tab !== 'settings',
      pCancelled: !!o.cancelled, pCancelledAt: o.cancelledAt ? new Date(o.cancelledAt).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '',
      pCanCancel: !o.cancelled && items.some(i => i.st === 'submitted' || i.st === 'progress'),
      cancelOpen: !!s.cancelOpen, cancelClosed: !s.cancelOpen, openCancel: () => this.setState({ cancelOpen: true }), closeCancel: () => this.setState({ cancelOpen: false, cancelReason: '' }),
      cancelReason: s.cancelReason || '', onCancelReason: e => this.setState({ cancelReason: e.target.value }),
      confirmCancel: () => { if (!confirm('Cancel order ' + o.id + '?')) return;
        this.api('order-cancel', { body: { order: o.id, reason: s.cancelReason || '' } }).then(r => { if (!r.ok) return alert(r.error === 'already_cancelled' ? 'This order is already cancelled.' : 'Could not cancel – please try again.');
          this.setState({ orders: r.orders, cancelOpen: false, cancelReason: '', accNotice: 'Order ' + o.id + ' was cancelled – we sent you a confirmation.' }); window.scrollTo({ top: 0 }); }); },
      pTabs: [['overview', 'Overview'], ['support', 'Support'], ['settings', 'Settings']].map(([k, label]) => ({ label, go: () => goTab(k), bg: tab === k ? '#151515' : 'transparent', fg: tab === k ? '#FFFFFF' : '#555', hasDot: k === 'support' && unread, dot: '1', dotBg: '#151515', dotFg: '#FFFFFF' })),
      ...(() => { const mob = (s.vw || 1200) < 760, sel = s.orderIdx || 0;
        const fmtFor = x => n => x.currency === 'EUR' ? `${Number(n).toLocaleString('en-US')} €` : `$${Number(n).toLocaleString('en-US')}`;
        const st = this.orderStatus(o);
        return {
          ordCount: s.orders.length === 1 ? '1 order' : s.orders.length + ' orders', ordWide: !mob,
          ordCols: mob ? 'minmax(0,1fr) auto' : 'minmax(0,1.6fr) minmax(0,1.2fr) 100px 120px', ordGap: mob ? '8px 12px' : '16px',
          ordList: s.orders.map((x, i) => { const c = this.orderStatus(x), billable = x.reviews.filter(r => r.status !== 'not_eligible' && r.status !== 'cancelled'), rem = x.reviews.filter(r => r.status === 'removed').length;
            return { id: x.id, biz: x.business.name || 'Your business', date: new Date(x.createdAt).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }),
              progress: rem + ' of ' + billable.length + ' removed', pct: (billable.length ? rem / billable.length * 100 : 0) + '%',
              amount: x.invoice.total > 0 ? fmtFor(x)(x.invoice.total) : '—', status: c[0], chipBg: c[1], chipFg: c[2], chipBd: c[3], bd: i === sel ? '#151515' : 'transparent',
              go: () => { this.setState({ orderIdx: i, cancelOpen: false, rvTab: 'all' }); } }; }),
          pOrderDate: new Date(o.createdAt).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }),
          pStatus: st[0], pChipBg: st[1] === '#EFEFEF' ? '#FFFFFF' : st[1], pChipFg: st[2], pChipBd: st[3] === '#EFEFEF' ? '#FFFFFF' : st[3],
          pCancelNote: o.cancelled ? (o.invoice.total > 0 && o.payment.status !== 'paid' ? ' – reviews removed before that are still billed.' : ' – nothing is charged for this order.') : ''
        }; })(),
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
      hasAccNotice: !!s.accNotice && s.view === 'portal', accNotice: s.accNotice || '',
      loginErrorText: s.loginErrorText || "Email or password doesn't match.",
      loginBtn: s.loginBusy ? 'Logging in…' : 'Log in',
      doReset: () => this.doReset(), resetLabel: s.resetSent ? '✓ If an account exists for this address, a reset link is on its way (valid 1 h)' : 'Forgot your password?',
      openPortalAuto: () => { this.setState({ view: s.portalIn ? 'portal' : 'login', loginEmail: s.email, loginPass: '', loginError: false }); window.scrollTo({ top: 0 }); },
      successText: s.newAccount ? "We've created your personal dashboard and emailed your login details to " + s.email + '.' : 'This order was added to your existing account (' + s.email + '). Log in with your existing password.',
      reviewsLoading: s.reviewsStatus === 'loading', reviewsPartial: s.reviewsStatus === 'done' && !s.reviewsComplete, reviewsFailed: s.reviewsStatus === 'error',
      noResults: v.noResults && s.reviewsStatus !== 'loading',
      onBizQuery: e => this.onBizType(e.target.value),
      bizChoose: s.bizStatus === 'choose',
      chooseTitle: (s.places || []).length === 1 ? 'We found this profile – is it yours?' : 'We found ' + (s.places || []).length + ' profiles – select yours:',
      choosePlaces: (s.places || []).map(p => ({ initial: (p.name || '?')[0], name: p.name, meta: p.meta || p.address, go: () => this.pickPlace(p) })),
      hasAlts: s.bizStatus === 'found' && (s.places || []).length > 1,
      altLabel: 'Not your business? Show all ' + (s.places || []).length + ' matches',
      showAllMatches: () => this.setState({ bizStatus: 'choose', biz: null, selected: {}, placeReviews: [], reviewsStatus: 'idle' }),
      bizNfTitle: s.searchError ? 'Search is unavailable right now' : 'No profile found'
    });
    const real = this.realPortal();
    if (real) {
      Object.assign(v, real);
      // the design builds the eyebrow from its demo order; use the real one
      if (v.acc && (s.portalTab || 'overview') !== 'settings') v.acc.eyebrow = '// dashboard · ' + (s.account ? s.account.email : '');
    }
    else Object.assign(v, { pCanCancel: false, pCancelled: false, ordList: [], pOrderId: '', pBiz: '', pFirst: 'there', pEmail: '' });
    return v;
  }
