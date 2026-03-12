"""
Strategy engines — rebalance, pairs trading, arbitrage (Bellman-Ford), momentum (RSI).
Plus scoring/ranking.
"""

import math, statistics, itertools
import networkx as nx
from typing import Dict, List

from config import (
    REBALANCE_DRIFT, PAIRS_ZSCORE_ENTRY, PAIRS_LOOKBACK_DAYS,
    MOMENTUM_RSI_HIGH, MOMENTUM_RSI_LOW, RSI_PERIOD,
    ARB_THRESHOLD, FEE_RATE, BASE_PAPER_TRADE,
    ALERT_STRENGTH_THRESHOLD, WEIGHTS,
    COIN_MAP, TICKER_TO_ID, log
)
from market_data import fetch_market_history
from indicators import zscore, rsi, roc


# ── MODULE 1: REBALANCE ──────────────────────────────────────────────────────
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


# ── MODULE 2: PAIRS TRADING ──────────────────────────────────────────────────
def analyze_pairs(portfolio: Dict, prices: Dict[str, float], market_coins: List[str]) -> List[dict]:
    signals = []
    coins   = [c for c in market_coins if c in prices and prices[c] > 0]

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

        # Use portfolio value if held, else BASE_PAPER_TRADE notional per side
        val_a     = portfolio[coin_a]["amount"] * prices[coin_a] if (coin_a in portfolio and portfolio[coin_a]["amount"] > 0) else BASE_PAPER_TRADE
        val_b     = portfolio[coin_b]["amount"] * prices[coin_b] if (coin_b in portfolio and portfolio[coin_b]["amount"] > 0) else BASE_PAPER_TRADE
        trade_usd = min(val_a, val_b) * 0.5
        # Strength proportional to z-score: z=1.5→0.375, z=2.0→0.5, z=4.0→1.0
        # At z=PAIRS_ZSCORE_ENTRY (1.5) strength=0.375 which clears the 0.2 alert threshold
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
    coins   = [c for c in market_coins if c in prices and prices[c] > 0]

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


# ── MODULE 4: MOMENTUM (RSI + ROC) ───────────────────────────────────────────
def analyze_momentum(portfolio: Dict, prices: Dict[str, float], market_coins: List[str]) -> List[dict]:
    """
    Generates buy/sell signals from Wilder's RSI + rate-of-change confirmation.

    Thresholds:
      - RSI > MOMENTUM_RSI_HIGH (65) → overbought → sell candidate
      - RSI < MOMENTUM_RSI_LOW  (35) → oversold   → buy  candidate
    Strength scales from 0.0 at the threshold up to 1.0 at extremes.
    ROC agreement adds up to +0.25 extra strength (e.g. falling price confirms sell).
    An alert fires only when strength > ALERT_STRENGTH_THRESHOLD (0.2), meaning:
      - Sell needs RSI ≈ 72+ (or RSI 69+ with strong negative ROC)
      - Buy  needs RSI ≈ 28- (or RSI 31- with strong positive ROC)
    """
    signals = []

    for coin in market_coins:
        if coin not in prices:
            continue

        history = fetch_market_history(coin, days=30)
        if len(history) < RSI_PERIOD + 2:
            log.info(f"[MOMENTUM] {coin.upper()}: insufficient history ({len(history)} pts), skipping")
            continue

        r       = rsi(history, RSI_PERIOD)
        roc_val = roc(history, 10)   # % change over last 10 hours

        if r >= MOMENTUM_RSI_HIGH:
            signal, label = "sell", "overbought"
            # Scales 0.0 at RSI=65 → 1.0 at RSI=100
            rsi_strength = (r - MOMENTUM_RSI_HIGH) / (100.0 - MOMENTUM_RSI_HIGH)
            # ROC confirmation: falling price (-roc_val > 0) boosts sell confidence
            roc_confirm  = max(-roc_val, 0.0) / 10.0   # 10% drop = 1.0 confirm
            strength     = min(rsi_strength + roc_confirm * 0.25, 1.0)

        elif r <= MOMENTUM_RSI_LOW:
            signal, label = "buy", "oversold"
            # Scales 0.0 at RSI=35 → 1.0 at RSI=0
            rsi_strength = (MOMENTUM_RSI_LOW - r) / MOMENTUM_RSI_LOW
            # ROC confirmation: rising price (+roc_val > 0) boosts buy confidence
            roc_confirm  = max(roc_val, 0.0) / 10.0
            strength     = min(rsi_strength + roc_confirm * 0.25, 1.0)

        else:
            signal, label, strength = "hold", "neutral", 0.0

        trade_usd = (portfolio[coin]["amount"] * prices[coin] * 0.25
                     if (coin in portfolio and portfolio[coin]["amount"] > 0)
                     else BASE_PAPER_TRADE)

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
                "rsi_high":    MOMENTUM_RSI_HIGH,
                "rsi_low":     MOMENTUM_RSI_LOW,
                "price_usd":   prices[coin],
                "history_pts": len(history),
            },
        })
        log.info(f"[MOMENTUM] {coin.upper()}: RSI={r:.1f} ROC={roc_val:+.2f}% → {signal} ({label}) strength={strength:.3f}")

    return signals


# ── SCORING ───────────────────────────────────────────────────────────────────
def score_and_rank(signals: List[dict]) -> List[dict]:
    for s in signals:
        s["score"] = round(s["strength"] * WEIGHTS.get(s["strategy"], 0.5), 4)
    return sorted(signals, key=lambda x: x["score"], reverse=True)
