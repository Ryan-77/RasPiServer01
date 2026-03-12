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
  // Canvas charts need visible parent to render — trigger redraw on paper tab
  if (tab === 'paper') {
    const activeBtn = document.querySelector('#pp-tf-btns .tf-btn.active');
    if (activeBtn) activeBtn.click();
  }
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

/* ── Initialize charts on page load ────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
  // Check URL hash for direct tab linking
  if (window.location.hash === '#paper') switchTab('paper');

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
