/* ============================================================
   CryptoLens — Price Ticker Strip
   Runs on every page. Fetches top 20 coins and animates.
   ============================================================ */

async function initTicker() {
  const track = document.getElementById('tickerTrack');
  if (!track) return;

  const data = await fetchCG('/coins/markets', {
    vs_currency: 'usd',
    order: 'market_cap_desc',
    per_page: 20,
    page: 1,
    price_change_percentage: '24h'
  });

  if (!data || !data.length) {
    track.innerHTML = '<span class="ticker-loading">Market data temporarily unavailable — refresh to retry</span>';
    return;
  }

  const html = data.map(c => {
    const chg = c.price_change_percentage_24h;
    return `
      <span class="ticker-item">
        <span class="ticker-name">${c.symbol.toUpperCase()}</span>
        <span class="ticker-price">${fmtPrice(c.current_price)}</span>
        <span class="${changeClass(chg)}">${fmtPct(chg)}</span>
        <span class="ticker-dot"></span>
      </span>`;
  }).join('');

  // Duplicate for seamless CSS loop
  track.innerHTML = html + html;
}

document.addEventListener('DOMContentLoaded', initTicker);
