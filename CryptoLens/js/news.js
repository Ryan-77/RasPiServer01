/* ============================================================
   CryptoLens — News Feed Module
   Primary:  CoinGecko /news endpoint (paid plans)
   Fallback: CoinGecko free endpoints — market movers, trending,
             global stats — presented as news-style cards.
   Used on:  index.html (6 cards) and news.html (full feed)
   ============================================================ */

let allArticles  = [];
let activeFilter = 'all';

const NEWS_KEYWORDS = {
  bitcoin:    ['bitcoin', 'btc', 'satoshi', 'halving'],
  ethereum:   ['ethereum', 'eth', 'solidity', 'evm', 'layer 2', 'l2'],
  defi:       ['defi', 'uniswap', 'aave', 'liquidity', 'yield', 'protocol', 'dao', 'tvl', 'curve', 'lido'],
  layer2:     ['layer 2', 'layer2', 'rollup', 'arbitrum', 'optimism', 'polygon', 'base', 'zk'],
  regulation: ['sec', 'regulation', 'legal', 'law', 'government', 'ban', 'policy', 'cftc', 'congress'],
  nft:        ['nft', 'nfts', 'opensea', 'collectible', 'ordinals'],
};

/* ── Field normalizer ──────────────────────────────────────── */
// Handles both old API format (thumb_2x, news_site, updated_at)
// and new API format (image, source_name, posted_at ISO-8601)
function normalizeArticle(raw) {
  let ts = 0;
  if (raw.posted_at) {
    ts = Math.floor(new Date(raw.posted_at).getTime() / 1000);
  } else if (raw.updated_at) {
    ts = Number(raw.updated_at);
  } else if (raw.created_at) {
    ts = Number(raw.created_at);
  }
  return {
    title:  raw.title       || 'Untitled',
    desc:   raw.description || '',
    url:    raw.url         || 'https://coingecko.com/en/news',
    thumb:  raw.image       || raw.thumb_2x || raw.thumb || '',
    source: raw.source_name || raw.news_site || 'CoinGecko',
    ts,
    tag:    raw.type === 'guide' ? 'guide' : 'news',
    _raw:   raw,
  };
}

/* ── Card builder ──────────────────────────────────────────── */
function buildNewsCard(article) {
  const { title, desc, url, thumb, source, ts, tag } = article;

  const imgHtml = thumb
    ? `<img class="news-card-img" src="${thumb}" alt=""
            loading="lazy"
            onerror="this.outerHTML='<div class=\\'news-card-img-placeholder\\'>📰</div>'">`
    : `<div class="news-card-img-placeholder">📰</div>`;

  const time = ts ? timeAgo(ts) : '';
  const tagBadge = tag === 'guide'
    ? `<span class="badge badge-cyan" style="font-size:.6rem;">GUIDE</span>`
    : `<span class="badge badge-gray" style="font-size:.6rem;">NEWS</span>`;

  const safeTitle = title.replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const safeDesc  = truncate(desc.replace(/<[^>]+>/g, ''), 150)
                      .replace(/</g, '&lt;').replace(/>/g, '&gt;');

  return `
    <article class="news-card">
      ${imgHtml}
      <div class="news-card-body">
        <div class="news-card-meta">
          <span class="news-source">${source} ${tagBadge}</span>
          <span class="news-time">${time}</span>
        </div>
        <h4 class="news-card-title">
          <a href="${url}" target="_blank" rel="noopener noreferrer">${safeTitle}</a>
        </h4>
        <p class="news-card-desc">${safeDesc}</p>
      </div>
      <div class="news-card-footer">
        <a class="news-read-more" href="${url}" target="_blank" rel="noopener noreferrer">
          Read article →
        </a>
      </div>
    </article>`;
}

/* ── Skeleton placeholders ─────────────────────────────────── */
function buildSkeletons(count, container) {
  container.innerHTML = Array(count).fill(`
    <div class="news-card">
      <div class="skeleton" style="height:160px;border-radius:0;"></div>
      <div class="news-card-body" style="gap:.75rem;display:flex;flex-direction:column;">
        <div class="skeleton" style="height:10px;width:55%;"></div>
        <div class="skeleton" style="height:16px;width:92%;"></div>
        <div class="skeleton" style="height:14px;width:80%;"></div>
        <div class="skeleton" style="height:14px;width:66%;"></div>
      </div>
    </div>`).join('');
}

/* ── Filter + search logic ─────────────────────────────────── */
function filterArticles(articles) {
  if (activeFilter === 'all') return articles;
  const kws = NEWS_KEYWORDS[activeFilter] || [];
  return articles.filter(a => {
    const text = (a.title + ' ' + a.desc).toLowerCase();
    return kws.some(kw => text.includes(kw));
  });
}

function renderGrid(articles, container) {
  const filtered = filterArticles(articles);
  if (!filtered.length) {
    container.innerHTML = `
      <p style="text-align:center;color:var(--text-muted);
                grid-column:1/-1;padding:var(--space-8);">
        No articles match this filter right now.
      </p>`;
    return;
  }
  container.innerHTML = filtered.map(buildNewsCard).join('');
}

/* ── CoinGecko /news attempt ───────────────────────────────── */
async function fetchCoinGeckoNews() {
  const data = await fetchCG('/news', { per_page: 50, page: 1 });
  // API returns null on error, or may return { data: [...] } in newer versions
  if (!data) return null;
  const items = Array.isArray(data) ? data : (data.data || data.articles || null);
  if (!items || !items.length) return null;
  return items.map(normalizeArticle);
}

/* ── Fallback: CoinGecko free-tier market cards ────────────── */
async function buildMarketNewsCards() {
  const [globalData, marketsData, trendingData] = await Promise.all([
    fetchCG('/global'),
    fetchCG('/coins/markets', {
      vs_currency: 'usd',
      order: 'market_cap_desc',
      per_page: 50,
      page: 1,
      price_change_percentage: '24h',
    }),
    fetchCG('/search/trending'),
  ]);

  const cards = [];
  const now   = Math.floor(Date.now() / 1000);

  /* ─ Global market snapshot ─ */
  if (globalData?.data) {
    const d     = globalData.data;
    const mcap  = d.total_market_cap?.usd;
    const chg   = d.market_cap_change_percentage_24h_usd;
    const btcDom = d.market_cap_percentage?.btc;
    const dir   = chg >= 0 ? 'rose' : 'fell';
    cards.push({
      title:  `Global crypto market cap ${dir} ${Math.abs(chg).toFixed(1)}% in 24 hours`,
      desc:   `Total market capitalization stands at ${fmtNum(mcap)}. ` +
              `Bitcoin dominance is ${btcDom?.toFixed(1)}%. ` +
              `There are ${d.active_cryptocurrencies?.toLocaleString()} active cryptocurrencies tracked across ${d.markets} exchanges.`,
      url:    'https://www.coingecko.com/en/global-charts',
      thumb:  '',
      source: 'CoinGecko Markets',
      ts:     now,
      tag:    'news',
    });
  }

  /* ─ Top gainers (up to 4) ─ */
  if (marketsData) {
    const gainers = [...marketsData]
      .filter(c => c.price_change_percentage_24h > 0)
      .sort((a, b) => b.price_change_percentage_24h - a.price_change_percentage_24h)
      .slice(0, 4);

    gainers.forEach(c => {
      cards.push({
        title:  `${c.name} surges ${fmtPct(c.price_change_percentage_24h)} — top gainer in 24h`,
        desc:   `${c.name} (${c.symbol.toUpperCase()}) is trading at ${fmtPrice(c.current_price)}, ` +
                `up ${fmtPct(c.price_change_percentage_24h)} over the past 24 hours. ` +
                `Market cap: ${fmtNum(c.market_cap)}. 24h volume: ${fmtNum(c.total_volume)}.`,
        url:    `https://www.coingecko.com/en/coins/${c.id}`,
        thumb:  c.image || '',
        source: 'CoinGecko Movers',
        ts:     now - 600,
        tag:    'news',
      });
    });

    /* ─ Notable losers (up to 2) ─ */
    const losers = [...marketsData]
      .filter(c => c.price_change_percentage_24h < -5)
      .sort((a, b) => a.price_change_percentage_24h - b.price_change_percentage_24h)
      .slice(0, 2);

    losers.forEach(c => {
      cards.push({
        title:  `${c.name} drops ${fmtPct(c.price_change_percentage_24h)} amid broad selling pressure`,
        desc:   `${c.name} (${c.symbol.toUpperCase()}) fell to ${fmtPrice(c.current_price)}, ` +
                `down ${fmtPct(c.price_change_percentage_24h)} over the last 24 hours. ` +
                `Market cap: ${fmtNum(c.market_cap)}.`,
        url:    `https://www.coingecko.com/en/coins/${c.id}`,
        thumb:  c.image || '',
        source: 'CoinGecko Movers',
        ts:     now - 900,
        tag:    'news',
      });
    });

    /* ─ Volume leader ─ */
    const volLeader = [...marketsData].sort((a, b) => b.total_volume - a.total_volume)[0];
    if (volLeader) {
      cards.push({
        title:  `${volLeader.name} leads 24h trading volume at ${fmtNum(volLeader.total_volume)}`,
        desc:   `${volLeader.name} ranks #1 by trading volume over the past day across tracked exchanges. ` +
                `Current price: ${fmtPrice(volLeader.current_price)}. ` +
                `Volume-to-market-cap ratio indicates ${
                  volLeader.total_volume / volLeader.market_cap > 0.2 ? 'elevated' : 'normal'
                } trading activity.`,
        url:    `https://www.coingecko.com/en/coins/${volLeader.id}`,
        thumb:  volLeader.image || '',
        source: 'CoinGecko Volume',
        ts:     now - 1200,
        tag:    'news',
      });
    }
  }

  /* ─ Trending coins ─ */
  if (trendingData?.coins?.length) {
    const topTrending = trendingData.coins.slice(0, 3).map(({ item }) => item);
    const names = topTrending.map(c => c.name).join(', ');
    cards.push({
      title:  `Trending on CoinGecko: ${names}`,
      desc:   `The most searched cryptocurrencies on CoinGecko right now are ` +
              topTrending.map(c =>
                `${c.name} (${c.symbol.toUpperCase()}, ranked #${c.market_cap_rank || '?'} by market cap)`
              ).join(', ') + '. Trending data updates every few minutes.',
      url:    'https://www.coingecko.com/en/discover',
      thumb:  topTrending[0]?.thumb || '',
      source: 'CoinGecko Trending',
      ts:     now - 300,
      tag:    'news',
    });

    /* Individual trending cards */
    topTrending.slice(1).forEach(item => {
      const chg = item.data?.price_change_percentage_24h?.usd;
      cards.push({
        title:  `${item.name} is trending — ${chg != null ? fmtPct(chg) + ' in 24h' : 'gaining attention'}`,
        desc:   `${item.name} (${item.symbol.toUpperCase()}) is among the top trending coins on CoinGecko. ` +
                `Market cap rank: #${item.market_cap_rank || '—'}. ` +
                (chg != null ? `24h price change: ${fmtPct(chg)}.` : ''),
        url:    `https://www.coingecko.com/en/coins/${item.id}`,
        thumb:  item.large || item.thumb || '',
        source: 'CoinGecko Trending',
        ts:     now - 400,
        tag:    'news',
      });
    });
  }

  return cards.length ? cards : null;
}

/* ── Master news loader ─────────────────────────────────────── */
async function loadNews() {
  // 1. Try the paid /news endpoint first
  const cgNews = await fetchCoinGeckoNews();
  if (cgNews && cgNews.length) return cgNews;

  // 2. Fall back to market intelligence cards (all free CoinGecko)
  console.info('CryptoLens: /news endpoint unavailable (requires paid plan). Using market data feed.');
  return buildMarketNewsCards();
}

/* ── HOME PAGE: 6-card preview ──────────────────────────────── */
async function initHomeNews() {
  const container = document.getElementById('homeNewsGrid');
  if (!container) return;

  buildSkeletons(6, container);

  const articles = await loadNews();
  if (!articles || !articles.length) {
    container.innerHTML = `
      <p style="color:var(--text-muted);grid-column:1/-1;text-align:center;padding:var(--space-8);">
        Market data temporarily unavailable.
        <a href="https://coingecko.com/en/news" target="_blank" rel="noopener noreferrer">
          Visit CoinGecko directly →
        </a>
      </p>`;
    return;
  }

  allArticles = articles;
  container.innerHTML = articles.slice(0, 6).map(buildNewsCard).join('');
}

/* ── NEWS PAGE: full feed + search + filters ─────────────────── */
async function initNewsPage() {
  const container = document.getElementById('newsGrid');
  if (!container) return;

  // ⚠️  Attach ALL listeners BEFORE the async fetch
  // so tabs/search work even if the API is slow or fails.
  const searchInput = document.getElementById('newsSearch');

  if (searchInput) {
    searchInput.addEventListener('input', () => {
      const q = searchInput.value.toLowerCase().trim();
      const source = allArticles.length ? allArticles : [];
      const filtered = q
        ? source.filter(a => (a.title + ' ' + a.desc).toLowerCase().includes(q))
        : source;
      renderGrid(filtered, container);
    });
  }

  document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      activeFilter = tab.dataset.filter || 'all';
      if (searchInput) searchInput.value = '';
      renderGrid(allArticles, container);  // uses current allArticles (may be empty yet)
    });
  });

  // Now fetch
  buildSkeletons(9, container);

  const articles = await loadNews();

  if (!articles || !articles.length) {
    container.innerHTML = `
      <div style="grid-column:1/-1;text-align:center;padding:var(--space-12);">
        <p style="margin-bottom:var(--space-4);">
          Unable to load market data. Check your API key in <code>js/config.js</code>.
        </p>
        <a href="https://coingecko.com/en/news" class="btn btn-outline"
           target="_blank" rel="noopener noreferrer">
          Read news on CoinGecko →
        </a>
      </div>`;
    return;
  }

  allArticles = articles;
  renderGrid(allArticles, container);
}

document.addEventListener('DOMContentLoaded', () => {
  initHomeNews();
  initNewsPage();
});
