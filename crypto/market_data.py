"""
External API calls (Kraken, CoinPaprika), price fetching, OHLC caching, cross-price computation.
"""

import time
import requests
from datetime import datetime, timezone, timedelta
from typing import Dict, List

from config import (
    KRAKEN_PAIRS, COIN_MAP, TICKER_TO_ID,
    STABLECOINS, SKIP_TICKERS, log
)
from database import get_db


# ── HTTP HELPER ───────────────────────────────────────────────────────────────
def _get(url: str) -> dict:
    for attempt in range(1, 4):
        try:
            r = requests.get(url, timeout=15)
            r.raise_for_status()
            return r.json()
        except requests.RequestException as e:
            if attempt == 3:
                raise
            time.sleep(2 * attempt)
    return {}


# ── PRICE FETCHING ────────────────────────────────────────────────────────────
def fetch_usd_prices(coins: List[str]) -> Dict[str, float]:
    """Returns {ticker: usd_price} via Kraken Ticker (one request per coin)."""
    prices = {}
    for coin in coins:
        pair = KRAKEN_PAIRS.get(coin)
        if not pair:
            continue
        try:
            data   = _get(f"https://api.kraken.com/0/public/Ticker?pair={pair}")
            result = data.get("result", {})
            key    = next(iter(result))
            prices[coin] = float(result[key]["c"][0])  # c[0] = last trade price
        except Exception as e:
            log.warning(f"[PRICE] Kraken {coin.upper()} failed: {e}")
    return prices


def build_cross_prices(coins: List[str], prices: Dict[str, float]) -> Dict:
    """Compute cross-rates from USD prices — no extra API call, fully accurate.
    Returns {internal_id: {ticker: rate}} matching the shape analyze_arbitrage expects."""
    result = {}
    for coin in coins:
        if coin not in prices or prices[coin] == 0:
            continue
        cid = TICKER_TO_ID.get(coin)
        if not cid:
            continue
        result[cid] = {
            other: prices[coin] / prices[other]
            for other in coins
            if other in prices and other != coin and prices[other] > 0
        }
    return result


# ── TOP MARKET COINS ─────────────────────────────────────────────────────────
def fetch_top_coins(n: int = 10) -> List[str]:
    """Return top n Kraken-tradable non-stablecoin tickers ranked by market cap.
    Uses DB cache (1-hour TTL) to avoid hammering CoinPaprika every 5-min run."""

    # Check cache freshness
    try:
        with get_db() as conn:
            cached = conn.execute(
                "SELECT coin, rank FROM market_cap_cache WHERE updated_at >= datetime('now', '-1 hour') ORDER BY rank ASC"
            ).fetchall()
        if cached:
            result = [r["coin"] for r in cached if r["coin"] in KRAKEN_PAIRS][:n]
            if result:
                log.info(f"[TOP{n}] (cached) {', '.join(t.upper() for t in result)}")
                return result
    except Exception:
        pass

    # Cache miss or stale — fetch from CoinPaprika
    try:
        data = _get("https://api.coinpaprika.com/v1/tickers?limit=50")
        result = []
        cache_rows = []
        rank = 0
        for asset in data:
            ticker = asset["symbol"].lower()
            if ticker in STABLECOINS or ticker in SKIP_TICKERS or "_" in ticker:
                continue
            if ticker not in KRAKEN_PAIRS:
                continue
            rank += 1
            cache_rows.append((ticker, rank))
            result.append(ticker)
            if len(result) >= n:
                break

        # Update cache
        if cache_rows:
            ts = datetime.now(tz=timezone.utc).isoformat()
            with get_db() as conn:
                conn.execute("DELETE FROM market_cap_cache")
                conn.executemany(
                    "INSERT INTO market_cap_cache (coin, rank, updated_at) VALUES (?, ?, ?)",
                    [(c, r, ts) for c, r in cache_rows],
                )
                conn.commit()

        if result:
            log.info(f"[TOP{n}] {', '.join(t.upper() for t in result)}")
            return result
    except Exception as e:
        log.warning(f"[TOP{n}] CoinPaprika failed ({e}), using fallback list")
    return list(KRAKEN_PAIRS.keys())[:n]


# ── OHLC HISTORY ─────────────────────────────────────────────────────────────
_history_cache: Dict[str, List[float]] = {}

def fetch_market_history(coin: str, days: int = 30) -> List[float]:
    """Hourly closing prices — checks DB cache first, fetches only new candles from Kraken."""
    key = f"{coin}:{days}"
    if key in _history_cache:
        return _history_cache[key]

    pair = KRAKEN_PAIRS.get(coin)
    if not pair:
        _history_cache[key] = []
        return []

    cutoff_ts = int((datetime.now(tz=timezone.utc) - timedelta(days=days)).timestamp())

    try:
        # Check DB cache for existing data
        with get_db() as conn:
            cached = conn.execute(
                "SELECT timestamp, close FROM ohlc_cache WHERE coin=? AND timestamp >= ? ORDER BY timestamp ASC",
                (coin, cutoff_ts),
            ).fetchall()

        latest_cached_ts = cached[-1]["timestamp"] if cached else 0

        # Fetch from Kraken — always get full OHLC (Kraken returns ~720 candles max)
        # but we only insert candles newer than what we have cached
        data     = _get(f"https://api.kraken.com/0/public/OHLC?pair={pair}&interval=60")
        result   = data.get("result", {})
        ohlc_key = next((k for k in result if k != "last"), None)
        if not ohlc_key:
            raise ValueError("No OHLC data in response")

        candles = result[ohlc_key]
        new_rows = []
        for c in candles:
            ts = int(c[0])
            if ts > latest_cached_ts:
                new_rows.append((coin, ts, float(c[4])))

        # Insert new candles into cache
        if new_rows:
            with get_db() as conn:
                conn.executemany(
                    "INSERT OR IGNORE INTO ohlc_cache (coin, timestamp, close) VALUES (?, ?, ?)",
                    new_rows,
                )
                conn.commit()

        # Read full series from cache (now up to date)
        with get_db() as conn:
            rows = conn.execute(
                "SELECT close FROM ohlc_cache WHERE coin=? AND timestamp >= ? ORDER BY timestamp ASC",
                (coin, cutoff_ts),
            ).fetchall()
        prices = [r["close"] for r in rows]

        cache_hits = len(cached)
        new_count = len(new_rows)
        _history_cache[key] = prices
        log.info(f"[HISTORY] {coin.upper()}: {len(prices)} pts ({cache_hits} cached, {new_count} new)")
        return prices

    except Exception as e:
        # Fallback: try returning whatever is in the DB cache
        try:
            with get_db() as conn:
                rows = conn.execute(
                    "SELECT close FROM ohlc_cache WHERE coin=? AND timestamp >= ? ORDER BY timestamp ASC",
                    (coin, cutoff_ts),
                ).fetchall()
            if rows:
                prices = [r["close"] for r in rows]
                _history_cache[key] = prices
                log.warning(f"[HISTORY] {coin.upper()} Kraken failed ({e}), using {len(prices)} cached pts")
                return prices
        except Exception:
            pass
        log.warning(f"[HISTORY] {coin.upper()} failed: {e}")
        _history_cache[key] = []
        return []
