#!/usr/bin/env python3
"""
Portfolio-Directed Crypto Analysis Engine
Modules: rebalance | pairs trading | arbitrage (Bellman-Ford) | momentum (RSI)
"""

import os, sys, json, math, time, sqlite3, logging, statistics, itertools
import requests
import networkx as nx
from datetime import datetime, timezone
from zoneinfo import ZoneInfo
from typing import Dict, List, Optional, Tuple
from dotenv import load_dotenv

load_dotenv()

# ── CONFIG ─────────────────────────────────────────────────────────────────────
DB_PATH      = "/var/www/data/crypto.db"
LOG_FILE     = "/var/www/html/crypto/log.txt"
TZ           = ZoneInfo("America/Denver")

ARB_THRESHOLD       = float(os.getenv("ARBITRAGE_THRESHOLD",   "1.002"))
PAIRS_ZSCORE_ENTRY  = float(os.getenv("PAIRS_ZSCORE_ENTRY",    "2.0"))
MOMENTUM_RSI_HIGH   = float(os.getenv("MOMENTUM_RSI_HIGH",     "70.0"))
MOMENTUM_RSI_LOW    = float(os.getenv("MOMENTUM_RSI_LOW",      "30.0"))
REBALANCE_DRIFT     = float(os.getenv("REBALANCE_DRIFT_PCT",   "5.0"))
FEE_RATE            = float(os.getenv("FEE_RATE",              "0.001"))
RSI_PERIOD          = 14
PAIRS_LOOKBACK_DAYS = 30

# CoinGecko id ↔ ticker mapping
COIN_MAP: Dict[str, str] = {
    "bitcoin":       "btc",
    "ethereum":      "eth",
    "litecoin":      "ltc",
    "ripple":        "xrp",
    "bitcoin-cash":  "bch",
    "chainlink":     "link",
    "solana":        "sol",
    "cardano":       "ada",
    "avalanche-2":   "avax",
    "dogecoin":      "doge",
    "polkadot":      "dot",
    "matic-network": "matic",
}
TICKER_TO_ID = {v: k for k, v in COIN_MAP.items()}

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

def load_price_history(coin: str, days: int = 30) -> List[float]:
    with get_db() as conn:
        rows = conn.execute(
            """SELECT price_usd FROM price_history
               WHERE coin = ? AND timestamp >= datetime('now', ?)
               ORDER BY timestamp ASC""",
            (coin, f"-{days} days"),
        ).fetchall()
    return [r["price_usd"] for r in rows]

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

def fetch_cross_prices(coins: List[str]) -> Dict:
    """Returns raw CoinGecko response with cross-rates between all coins."""
    ids = [TICKER_TO_ID[c] for c in coins if c in TICKER_TO_ID]
    vs  = list(COIN_MAP.values())  # all tickers as vs_currencies
    return _get(
        f"https://api.coingecko.com/api/v3/simple/price"
        f"?ids={','.join(ids)}&vs_currencies=usd,{','.join(vs)}"
    )

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
    gains  = [d for d in deltas if d > 0]
    losses = [-d for d in deltas if d < 0]
    avg_g  = sum(gains[-period:])  / period if gains  else 0.0
    avg_l  = sum(losses[-period:]) / period if losses else 0.0
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
def analyze_pairs(portfolio: Dict, prices: Dict[str, float]) -> List[dict]:
    signals = []
    coins   = [c for c in portfolio if c in prices]

    for coin_a, coin_b in itertools.combinations(coins, 2):
        hist_a = load_price_history(coin_a, PAIRS_LOOKBACK_DAYS)
        hist_b = load_price_history(coin_b, PAIRS_LOOKBACK_DAYS)

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

        val_a     = portfolio[coin_a]["amount"] * prices[coin_a]
        val_b     = portfolio[coin_b]["amount"] * prices[coin_b]
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
                       prices: Dict[str, float]) -> List[dict]:
    signals = []
    coins   = list(portfolio.keys())

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

        trade_usd = min(
            (portfolio.get(c, {}).get("amount", 0) * prices.get(c, 0))
            for c in cycle if c in portfolio
        )
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
def analyze_momentum(portfolio: Dict, prices: Dict[str, float]) -> List[dict]:
    signals = []

    for coin in portfolio:
        if coin not in prices:
            continue

        history = load_price_history(coin, days=30)
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

        trade_usd = portfolio[coin]["amount"] * prices[coin] * 0.25

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

    portfolio = load_portfolio()
    if not portfolio:
        log.error("Portfolio is empty. Add coins via the web dashboard.")
        sys.exit(1)

    coins = list(portfolio.keys())
    portfolio_str = ', '.join(f"{c.upper()}={v['amount']}" for c, v in portfolio.items())
    log.info(f"Portfolio: {portfolio_str}")

    log.info("Fetching prices from CoinGecko...")
    try:
        prices       = fetch_usd_prices(coins)
        cross_prices = fetch_cross_prices(coins)
    except Exception as e:
        log.error(f"Price fetch failed: {e}")
        sys.exit(1)

    log.info(f"Prices: {', '.join(f'{c.upper()}=${p:,.2f}' for c, p in prices.items())}")
    save_price_history(prices)

    all_signals  = []
    all_signals += analyze_rebalance(portfolio, prices)
    all_signals += analyze_pairs(portfolio, prices)
    all_signals += analyze_arbitrage(portfolio, cross_prices, prices)
    all_signals += analyze_momentum(portfolio, prices)

    ranked = score_and_rank(all_signals)

    for sig in ranked:
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
