  // ===== byereviews production layer for the Lead Finder (injected by tools/build_admin.py) =====

  api(action, opts = {}) {
    const init = opts.body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(opts.body) } : { credentials: 'same-origin' };
    return fetch('/order.php?a=' + action, init).then(r => r.json().catch(() => ({ ok: false, error: 'bad_response' }))).catch(() => ({ ok: false, error: 'network' }));
  }
  state = Object.assign({}, this.state, { leads: [], ready: false, configured: true, usage: null, limits: null, srvRun: null, qByRegion: {}, busy: false });

  componentDidMount() {
    this._designDidMount();
    this.api('admin-leads').then(r => {
      if (!r.ok) return this.toast(r.error === 'not_authed' ? 'Session expired – please sign in again' : 'Could not load leads');
      const st = r.settings, region = st.region === 'US' ? 'USA' : 'England';
      this.setState({ ready: true, qByRegion: { England: st.queries.GB, USA: st.queries.US }, region, queries: region === 'USA' ? st.queries.US : st.queries.GB, crit: st.crit, ig: !!st.ig, exclude: st.exclude || '' });
      this.applyState(r);
      if (r.run && r.run.status === 'running') this.loop(r.run.id); // e.g. page reloaded during a run
    });
  }
  componentWillUnmount() { this._designWillUnmount(); this.stopLoop = true; }

  lead(l) {
    return { id: l.id, name: l.name, address: l.address, region: l.region === 'US' ? 'USA' : 'England', rating: Number(l.rating) || 0, count: l.count, ig: l.ig || '',
      status: l.status, phone: l.phone || '', web: l.web || '', mapsUrl: l.maps || '', notes: l.notes || '',
      reviews: (l.reviews || []).map(r => ({ stars: r.stars, author: r.author, at: r.at, text: r.text || '', link: r.link || '' })) };
  }
  applyState(r) {
    this.setState(s => ({ configured: r.configured, usage: r.usage, limits: r.limits, srvRun: r.run,
      // keep what is typed in open notes fields
      leads: r.leads.map(l => { const n = this.lead(l), old = s.leads.find(x => x.id === n.id); return old && this._notesT && this._notesT[n.id] ? { ...n, notes: old.notes } : n; }) }));
  }
  payload(extra) {
    const s = this.state;
    return Object.assign({ region: s.region === 'USA' ? 'US' : 'GB', queries: s.queries, crit: s.crit, ig: s.ig, exclude: s.exclude || '' }, extra);
  }
  failRun(r) {
    const m = { not_configured: 'Google API key missing on the server', no_queries: 'Add at least one query', already_running: 'A search is already running', not_authed: 'Session expired – please sign in again' }[r.error];
    this.toast(m || 'Something went wrong');
    if (r.error === 'not_configured') this.setState({ configured: false });
  }
  startRun() {
    if (this.state.busy || this.isRunning()) return;
    if (!this.state.configured) return this.toast('Add the Google API key on the server first');
    this.setState({ busy: true });
    this.api('admin-leads-run', { body: this.payload() }).then(r => {
      this.setState({ busy: false });
      if (!r.ok) return this.failRun(r);
      this.setState({ srvRun: r.run });
      this.loop(r.run.id);
    });
  }
  loop(id) {
    this.stopLoop = false;
    const step = () => this.api('admin-leads-step', { body: { run: id } }).then(r => {
      if (this.stopLoop) return;
      if (!r.ok) { if (r.error === 'not_authed') return this.failRun(r); return setTimeout(step, 3000); } // network hiccup: try again
      this.applyState(r);
      const run = r.run;
      if (run && run.status === 'running') return step();
      if (!run) return;
      if (run.status === 'done') this.toast('Run finished · ' + run.leads + ' new lead' + (run.leads === 1 ? '' : 's'));
      else if (run.status === 'quota') this.toast('Daily Google limit reached – continue tomorrow');
      else if (run.status === 'error') { this.setState({ configured: false }); this.toast('Google rejected the API key'); }
    });
    step();
  }
  isRunning() { const r = this.state.srvRun; return !!(r && r.status === 'running'); }
  open(id) { this.setState({ openId: id, loading: false }); if (this.state.mode === 'page') window.scrollTo({ top: 0 }); }
  setLead(id, patch) {
    this._designSetLead(id, patch);
    if (patch.status) this.api('admin-lead', { body: { id, status: patch.status } }).then(r => { if (!r.ok) this.toast('Could not save'); });
    if ('notes' in patch) { // save notes 800 ms after the last keystroke
      this._notesT = this._notesT || {};
      clearTimeout(this._notesT[id]);
      this._notesT[id] = setTimeout(() => { delete this._notesT[id]; this.api('admin-lead', { body: { id, notes: patch.notes } }); }, 800);
    }
  }

  renderVals() {
    const s = this.state, run = s.srvRun, running = this.isRunning();
    // the design reads demo/run/lastRun – feed it the server state
    const stats = r => ({ i: r.i, total: r.total, places: r.places, small: r.small, rev: r.rev, leads: r.leads, skip: r.skip });
    const saved = { demo: s.demo, run: s.run, lastRun: s.lastRun };
    s.demo = !s.configured ? 'error' : 'normal';
    s.run = running ? stats(run) : null;
    s.lastRun = run && !running ? stats(run) : null;
    const v = this._designRenderVals();
    Object.assign(s, saved);

    const u = s.usage || { text: { month: 0, day: 0 }, details: { month: 0, day: 0 } };
    const lim = s.limits || { text: { day: 30, month: 930 }, details: { day: 30, month: 930 } };
    const col = p => p >= 1 ? '#D93025' : p >= .8 ? '#E8A33D' : '#151515';
    // only Text Search is used (it returns the reviews too)
    const full = u.text.day >= lim.text.day || u.text.month >= lim.text.month;
    const warn = !full && u.text.day / lim.text.day >= .8;
    // free tier: 1,000 calls per month, then $40 per 1,000 (Text Search Enterprise + Atmosphere); older runs may have used Place Details
    const cost = Math.max(0, u.text.month - 1000) * .04 + Math.max(0, u.details.month - 1000) * .025;
    const when = t => { if (!t) return ''; const d = new Date(t), today = new Date().toDateString() === d.toDateString();
      return (today ? 'today' : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })) + ', ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }); };
    const stoppedQuota = !!(run && run.status === 'quota');
    const relabel = t => ({ ...t, label: { 'Small profiles': 'Small profiles (5–30)', 'Reviews checked': 'Reviews read', 'Skipped · checked recently': 'Already in list' }[t.label] || t.label });
    Object.assign(v, {
      criteria: v.criteria.filter(c => c.label !== 'Skip if checked within'),
      liveTiles: v.liveTiles.map(relabel), lastTiles: v.lastTiles.map(relabel),
      isEmpty: s.ready && v.isEmpty,
      exclude: s.exclude || '', onExclude: e => this.setState({ exclude: e.target.value }),
      quota: [['Google searches (incl. reviews)', u.text, lim.text]].map(([label, x, l]) => ({ label, month: x.month, today: x.day, monthMax: l.month, todayMax: l.day,
        monthPct: Math.min(100, x.month / l.month * 100) + '%', monthCol: col(x.month / l.month), todayPct: Math.min(100, x.day / l.day * 100) + '%', todayCol: col(x.day / l.day) })),
      cost: '€' + cost.toFixed(2), quotaWarn: warn, quotaFull: full, quotaBorder: full ? '#D93025' : warn ? '#E8A33D' : 'transparent',
      runOpacity: full || !s.configured || running || s.busy ? .4 : 1,
      runTitle: running ? 'Searching… Query ' + Math.min(run.i + 1, run.total) + ' of ' + run.total : '',
      runPct: running ? (run.i / Math.max(1, run.total) * 100) + '%' : '0%',
      runQuery: running ? '→ ' + (run.current || '…') : '',
      cancelRun: () => { this.stopLoop = true; this.api('admin-leads-cancel', { body: {} }).then(r => { if (r.ok) this.applyState(r); }); this.toast('Run cancelled'); },
      showLastRun: !!run && !running,
      quotaStopped: stoppedQuota,
      quotaStopText: stoppedQuota ? 'Stopped at query ' + run.i + ' of ' + run.total + '.' : '',
      quotaStopMore: stoppedQuota ? ' The daily Google limit was reached, so the run ended early to stay inside the free tier. Press Run search tomorrow to continue with the remaining ' + run.remaining + ' search' + (run.remaining === 1 ? '' : 'es') + '.' : '',
      lastRunTitle: !run ? 'Last run' : run.status === 'quota' ? 'Last run · stopped early' : run.status === 'cancelled' ? 'Last run · cancelled' : run.status === 'error' ? 'Last run · API error' : 'Last run',
      lastRunWhen: run ? when(run.endedAt || run.startedAt) : '',
      dryRun: () => this.api('admin-leads-run', { body: this.payload({ dry: true }) }).then(r => {
        if (!r.ok) return this.failRun(r);
        const d = r.dry, fits = d.text <= d.textLeft;
        this.toast((d.resume ? 'Resumes: ' : 'Dry run: ') + 'up to ' + d.text + ' Google searches (' + d.queries + ' queries × 3 pages, 20 places each) · ' + d.textLeft + ' left today' + (fits ? ' · €0.00' : ' · stops at the limit, continue tomorrow'));
      }),
      fixError: () => this.toast('Add google_places_key in /etc/byereviews/config.php (Places API (New) enabled)'),
      regionTabs: ['England', 'USA'].map(k => ({ label: k, bg: s.region === k ? '#FFFFFF' : 'transparent', fg: s.region === k ? '#151515' : '#8A8A8A', sh: s.region === k ? '0 1px 3px rgba(0,0,0,.08)' : 'none',
        go: () => { if (k === s.region) return; this.setState({ region: k, qByRegion: { ...s.qByRegion, [s.region]: s.queries }, queries: s.qByRegion[k] || '' }); } }))
    });
    const lead = s.leads.find(l => l.id === s.openId);
    if (lead && v.d) {
      Object.assign(v.d, { maps: lead.mapsUrl || v.d.maps, web: lead.web || v.d.web, hasWeb: !!lead.web, wa: lead.phone ? v.d.wa : '#' });
      v.d.reviews = v.d.reviews.map((r, i) => ({ ...r, link: lead.reviews[i] && lead.reviews[i].link || v.d.maps }));
      v.createOffer = () => {
        const n = lead.reviews.length, price = lead.reviews.every(r => Date.now() - r.at <= 28 * 86400000) ? 90 : 125;
        const msg = `Hi ${lead.name} team! We can get the ${n > 1 ? n + ' recent bad reviews' : 'recent ' + lead.reviews[0].stars + '★ review'} on your Google profile checked and removed if it breaks Google's rules. ` +
          `No cure, no pay: $${price} per review, only charged once it's actually gone. Start here: https://byereviews.com/#order`;
        try { navigator.clipboard.writeText(msg); } catch (e) {}
        this.toast('Offer text copied');
      };
    }
    return v;
  }
