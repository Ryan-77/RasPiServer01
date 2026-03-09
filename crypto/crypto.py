#!/usr/bin/env python3
"""
Portfolio-Directed Crypto Analysis Engine
Modules: rebalance | pairs trading | arbitrage (Bellman-Ford) | momentum (RSI)
"""

import os, sys, json, math, time, sqlite3, logging, statistics, itertools
import requests
import networkx as nx
from datetime import datetime, timezone
from typing import Dict, List
from dotenv import load_dotenv

load_dotenv()

# ── CONFIG ─────────────────────────────────────────────────────────────────────
DB_PATH      = "/var/www/data/crypto.db"
LOG_FILE     = "/var/www/html/crypto/log.txt"

ARB_THRESHOLD            = float(os.getenv("ARBITRAGE_THRESHOLD",    "1.002"))
PAIRS_ZSCORE_ENTRY       = float(os.getenv("PAIRS_ZSCORE_ENTRY",     "2.0"))
MOMENTUM_RSI_HIGH        = float(os.getenv("MOMENTUM_RSI_HIGH",      "70.0"))
MOMENTUM_RSI_LOW         = float(os.getenv("MOMENTUM_RSI_LOW",       "30.0"))
REBALANCE_DRIFT          = float(os.getenv("REBALANCE_DRIFT_PCT",    "5.0"))
FEE_RATE                 = float(os.getenv("FEE_RATE",               "0.001"))
ALERT_STRENGTH_THRESHOLD = float(os.getenv("ALERT_STRENGTH_THRESHOLD","0.2"))
ALERT_DEDUP_HOURS        = int(os.getenv("ALERT_DEDUP_HOURS",         "1"))
PRICE_HISTORY_KEEP_DAYS  = int(os.getenv("PRICE_HISTORY_KEEP_DAYS",   "14"))
SIGNALS_KEEP_DAYS        = int(os.getenv("SIGNALS_KEEP_DAYS",         "90"))
ALERTS_KEEP_DAYS         = int(os.getenv("ALERTS_KEEP_DAYS",          "90"))
RSI_PERIOD          = 14
PAIRS_LOOKBACK_DAYS = 30

# CoinGecko id ↔ ticker mapping — broad set covering likely top-20 candidates
COIN_MAP: Dict[str, str] = {
    "bitcoin":            "btc",
    "ethereum":           "eth",
    "ripple":             "xrp",
    "binancecoin":        "bnb",
    "solana":             "sol",
    "dogecoin":           "doge",
    "cardano":            "ada",
    "tron":               "trx",
    "avalanche-2":        "avax",
    "chainlink":          "link",
    "the-open-network":   "ton",
    "sui":                "sui",
    "shiba-inu":          "shib",
    "polkadot":           "dot",
    "near":               "near",
    "litecoin":           "ltc",
    "bitcoin-cash":       "bch",
    "matic-network":      "matic",
    "stellar":            "xlm",
    "hedera-hashgraph":   "hbar",
}
TICKER_TO_ID = {v: k for k, v in COIN_MAP.items()}

# Stablecoins to skip when selecting top market coins
STABLECOINS = {"usdt", "usdc", "dai", "busd", "tusd", "fdusd", "usdp", "pyusd", "usde", "susde", "usds", "frax", "lusd", "gusd", "usdx"}

# Exchange tokens / wrapped assets / other junk to skip
SKIP_TICKERS = {"wbt", "wbtc", "leo", "ht", "okb", "gt", "kcs", "btt", "wemix", "nexo"}

# Binance Spot symbols for historical data (no rate limits, no auth)
BINANCE_SYMBOLS: Dict[str, str] = {
    "btc":  "BTCUSDT",  "eth":  "ETHUSDT",  "xrp":  "XRPUSDT",
    "bnb":  "BNBUSDT",  "sol":  "SOLUSDT",  "doge": "DOGEUSDT",
    "ada":  "ADAUSDT",  "trx":  "TRXUSDT",  "avax": "AVAXUSDT",
    "link": "LINKUSDT", "ton":  "TONUSDT",  "sui":  "SUIUSDT",
    "shib": "SHIBUSDT", "dot":  "DOTUSDT",  "near": "NEARUSDT",
    "ltc":  "LTCUSDT",  "bch":  "BCHUSDT",  "matic":"MATICUSDT",
    "xlm":  "XLMUSDT",  "hbar": "HBARUSDT",
}

# ── LOGGING ────────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler(sys.stdout),
    ],
)
log = logging.getLogger(__name__)

# ── DATABASE ───────────────────────────────────────────────────────────────────
def get_db() -> sqlite3.Connection:
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn

def setup_db() -> None:
    with get_db() as conn:
        conn.execute("""
            CREATE TABLE IF NOT EXISTS price_history (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp TEXT,
                coin      TEXT,
                price_usd REAL
            )
        """)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS analysis_signals (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp    TEXT,
                strategy     TEXT,
                coins        TEXT,
                signal       TEXT,
                strength     REAL,
                expected_usd REAL,
                details      TEXT
            )
        """)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS alerts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp    TEXT,
                strategy     TEXT,
                coins        TEXT,
                signal       TEXT,
                strength     REAL,
                expected_usd REAL,
                details      TEXT,
                status       TEXT DEFAULT 'new'
            )
        """)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS paper_trades (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                alert_id     INTEGER,
                timestamp    TEXT,
                coin         TEXT,
                action       TEXT,
                entry_price  REAL,
                amount_coin  REAL,
                amount_usd   REAL,
                status       TEXT DEFAULT 'open'
            )
        """)
        conn.commit()

def prune_db() -> None:
    """Delete old rows to keep the DB small on the Pi."""
    with get_db() as conn:
        r1 = conn.execute(
            f"DELETE FROM price_history WHERE timestamp < datetime('now', '-{PRICE_HISTORY_KEEP_DAYS} days')"
        )
        r2 = conn.execute(
            f"DELETE FROM analysis_signals WHERE timestamp < datetime('now', '-{SIGNALS_KEEP_DAYS} days')"
        )
        r3 = conn.execute(
            f"DELETE FROM alerts WHERE timestamp < datetime('now', '-{ALERTS_KEEP_DAYS} days')"
        )
        # Remove paper trades whose parent alert was pruned
        conn.execute("DELETE FROM paper_trades WHERE alert_id NOT IN (SELECT id FROM alerts)")
        conn.commit()
    # VACUUM must run outside any transaction
    raw = sqlite3.connect(DB_PATH)
    raw.isolation_level = None
    raw.execute("VACUUM")
    raw.close()
    log.info(
        f"[PRUNE] Removed: price_history={r1.rowcount}, signals={r2.rowcount}, alerts={r3.rowcount} rows. VACUUM done."
    )


def load_portfolio() -> Dict[str, Dict]:
    """Returns {coin: {amount, target_pct}}"""
    with get_db() as conn:
        rows = conn.execute(
            "SELECT coin, amount, target_pct FROM portfolio"
        ).fetchall()
    return {r["coin"]: {"amount": r["amount"], "target_pct": r["target_pct"]} for r in rows}

def save_price_history(prices: Dict[str, float]) -> None:
    ts = datetime.now(tz=timezone.utc).isoformat()
    with get_db() as conn:
        conn.executemany(
            "INSERT INTO price_history (timestamp, coin, price_usd) VALUES (?, ?, ?)",
            [(ts, coin, price) for coin, price in prices.items()],
        )
        conn.commit()

def save_alerts(ranked: List[dict], prices: Dict[str, float], portfolio: Dict) -> int:
    actionable = [s for s in ranked if s["strength"] > ALERT_STRENGTH_THRESHOLD and s["signal"] != "hold"]
    ts    = datetime.now(tz=timezone.utc).isoformat()
    count = 0
    with get_db() as conn:
        for sig in actionable:
            coins_str = ",".join(sig["coins"])
            # Skip if an identical alert already fired within the dedup window
            existing = conn.execute(
                """SELECT id FROM alerts WHERE strategy=? AND coins=?
                   AND timestamp >= datetime('now', ?)""",
                (sig["strategy"], coins_str, f"-{ALERT_DEDUP_HOURS} hours"),
            ).fetchone()
            if existing:
                log.info(f"[ALERT] Duplicate skipped: {sig['strategy']}/{coins_str}")
                continue

            cur = conn.execute(
                """INSERT INTO alerts (timestamp, strategy, coins, signal, strength, expected_usd, details, status)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 'new')""",
                (ts, sig["strategy"], coins_str, sig["signal"],
                 sig["strength"], sig["expected_usd"], json.dumps(sig.get("details", {}))),
            )
            alert_id = cur.lastrowid
            count += 1
            log.info(f"[ALERT] #{alert_id} {sig['strategy'].upper()} {coins_str} → {sig['signal']} strength={sig['strength']:.3f}")

            # Paper trades — rebalance and momentum: single coin
            if sig["strategy"] in ("rebalance", "momentum"):
                coin  = sig["coins"][0]
                price = prices.get(coin, 0)
                if price > 0:
                    notional  = portfolio[coin]["amount"] * price * 0.25 if (coin in portfolio and portfolio[coin]["amount"] > 0) else 1000.0
                    trade_amt = round(notional / price, 8)
                    conn.execute(
                        """INSERT INTO paper_trades
                           (alert_id, timestamp, coin, action, entry_price, amount_coin, amount_usd, status)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'open')""",
                        (alert_id, ts, coin, sig["signal"], price, trade_amt, round(notional, 2)),
                    )

            # Pairs: two opposing trades
            elif sig["strategy"] == "pairs" and len(sig["coins"]) == 2:
                details = sig.get("details", {})
                for coin, action in [
                    (sig["coins"][0], details.get("action_a", "buy")),
                    (sig["coins"][1], details.get("action_b", "sell")),
                ]:
                    price = prices.get(coin, 0)
                    if price > 0:
                        notional  = portfolio[coin]["amount"] * price * 0.25 if (coin in portfolio and portfolio[coin]["amount"] > 0) else 1000.0
                        trade_amt = round(notional / price, 8)
                        conn.execute(
                            """INSERT INTO paper_trades
                               (alert_id, timestamp, coin, action, entry_price, amount_coin, amount_usd, status)
                               VALUES (?, ?, ?, ?, ?, ?, ?, 'open')""",
                            (alert_id, ts, coin, action, price, trade_amt, round(notional, 2)),
                        )
            # Arbitrage cycles are skipped — too complex to simulate cleanly

        conn.commit()
    return count


def save_signal(strategy: str, coins: List[str], signal: str,
                strength: float, expected_usd: float, details: dict) -> None:
    ts = datetime.now(tz=timezone.utc).isoformat()
    with get_db() as conn:
        conn.execute(
            """INSERT INTO analysis_signals
               (timestamp, strategy, coins, signal, strength, expected_usd, details)
               VALUES (?, ?, ?, ?, ?, ?, ?)""",
            (ts, strategy, ",".join(coins), signal, strength, expected_usd, json.dumps(details)),
        )
        conn.commit()

# ── PRICE FETCHING ─────────────────────────────────────────────────────────────
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

def fetch_usd_prices(coins: List[str]) -> Dict[str, float]:
    """Returns {ticker: usd_price}"""
    ids = [TICKER_TO_ID[c] for c in coins if c in TICKER_TO_ID]
    data = _get(
        f"https://api.coingecko.com/api/v3/simple/price"
        f"?ids={','.join(ids)}&vs_currencies=usd"
    )
    return {COIN_MAP[cid]: data[cid]["usd"] for cid in ids if cid in data}

def build_cross_prices(coins: List[str], prices: Dict[str, float]) -> Dict:
    """Compute cross-rates from USD prices — no extra API call, fully accurate.
    Returns {coingecko_id: {ticker: rate}} matching the shape analyze_arbitrage expects."""
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

def fetch_top_coins(n: int = 10) -> List[str]:
    """Return top n non-stablecoin tickers by market cap. Dynamically updates COIN_MAP."""
    try:
        data = _get(
            "https://api.coingecko.com/api/v3/coins/markets"
            "?vs_currency=usd&order=market_cap_desc&per_page=50&page=1&sparkline=false"
        )
        result = []
        for coin in data:
            ticker = coin["symbol"].lower()
            # Skip stablecoins, exchange tokens, and anything with underscores (always junk)
            if ticker in STABLECOINS or ticker in SKIP_TICKERS or "_" in ticker:
                continue
            cid = coin["id"]
            # Register any unknown coin so our fetch functions can handle it
            if cid not in COIN_MAP:
                COIN_MAP[cid] = ticker
                TICKER_TO_ID[ticker] = cid
            result.append(ticker)
            if len(result) >= n:
                break
        log.info(f"[TOP{n}] {', '.join(t.upper() for t in result)}")
        return result
    except Exception as e:
        log.warning(f"[TOP{n}] API fetch failed ({e}), using fallback list")
        return ["btc", "eth", "xrp", "bnb", "sol", "doge", "ada", "trx", "avax", "link"]


_history_cache: Dict[str, List[float]] = {}

def fetch_market_history(coin: str, days: int = 30) -> List[float]:
    """Fetch hourly closing prices. Binance first (no rate limit), CoinGecko fallback."""
    key = f"{coin}:{days}"
    if key in _history_cache:
        return _history_cache[key]

    # ── Binance (primary: free, no auth, 1200 req/min) ────────────────────────
    symbol = BINANCE_SYMBOLS.get(coin)
    if symbol:
        try:
            limit = min(days * 24, 1000)
            data  = _get(
                f"https://api.binance.com/api/v3/klines"
                f"?symbol={symbol}&interval=1h&limit={limit}"
            )
            prices = [float(k[4]) for k in data]   # index 4 = close price
            _history_cache[key] = prices
            log.info(f"[HISTORY] {coin.upper()}: {len(prices)} pts over {days}d from Binance")
            return prices
        except Exception as e:
            log.warning(f"[HISTORY] {coin.upper()} Binance failed ({e}), trying CoinGecko")

    # ── CoinGecko (fallback for coins not on Binance) ─────────────────────────
    cid = TICKER_TO_ID.get(coin)
    if not cid:
        _history_cache[key] = []
        return []
    try:
        data   = _get(f"https://api.coingecko.com/api/v3/coins/{cid}/market_chart?vs_currency=usd&days={days}")
        prices = [p[1] for p in data.get("prices", [])]
        _history_cache[key] = prices
        log.info(f"[HISTORY] {coin.upper()}: {len(prices)} pts over {days}d from CoinGecko")
        return prices
    except Exception as e:
        log.warning(f"[HISTORY] {coin.upper()} CoinGecko failed ({e})")
        _history_cache[key] = []   # cache the failure — don't retry this run
        return []


# ── HELPERS ────────────────────────────────────────────────────────────────────
def zscore(series: List[float]) -> float:
    if len(series) < 5:
        return 0.0
    mean = statistics.mean(series)
    std  = statistics.stdev(series)
    return 0.0 if std == 0 else (series[-1] - mean) / std

def rsi(prices: List[float], period: int = RSI_PERIOD) -> float:
    if len(prices) < period + 1:
        return 50.0
    deltas = [prices[i] - prices[i - 1] for i in range(1, len(prices))]
    recent = deltas[-period:]          # last N deltas — same time window for both sides
    avg_g  = sum(d for d in recent if d > 0)  / period
    avg_l  = sum(-d for d in recent if d < 0) / period
    return 100.0 if avg_l == 0 else round(100 - (100 / (1 + avg_g / avg_l)), 2)

def roc(prices: List[float], period: int = 10) -> float:
    if len(prices) < period + 1:
        return 0.0
    return round(((prices[-1] - prices[-period - 1]) / prices[-period - 1]) * 100, 4)

# ── MODULE 1: REBALANCE ────────────────────────────────────────────────────────
def analyze_rebalance(portfolio: Dict, prices: Dict[str, float]) -> List[dict]:
    signals   = []
    total_usd = sum(portfolio[c]["amount"] * prices.get(c, 0) for c in portfolio)
    if total_usd == 0:
        return signals

    for coin, data in portfolio.items():
        price      = prices.get(coin, 0)
        target_pct = data.get("target_pct")
        if not price or target_pct is None:
            continue

        current_usd = data["amount"] * price
        current_pct = (current_usd / total_usd) * 100
        drift       = current_pct - target_pct

        if abs(drift) < REBALANCE_DRIFT:
            continue

        delta_usd  = ((target_pct / 100) * total_usd) - current_usd
        delta_coin = delta_usd / price
        action     = "sell" if drift > 0 else "buy"
        strength   = min(abs(drift) / 20.0, 1.0)

        signals.append({
            "strategy":     "rebalance",
            "coins":        [coin],
            "signal":       action,
            "strength":     round(strength, 4),
            "expected_usd": round(abs(delta_usd), 2),
            "details": {
                "current_pct": round(current_pct, 2),
                "target_pct":  round(target_pct,  2),
                "drift_pct":   round(drift,        2),
                "delta_coin":  round(abs(delta_coin), 8),
                "delta_usd":   round(abs(delta_usd),  2),
                "total_usd":   round(total_usd,        2),
            },
        })
        log.info(f"[REBALANCE] {coin.upper()}: {action} ${abs(delta_usd):.2f} (drift {drift:+.1f}%)")

    return signals

# ── MODULE 2: PAIRS TRADING ────────────────────────────────────────────────────
def analyze_pairs(portfolio: Dict, prices: Dict[str, float], market_coins: List[str]) -> List[dict]:
    signals = []
    coins   = [c for c in market_coins if c in prices]

    for coin_a, coin_b in itertools.combinations(coins, 2):
        hist_a = fetch_market_history(coin_a, PAIRS_LOOKBACK_DAYS)
        hist_b = fetch_market_history(coin_b, PAIRS_LOOKBACK_DAYS)

        n = min(len(hist_a), len(hist_b))
        if n < 10:
            log.info(f"[PAIRS] {coin_a}/{coin_b}: insufficient history ({n} pts), skipping")
            continue

        hist_a, hist_b = hist_a[-n:], hist_b[-n:]
        ratios  = [a / b for a, b in zip(hist_a, hist_b) if b]
        if not ratios:
            continue

        z             = zscore(ratios)
        current_ratio = prices[coin_a] / prices[coin_b]
        mean_ratio    = statistics.mean(ratios)

        if abs(z) < PAIRS_ZSCORE_ENTRY:
            continue

        # High ratio → coin_a expensive → sell A buy B; low ratio → opposite
        if z > 0:
            action_a, action_b = "sell", "buy"
            desc = f"{coin_a.upper()} overvalued vs {coin_b.upper()}"
        else:
            action_a, action_b = "buy", "sell"
            desc = f"{coin_a.upper()} undervalued vs {coin_b.upper()}"

        # Use portfolio value if held, else $1000 notional per side
        val_a     = portfolio[coin_a]["amount"] * prices[coin_a] if (coin_a in portfolio and portfolio[coin_a]["amount"] > 0) else 1000.0
        val_b     = portfolio[coin_b]["amount"] * prices[coin_b] if (coin_b in portfolio and portfolio[coin_b]["amount"] > 0) else 1000.0
        trade_usd = min(val_a, val_b) * 0.5
        strength  = min(abs(z) / 4.0, 1.0)

        signals.append({
            "strategy":     "pairs",
            "coins":        [coin_a, coin_b],
            "signal":       f"{action_a}_{coin_a}/{action_b}_{coin_b}",
            "strength":     round(strength, 4),
            "expected_usd": round(trade_usd, 2),
            "details": {
                "description":   desc,
                "action_a":      action_a,
                "action_b":      action_b,
                "zscore":        round(z, 4),
                "current_ratio": round(current_ratio, 6),
                "mean_ratio":    round(mean_ratio,    6),
                "lookback_days": PAIRS_LOOKBACK_DAYS,
                "trade_usd":     round(trade_usd, 2),
            },
        })
        log.info(f"[PAIRS] {coin_a}/{coin_b} z={z:.2f}: {desc}")

    return signals

# ── MODULE 3: ARBITRAGE (BELLMAN-FORD) ────────────────────────────────────────
def analyze_arbitrage(portfolio: Dict, cross_prices: Dict,
                       prices: Dict[str, float], market_coins: List[str]) -> List[dict]:
    signals = []
    coins   = [c for c in market_coins if c in prices]

    g = nx.DiGraph()
    for coin in coins:
        g.add_node(coin)

    for cid, vs_dict in cross_prices.items():
        src = COIN_MAP.get(cid)
        if src not in coins:
            continue
        for dst, rate in vs_dict.items():
            if dst not in coins or dst == src:
                continue
            try:
                effective = float(rate) * (1 - FEE_RATE)
                if effective > 0:
                    g.add_edge(src, dst, weight=-math.log(effective))
            except (TypeError, ValueError):
                continue

    # Virtual source for Bellman-Ford
    g.add_node("__s__")
    for node in coins:
        g.add_edge("__s__", node, weight=0)

    try:
        cycle = nx.find_negative_cycle(g, "__s__")
        cycle = [n for n in cycle if n != "__s__"]
        if not cycle:
            return signals

        # Compute round-trip factor
        factor = 1.0
        for u, v in zip(cycle[:-1], cycle[1:]):
            if g.has_edge(u, v):
                factor *= math.exp(-g[u][v]["weight"])

        if factor <= ARB_THRESHOLD:
            log.info(f"[ARBITRAGE] Best cycle factor {factor:.6f} below threshold {ARB_THRESHOLD}")
            return signals

        # Use portfolio value if held, else $1000 notional per coin in cycle
        held = [c for c in cycle if c in portfolio]
        trade_usd = min(
            (portfolio[c]["amount"] * prices.get(c, 0)) for c in held
        ) if held else 1000.0
        strength = min((factor - 1.0) / 0.01, 1.0)

        signals.append({
            "strategy":     "arbitrage",
            "coins":        cycle,
            "signal":       "opportunity",
            "strength":     round(strength, 4),
            "expected_usd": round(trade_usd * (factor - 1.0), 2),
            "details": {
                "cycle":         cycle,
                "factor":        round(factor, 8),
                "net_gain_pct":  round((factor - 1.0) * 100, 4),
                "threshold":     ARB_THRESHOLD,
                "fee_rate":      FEE_RATE,
                "trade_usd_est": round(trade_usd, 2),
            },
        })
        log.info(f"[ARBITRAGE] {' → '.join(cycle)} factor={factor:.6f} (+{(factor-1)*100:.3f}%)")

    except nx.NetworkXError:
        log.info("[ARBITRAGE] No negative cycle found in portfolio graph")
    finally:
        if "__s__" in g:
            g.remove_node("__s__")

    return signals

# ── MODULE 4: MOMENTUM (RSI + ROC) ────────────────────────────────────────────
def analyze_momentum(portfolio: Dict, prices: Dict[str, float], market_coins: List[str]) -> List[dict]:
    signals = []

    for coin in market_coins:
        if coin not in prices:
            continue

        history = fetch_market_history(coin, days=30)
        if len(history) < RSI_PERIOD + 2:
            log.info(f"[MOMENTUM] {coin.upper()}: insufficient history ({len(history)} pts), skipping")
            continue

        r   = rsi(history, RSI_PERIOD)
        roc_val = roc(history, 10)

        if r >= MOMENTUM_RSI_HIGH:
            signal, label = "sell", "overbought"
            strength = min((r - MOMENTUM_RSI_HIGH) / 30.0, 1.0)
        elif r <= MOMENTUM_RSI_LOW:
            signal, label = "buy", "oversold"
            strength = min((MOMENTUM_RSI_LOW - r) / 30.0, 1.0)
        else:
            signal, label, strength = "hold", "neutral", 0.0

        # Use portfolio value if held, else $1000 notional
        trade_usd = portfolio[coin]["amount"] * prices[coin] * 0.25 if (coin in portfolio and portfolio[coin]["amount"] > 0) else 1000.0

        signals.append({
            "strategy":     "momentum",
            "coins":        [coin],
            "signal":       signal,
            "strength":     round(strength, 4),
            "expected_usd": round(trade_usd, 2) if signal != "hold" else 0.0,
            "details": {
                "rsi":         r,
                "roc_10":      roc_val,
                "label":       label,
                "price_usd":   prices[coin],
                "history_pts": len(history),
            },
        })
        log.info(f"[MOMENTUM] {coin.upper()}: RSI={r} ROC={roc_val:+.2f}% → {signal} ({label})")

    return signals

# ── SCORING ────────────────────────────────────────────────────────────────────
WEIGHTS = {"arbitrage": 1.0, "pairs": 0.85, "rebalance": 0.70, "momentum": 0.60}

def score_and_rank(signals: List[dict]) -> List[dict]:
    for s in signals:
        s["score"] = round(s["strength"] * WEIGHTS.get(s["strategy"], 0.5), 4)
    return sorted(signals, key=lambda x: x["score"], reverse=True)

# ── MAIN ───────────────────────────────────────────────────────────────────────
def main() -> None:
    log.info("=" * 60)
    log.info("Portfolio-Directed Crypto Analysis Engine — START")

    setup_db()
    prune_db()

    portfolio = load_portfolio()
    if not portfolio:
        log.error("Portfolio is empty. Add coins via the web dashboard.")
        sys.exit(1)

    portfolio_str = ', '.join(f"{c.upper()}={v['amount']}" for c, v in portfolio.items())
    log.info(f"Portfolio: {portfolio_str}")

    log.info("Fetching top 10 market coins...")
    market_coins = fetch_top_coins(10)

    # Fetch prices for union of market coins + portfolio coins
    all_coins = list(set(list(portfolio.keys()) + market_coins))
    log.info("Fetching prices from CoinGecko...")
    try:
        prices = fetch_usd_prices(all_coins)
    except Exception as e:
        log.error(f"Price fetch failed: {e}")
        sys.exit(1)

    cross_prices = build_cross_prices(market_coins, prices)
    log.info(f"Prices: {', '.join(f'{c.upper()}=${p:,.2f}' for c, p in prices.items())}")
    save_price_history(prices)

    all_signals  = []
    all_signals += analyze_rebalance(portfolio, prices)           # portfolio only
    all_signals += analyze_pairs(portfolio, prices, market_coins)
    all_signals += analyze_arbitrage(portfolio, cross_prices, prices, market_coins)
    all_signals += analyze_momentum(portfolio, prices, market_coins)

    ranked = score_and_rank(all_signals)

    alert_count = save_alerts(ranked, prices, portfolio)
    log.info(f"Alerts: {alert_count} new alert(s) created")

    for sig in ranked:
        if sig["signal"] == "hold":
            continue
        save_signal(
            strategy=sig["strategy"],
            coins=sig["coins"],
            signal=sig["signal"],
            strength=sig["strength"],
            expected_usd=sig["expected_usd"],
            details=sig["details"],
        )

    log.info(f"Analysis complete — {len(ranked)} signal(s)")
    for i, s in enumerate(ranked, 1):
        coins_str = "/".join(c.upper() for c in s["coins"])
        log.info(
            f"  #{i} [{s['strategy'].upper():10}] {coins_str:15} "
            f"{s['signal']:25} score={s['score']:.3f}  ~${s['expected_usd']:,.2f}"
        )
    log.info("=" * 60)


if __name__ == "__main__":
    main()
