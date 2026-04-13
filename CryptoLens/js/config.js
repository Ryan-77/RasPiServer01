/* ============================================================
   CryptoLens — Site Configuration
   CoinGecko calls are proxied through cg-proxy.php to keep
   the API key off the client.
   ============================================================ */

const CG_PROXY = '/cryptolens/cg-proxy.php';

// Helper: build proxy URL (no API key in the browser)
function cgUrl(endpoint, params = {}) {
  const url = new URL(CG_PROXY, window.location.origin);
  url.searchParams.set('endpoint', endpoint);
  for (const [k, v] of Object.entries(params)) {
    url.searchParams.set(k, v);
  }
  return url.toString();
}

// Helper: fetch CoinGecko via server-side proxy
async function fetchCG(endpoint, params = {}) {
  try {
    const res = await fetch(cgUrl(endpoint, params));
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  } catch (err) {
    console.warn('CoinGecko fetch failed:', err.message);
    return null;
  }
}

// Helper: relative time string from Unix timestamp
function timeAgo(unixTs) {
  const secs = Math.floor(Date.now() / 1000 - unixTs);
  if (secs < 60)    return 'Just now';
  if (secs < 3600)  return `${Math.floor(secs / 60)}m ago`;
  if (secs < 86400) return `${Math.floor(secs / 3600)}h ago`;
  return `${Math.floor(secs / 86400)}d ago`;
}

// Helper: format large numbers
function fmtNum(n) {
  if (!n && n !== 0) return '—';
  if (n >= 1e12) return '$' + (n / 1e12).toFixed(2) + 'T';
  if (n >= 1e9)  return '$' + (n / 1e9).toFixed(2) + 'B';
  if (n >= 1e6)  return '$' + (n / 1e6).toFixed(2) + 'M';
  return '$' + n.toLocaleString();
}

// Helper: format price with smart decimals
function fmtPrice(p) {
  if (!p && p !== 0) return '—';
  if (p >= 1000)  return '$' + p.toLocaleString(undefined, { maximumFractionDigits: 2 });
  if (p >= 1)     return '$' + p.toFixed(4);
  if (p >= 0.01)  return '$' + p.toFixed(5);
  return '$' + p.toFixed(8);
}

// Helper: format percentage change
function fmtPct(n, decimals = 2) {
  if (n === null || n === undefined) return '—';
  const sign = n >= 0 ? '+' : '';
  return sign + n.toFixed(decimals) + '%';
}

// Helper: change CSS class
function changeClass(n) {
  return (n >= 0) ? 'change-pos' : 'change-neg';
}

// Helper: truncate text
function truncate(str, len) {
  if (!str) return '';
  return str.length > len ? str.slice(0, len) + '…' : str;
}
