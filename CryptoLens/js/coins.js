/* ============================================================
   CryptoLens — Coin Market Data + Explorer
   Used on: index.html (top-10 table, trending)
            coin-explorer.html (full table + modal)
   ============================================================ */

/* ---- Trending Coins (Home) ---- */
async function initTrending() {
  const container = document.getElementById('trendingStrip');
  if (!container) return;

  container.innerHTML = Array(7).fill(`<div class="skeleton trending-chip" style="height:72px;min-width:150px;"></div>`).join('');

  const data = await fetchCG('/search/trending');
  if (!data || !data.coins) {
    container.innerHTML = `<p style="color:var(--text-muted);">Trending data unavailable.</p>`;
    return;
  }

  container.innerHTML = data.coins.slice(0, 7).map(({ item }) => {
    const chg  = item.data?.price_change_percentage_24h?.usd;
    const chgHtml = (chg !== undefined && chg !== null)
      ? `<span class="${changeClass(chg)}" style="font-size:0.7rem;font-weight:600;">${fmtPct(chg)}</span>`
      : '';
    return `
      <a class="trending-chip" href="coin-explorer.html?coin=${item.id}" title="${item.name}">
        <img src="${item.thumb}" alt="${item.name}" width="32" height="32" loading="lazy"
             onerror="this.src='data:image/svg+xml,<svg xmlns=\\'http://www.w3.org/2000/svg\\' width=\\'32\\' height=\\'32\\'><rect fill=\\'%231c1c2e\\' rx=\\'16\\' width=\\'32\\' height=\\'32\\'/><text x=\\'16\\' y=\\'22\\' text-anchor=\\'middle\\' fill=\\'%237c3aed\\' font-size=\\'14\\' font-family=\\'sans-serif\\'>${(item.symbol || '?')[0]}</text></svg>'">
        <div class="trending-chip-info">
          <div class="name">${item.name}</div>
          <div class="sym">${(item.symbol || '').toUpperCase()}</div>
          ${chgHtml}
        </div>
        <span class="trending-rank">#${item.market_cap_rank || '—'}</span>
      </a>`;
  }).join('');
}

/* ---- Top 10 Table (Home) ---- */
async function initHomeTable() {
  const tbody = document.getElementById('homeTableBody');
  if (!tbody) return;

  tbody.innerHTML = Array(10).fill(`
    <tr>
      <td><div class="skeleton" style="height:14px;width:20px;"></div></td>
      <td><div class="skeleton" style="height:14px;width:130px;"></div></td>
      <td><div class="skeleton" style="height:14px;width:90px;"></div></td>
      <td><div class="skeleton" style="height:14px;width:70px;"></div></td>
      <td><div class="skeleton" style="height:14px;width:100px;"></div></td>
    </tr>`).join('');

  const data = await fetchCG('/coins/markets', {
    vs_currency: 'usd',
    order: 'market_cap_desc',
    per_page: 10,
    page: 1
  });

  if (!data) {
    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:2rem;">Data unavailable</td></tr>`;
    return;
  }

  tbody.innerHTML = data.map(c => {
    const chg24 = c.price_change_percentage_24h;
    return `
      <tr>
        <td class="rank-cell">${c.market_cap_rank}</td>
        <td>
          <div class="coin-cell">
            <img src="${c.image}" alt="${c.name}" width="28" height="28" loading="lazy"
                 onerror="this.style.display='none'">
            <span>${c.name} <span class="coin-sym">${c.symbol.toUpperCase()}</span></span>
          </div>
        </td>
        <td class="price-cell">${fmtPrice(c.current_price)}</td>
        <td class="${changeClass(chg24)}">${fmtPct(chg24)}</td>
        <td>${fmtNum(c.market_cap)}</td>
      </tr>`;
  }).join('');
}

/* ---- Full Explorer Table (coin-explorer.html) ---- */
let explorerData = [];
let sortKey   = 'market_cap_rank';
let sortAsc   = true;

async function initExplorer() {
  const tbody = document.getElementById('explorerTableBody');
  if (!tbody) return;

  tbody.innerHTML = `<tr><td colspan="8" class="table-loading">Loading coins…</td></tr>`;

  const data = await fetchCG('/coins/markets', {
    vs_currency: 'usd',
    order: 'market_cap_desc',
    per_page: 100,
    page: 1,
    price_change_percentage: '24h,7d'
  });

  if (!data) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-loading">Failed to load data. Check your API key.</td></tr>`;
    return;
  }

  explorerData = data;
  renderExplorerTable(explorerData);

  // Check URL param for pre-selected coin
  const params = new URLSearchParams(window.location.search);
  const coinId = params.get('coin');
  if (coinId) {
    const coin = explorerData.find(c => c.id === coinId);
    if (coin) openCoinModal(coin.id);
  }

  // Search
  const searchInput = document.getElementById('coinSearch');
  if (searchInput) {
    searchInput.addEventListener('input', () => {
      const q = searchInput.value.toLowerCase().trim();
      const filtered = q
        ? explorerData.filter(c => c.name.toLowerCase().includes(q) || c.symbol.toLowerCase().includes(q))
        : explorerData;
      renderExplorerTable(filtered);
    });
  }

  // Sort headers
  document.querySelectorAll('.data-table th[data-sort]').forEach(th => {
    th.addEventListener('click', () => {
      const key = th.dataset.sort;
      if (sortKey === key) { sortAsc = !sortAsc; }
      else { sortKey = key; sortAsc = true; }
      document.querySelectorAll('.data-table th').forEach(h => h.classList.remove('sorted'));
      th.classList.add('sorted');
      th.textContent = th.textContent.replace(' ↑','').replace(' ↓','') + (sortAsc ? ' ↑' : ' ↓');
      const sorted = [...explorerData].sort((a, b) => {
        const va = a[key] ?? 0, vb = b[key] ?? 0;
        return sortAsc ? va - vb : vb - va;
      });
      renderExplorerTable(sorted);
    });
  });
}

function renderExplorerTable(coins) {
  const tbody = document.getElementById('explorerTableBody');
  if (!tbody) return;

  if (!coins.length) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-loading">No coins match your search.</td></tr>`;
    return;
  }

  tbody.innerHTML = coins.map(c => {
    const chg24 = c.price_change_percentage_24h;
    const chg7  = c.price_change_percentage_7d_in_currency;
    return `
      <tr>
        <td class="rank-cell">${c.market_cap_rank || '—'}</td>
        <td>
          <div class="coin-cell">
            <img src="${c.image}" alt="${c.name}" width="28" height="28" loading="lazy"
                 onerror="this.style.display='none'">
            <div>
              <div>${c.name}</div>
              <div class="coin-sym">${c.symbol.toUpperCase()}</div>
            </div>
          </div>
        </td>
        <td class="price-cell">${fmtPrice(c.current_price)}</td>
        <td class="${changeClass(chg24)}">${fmtPct(chg24)}</td>
        <td class="${changeClass(chg7)}">${fmtPct(chg7)}</td>
        <td>${fmtNum(c.market_cap)}</td>
        <td>${fmtNum(c.total_volume)}</td>
        <td>
          <button class="btn btn-outline btn-sm" onclick="openCoinModal('${c.id}')">Details</button>
        </td>
      </tr>`;
  }).join('');
}

/* ---- Coin Detail Modal ---- */
async function openCoinModal(coinId) {
  const overlay = document.getElementById('coinModal');
  const content = document.getElementById('coinModalContent');
  if (!overlay || !content) return;

  overlay.classList.add('open');
  document.body.style.overflow = 'hidden';

  content.innerHTML = `
    <div style="padding:var(--space-8);text-align:center;color:var(--text-muted);">
      <div class="skeleton" style="height:52px;width:52px;border-radius:50%;margin:0 auto var(--space-4);"></div>
      <div class="skeleton" style="height:24px;width:50%;margin:0 auto var(--space-2);"></div>
      <div class="skeleton" style="height:16px;width:30%;margin:0 auto;"></div>
    </div>`;

  const data = await fetchCG(`/coins/${coinId}`, {
    localization: 'false',
    tickers: 'false',
    market_data: 'true',
    community_data: 'false',
    developer_data: 'false'
  });

  if (!data) {
    content.innerHTML = `<p style="text-align:center;color:var(--text-muted);padding:2rem;">Failed to load coin data.</p>`;
    return;
  }

  const md   = data.market_data || {};
  const usd  = (obj) => obj?.usd ?? null;
  const price    = usd(md.current_price);
  const chg24    = usd(md.price_change_percentage_24h_in_currency);
  const mktCap   = usd(md.market_cap);
  const vol24    = usd(md.total_volume);
  const ath      = usd(md.ath);
  const supply   = md.circulating_supply;

  const descFull = data.description?.en || '';
  const descShort = truncate(descFull.replace(/<[^>]+>/g, ''), 400);

  const website = data.links?.homepage?.[0] || '';
  const reddit  = data.links?.subreddit_url || '';
  const github  = data.links?.repos_url?.github?.[0] || '';

  content.innerHTML = `
    <div class="modal-coin-header">
      <img src="${data.image?.large || data.image?.small || ''}" alt="${data.name}" width="52" height="52"
           onerror="this.style.display='none'">
      <div>
        <div class="modal-coin-name">${data.name}</div>
        <div class="modal-coin-sym">${(data.symbol || '').toUpperCase()} · Rank #${data.market_cap_rank || '—'}</div>
      </div>
    </div>
    <div class="modal-price-row">
      <span class="modal-price">${fmtPrice(price)}</span>
      <span class="badge ${chg24 >= 0 ? 'badge-green' : 'badge-red'}">${fmtPct(chg24)}</span>
      <span style="font-size:var(--font-xs);color:var(--text-muted);">24h</span>
    </div>
    <div class="modal-stats-grid">
      <div class="modal-stat">
        <div class="label">Market Cap</div>
        <div class="value">${fmtNum(mktCap)}</div>
      </div>
      <div class="modal-stat">
        <div class="label">24h Volume</div>
        <div class="value">${fmtNum(vol24)}</div>
      </div>
      <div class="modal-stat">
        <div class="label">Circulating Supply</div>
        <div class="value">${supply ? supply.toLocaleString() + ' ' + (data.symbol || '').toUpperCase() : '—'}</div>
      </div>
      <div class="modal-stat">
        <div class="label">All-Time High</div>
        <div class="value">${fmtPrice(ath)}</div>
      </div>
    </div>
    ${descShort ? `<p class="modal-desc">${descShort}</p>` : ''}
    <div class="modal-links">
      ${website ? `<a class="modal-link" href="${website}" target="_blank" rel="noopener noreferrer">🌐 Website</a>` : ''}
      ${reddit  ? `<a class="modal-link" href="${reddit}"  target="_blank" rel="noopener noreferrer">💬 Reddit</a>` : ''}
      ${github  ? `<a class="modal-link" href="${github}"  target="_blank" rel="noopener noreferrer">⌥ GitHub</a>` : ''}
      <a class="modal-link" href="https://coingecko.com/en/coins/${data.id}" target="_blank" rel="noopener noreferrer">📊 CoinGecko</a>
    </div>`;
}

function closeCoinModal() {
  const overlay = document.getElementById('coinModal');
  if (overlay) overlay.classList.remove('open');
  document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', () => {
  initTrending();
  initHomeTable();
  initExplorer();

  // Close modal on overlay click or close button
  const overlay = document.getElementById('coinModal');
  if (overlay) {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeCoinModal();
    });
  }
  const closeBtn = document.getElementById('coinModalClose');
  if (closeBtn) closeBtn.addEventListener('click', closeCoinModal);

  // Close on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeCoinModal();
  });
});
