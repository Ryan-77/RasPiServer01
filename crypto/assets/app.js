/* ── Clock ──────────────────────────────────────────────────── */
const days=['SUN','MON','TUE','WED','THU','FRI','SAT'],months=['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
function pad(n){return String(n).padStart(2,'0')}
function tick(){
  const n=new Date();
  document.getElementById('clock').textContent=pad(n.getHours())+':'+pad(n.getMinutes())+':'+pad(n.getSeconds());
  document.getElementById('cdate').textContent=days[n.getDay()]+' · '+pad(n.getDate())+' '+months[n.getMonth()]+' '+n.getFullYear();
}
setInterval(tick,1000); tick();
const lb=document.getElementById('log-body');
if(lb) lb.scrollTop=lb.scrollHeight;

/* ── Sub-tab Switching (portfolio page) ─────────────────────── */
function switchTab(tab) {
  document.querySelectorAll('.sub-tab').forEach(b => b.classList.remove('active'));
  const btn = document.querySelector('.sub-tab[data-tab="' + tab + '"]');
  if (btn) btn.classList.add('active');
  const h = document.getElementById('tab-holdings');
  const p = document.getElementById('tab-paper');
  if (h) h.style.display = tab === 'holdings' ? 'block' : 'none';
  if (p) p.style.display = tab === 'paper' ? 'block' : 'none';
  history.replaceState(null, '', location.pathname + location.search + '#' + tab);

  // Update hero value + meta based on active tab
  const heroVal = document.getElementById('hero-value');
  const heroPrimary = document.getElementById('hero-meta-primary');
  const heroPaperRet = document.getElementById('hero-meta-paper-ret');
  const heroPaperSep = document.getElementById('hero-meta-sep2');
  if (heroVal) {
    if (tab === 'paper' && heroVal.dataset.paper) {
      heroVal.innerHTML = '<span style="color:var(--pu)">' + heroVal.dataset.paper + '</span>';
    } else {
      heroVal.innerHTML = heroVal.dataset.holdings || '<span style="color:var(--t3)">$0.00</span>';
    }
  }
  if (heroPrimary) {
    heroPrimary.textContent = tab === 'paper'
      ? (heroPrimary.dataset.paperText || 'paper portfolio')
      : (heroPrimary.dataset.holdingsText || '');
  }
  if (heroPaperRet) heroPaperRet.style.display = tab === 'paper' ? 'none' : '';
  if (heroPaperSep) heroPaperSep.style.display = tab === 'paper' ? 'none' : '';

  // Canvas charts need visible parent to render — only redraw when overview sub-tab is active
  if (tab === 'paper') {
    const activePaper = document.querySelector('.pp-sub-tab.active');
    if (!activePaper || activePaper.dataset.ptab === 'overview') {
      const activeBtn = document.querySelector('#pp-tf-btns .tf-btn.active');
      if (activeBtn) activeBtn.click();
    }
  }
}

/* ── Paper Portfolio Inner Sub-tabs ─────────────────────────── */
const PP_TABS = ['overview','holdings','allocations','trades','performance','settings'];

function switchPaperTab(tab) {
  document.querySelectorAll('.pp-sub-tab').forEach(b => b.classList.remove('active'));
  const btn = document.querySelector('.pp-sub-tab[data-ptab="' + tab + '"]');
  if (btn) btn.classList.add('active');
  PP_TABS.forEach(t => {
    const el = document.getElementById('pp-tab-' + t);
    if (el) el.style.display = t === tab ? 'block' : 'none';
  });
  history.replaceState(null, '', location.pathname + location.search + '#paper:' + tab);
  // Trigger chart redraw when switching to overview
  if (tab === 'overview') {
    const activeBtn = document.querySelector('#pp-tf-btns .tf-btn.active');
    if (activeBtn) activeBtn.click();
  }
  // Init sort on trades tab first visit
  if (tab === 'trades' && !_tradeSortInited) {
    initTradeSort('pp-trades-table');
    _tradeSortInited = true;
  }
}

/* ── Trade History Sort ──────────────────────────────────────── */
const SORT_CYCLE = ['auto','asc','desc','first-last','last-first'];
const _tradeSortState = {};
let _tradeOrigOrder = [];
let _tradeSortInited = false;

function initTradeSort(tableId) {
  const tbody = document.querySelector('#' + tableId + ' tbody');
  if (!tbody) return;
  _tradeOrigOrder = Array.from(tbody.querySelectorAll('tr'));
}

function tradeSort(th) {
  const col = th.dataset.col;
  const cur = _tradeSortState[col] || 'auto';
  const next = SORT_CYCLE[(SORT_CYCLE.indexOf(cur) + 1) % SORT_CYCLE.length];
  // Reset all header labels
  th.closest('thead').querySelectorAll('th[data-col]').forEach(h => {
    delete _tradeSortState[h.dataset.col];
    const lbl = h.querySelector('.sort-lbl');
    if (lbl) lbl.textContent = '';
    h.removeAttribute('data-sort-active');
  });
  _tradeSortState[col] = next;
  const lbl = th.querySelector('.sort-lbl');
  if (lbl) lbl.textContent = next !== 'auto' ? ' [' + next + ']' : '';
  if (next !== 'auto') th.setAttribute('data-sort-active', '1');
  // Update status bar
  const status = document.getElementById('trade-sort-status');
  if (status) status.textContent = next !== 'auto'
    ? 'SORTED BY: ' + col.toUpperCase() + ' · ' + next.toUpperCase()
    : '';
  applyTradeSort(th.closest('table').querySelector('tbody'), col, next);
}

function applyTradeSort(tbody, col, state) {
  let rows;
  if (state === 'first-last') {
    rows = [..._tradeOrigOrder];
  } else if (state === 'last-first') {
    rows = [..._tradeOrigOrder].slice().reverse();
  } else if (state === 'auto') {
    rows = [..._tradeOrigOrder];
  } else {
    rows = Array.from(tbody.querySelectorAll('tr')).sort((a, b) => {
      const atd = a.querySelector('td[data-col="' + col + '"]');
      const btd = b.querySelector('td[data-col="' + col + '"]');
      const av = atd ? atd.dataset.val ?? '' : '';
      const bv = btd ? btd.dataset.val ?? '' : '';
      const an = parseFloat(av), bn = parseFloat(bv);
      const cmp = !isNaN(an) && !isNaN(bn) ? an - bn : String(av).localeCompare(String(bv));
      return state === 'asc' ? cmp : -cmp;
    });
  }
  rows.forEach(r => tbody.appendChild(r));
}

/* ── Equity Chart Engine ──────────────────────────────────────── */
function drawEquityChart(canvas, data, opts) {
  if (!canvas || !data || data.length < 2) return;
  const dpr = window.devicePixelRatio || 1;
  const rect = canvas.getBoundingClientRect();
  canvas.width = rect.width * dpr;
  canvas.height = rect.height * dpr;
  const ctx = canvas.getContext('2d');
  ctx.scale(dpr, dpr);
  const W = rect.width, H = rect.height;
  const pad = {t:12, b:22, l:52, r:12};
  const cW = W - pad.l - pad.r, cH = H - pad.t - pad.b;

  const vals = data.map(d => d.total_value);
  let minV = Math.min(...vals), maxV = Math.max(...vals);
  if (opts.baseline !== undefined) {
    minV = Math.min(minV, opts.baseline);
    maxV = Math.max(maxV, opts.baseline);
  }
  const range = maxV - minV || 1;
  minV -= range * 0.04; maxV += range * 0.04;
  const rng = maxV - minV;

  const toX = i => pad.l + (i / (data.length - 1)) * cW;
  const toY = v => pad.t + (1 - (v - minV) / rng) * cH;

  ctx.clearRect(0, 0, W, H);

  // Grid lines
  ctx.strokeStyle = '#333130'; ctx.lineWidth = 0.5;
  for (let i = 0; i < 5; i++) {
    const y = pad.t + (i / 4) * cH;
    ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(W - pad.r, y); ctx.stroke();
    const v = maxV - (i / 4) * rng;
    ctx.fillStyle = '#6b6966'; ctx.font = '10px system-ui';
    ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
    ctx.fillText('$' + v.toFixed(0), pad.l - 6, y);
  }

  // Baseline
  if (opts.baseline !== undefined) {
    const by = toY(opts.baseline);
    ctx.setLineDash([4, 3]); ctx.strokeStyle = '#4a4845'; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(pad.l, by); ctx.lineTo(W - pad.r, by); ctx.stroke();
    ctx.setLineDash([]);
  }

  // Draw line helper
  function drawLine(values, color, width) {
    if (!values || values.length < 2) return;
    ctx.strokeStyle = color; ctx.lineWidth = width; ctx.beginPath();
    values.forEach((v, i) => { if (v === null) return; const fn = i === 0 ? 'moveTo' : 'lineTo'; ctx[fn](toX(i), toY(v)); });
    ctx.stroke();
  }

  // Benchmark lines
  if (opts.showBenchmarks && opts.baseline) {
    const btcVals = data.map(d => d.btc_return_pct !== undefined ? opts.baseline * (1 + d.btc_return_pct / 100) : null);
    const eqVals  = data.map(d => d.equal_weight_return_pct !== undefined ? opts.baseline * (1 + d.equal_weight_return_pct / 100) : null);
    drawLine(btcVals, '#fb923c', 1);
    drawLine(eqVals, '#60a5fa', 1);
  }

  // Main line
  drawLine(vals, opts.color || '#a78bfa', 2);

  // X-axis labels
  ctx.fillStyle = '#6b6966'; ctx.font = '10px system-ui'; ctx.textAlign = 'center'; ctx.textBaseline = 'top';
  const labelCount = Math.min(5, data.length);
  for (let i = 0; i < labelCount; i++) {
    const idx = Math.floor(i * (data.length - 1) / (labelCount - 1));
    const d = data[idx];
    const ts = d.recorded_at || d.date || '';
    const label = ts.length >= 13 ? ts.substring(5, 13) : ts.substring(0, 10);
    ctx.fillText(label, toX(idx), H - pad.b + 6);
  }

  // Hover tooltip
  canvas.onmousemove = function(e) {
    const br = canvas.getBoundingClientRect();
    const mx = e.clientX - br.left;
    const idx = Math.round(((mx - pad.l) / cW) * (data.length - 1));
    if (idx < 0 || idx >= data.length) return;
    const d = data[idx];
    const ts = d.recorded_at || d.date || '';
    canvas.title = ts + '  $' + d.total_value.toFixed(2);
  };
}

function setupChartButtons(btnContainerId, canvasId, apiEndpoint, opts) {
  const container = document.getElementById(btnContainerId);
  const canvas = document.getElementById(canvasId);
  if (!container || !canvas) return;

  function loadChart(hours) {
    fetch(apiEndpoint + '&hours=' + hours)
      .then(r => r.json())
      .then(resp => {
        const data = resp.data || resp;
        if (Array.isArray(data) && data.length > 1) {
          data.forEach(d => {
            d.total_value = parseFloat(d.total_value) || 0;
            if (d.btc_return_pct !== undefined) d.btc_return_pct = parseFloat(d.btc_return_pct) || 0;
            if (d.equal_weight_return_pct !== undefined) d.equal_weight_return_pct = parseFloat(d.equal_weight_return_pct) || 0;
          });
          drawEquityChart(canvas, data, opts);
        }
      })
      .catch(err => console.warn('Chart fetch error:', err));
  }

  container.querySelectorAll('.tf-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      container.querySelectorAll('.tf-btn').forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      loadChart(parseInt(this.dataset.hours));
    });
  });

  const activeBtn = container.querySelector('.tf-btn.active');
  if (activeBtn) loadChart(parseInt(activeBtn.dataset.hours));
}

/* ── Initialize on page load ─────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
  // Parse URL hash for direct tab/sub-tab linking
  const hash = window.location.hash;
  if (hash.startsWith('#paper')) {
    switchTab('paper');
    const sub = hash.includes(':') ? hash.split(':')[1] : 'overview';
    if (PP_TABS.includes(sub)) switchPaperTab(sub);
  }

  // Paper Portfolio equity curve
  if (document.getElementById('pp-equity-chart')) {
    setupChartButtons('pp-tf-btns', 'pp-equity-chart',
      'api.php?action=paper_portfolio_history',
      { color: '#a78bfa', baseline: APP_DATA.ppFundedAmount, showBenchmarks: true }
    );
  }
  // Paper Trading P&L equity curve
  if (document.getElementById('pt-equity-chart')) {
    setupChartButtons('pt-tf-btns', 'pt-equity-chart',
      'api.php?action=paper_trading_history',
      { color: '#c96442', baseline: 0, showBenchmarks: false }
    );
  }
});
