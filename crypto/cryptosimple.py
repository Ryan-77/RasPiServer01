#!/usr/bin/env python3
"""
cryptosimple.py — Sanity check script.
Fetches exactly the data points we need for analysis, prints to simple.txt.
Uses Kraken public API (free, no auth, US-accessible, 720 candles per request).
"""

import requests
from datetime import datetime, timezone

OUTPUT_FILE = "simple.txt"

# ── TOP 10 COINS ───────────────────────────────────────────────────────────────
# Format: ticker → Kraken pair name
# Note: Kraken uses XBT for Bitcoin. BNB/TRX not listed on Kraken.
# Replaced with SOL and DOT which are available.

COINS = {
    "btc":  "XBTUSD",   # 1  Bitcoin   (Kraken calls it XBT)
    "eth":  "ETHUSD",   # 2  Ethereum
    "xrp":  "XRPUSD",   # 3  XRP
    "sol":  "SOLUSD",   # 4  Solana
    "doge": "DOGEUSD",  # 5  Dogecoin
    "ada":  "ADAUSD",   # 6  Cardano
    "avax": "AVAXUSD",  # 7  Avalanche
    "link": "LINKUSD",  # 8  Chainlink
    "dot":  "DOTUSD",   # 9  Polkadot
    "ltc":  "LTCUSD",   # 10 Litecoin
}

# ── DATA POINTS WE NEED ────────────────────────────────────────────────────────
# For each coin we need:
#   1. current_price  — live USD price
#   2. history_30d    — 720 hourly closes (30 days) for RSI and pairs z-score
#      - RSI(14)  : >70 overbought (sell), <30 oversold (buy)
#      - ROC(10)  : rate of change over 10 periods


def fetch_price(pair: str) -> float:
    """Current price from Kraken Ticker endpoint."""
    r = requests.get(
        f"https://api.kraken.com/0/public/Ticker?pair={pair}",
        timeout=10
    )
    r.raise_for_status()
    result = r.json()["result"]
    key = next(iter(result))          # internal key e.g. "XXBTZUSD"
    return float(result[key]["c"][0]) # c[0] = last trade price


def fetch_history(pair: str, hours: int = 720) -> list:
    """Hourly OHLC from Kraken. Returns up to 720 closes in one request."""
    r = requests.get(
        f"https://api.kraken.com/0/public/OHLC?pair={pair}&interval=60",
        timeout=15
    )
    r.raise_for_status()
    result = r.json()["result"]
    key = next(k for k in result if k != "last")  # skip the "last" timestamp key
    candles = result[key]
    closes = [float(c[4]) for c in candles]        # index 4 = close price
    return closes[-hours:]                          # trim to requested window


def rsi(prices: list, period: int = 14) -> float:
    if len(prices) < period + 1:
        return 50.0
    deltas = [prices[i] - prices[i - 1] for i in range(1, len(prices))]
    recent = deltas[-period:]
    avg_g  = sum(d for d in recent if d > 0)  / period
    avg_l  = sum(-d for d in recent if d < 0) / period
    return 100.0 if avg_l == 0 else round(100 - (100 / (1 + avg_g / avg_l)), 2)


def roc(prices: list, period: int = 10) -> float:
    if len(prices) < period + 1:
        return 0.0
    return round(((prices[-1] - prices[-period - 1]) / prices[-period - 1]) * 100, 4)


# ── MAIN ───────────────────────────────────────────────────────────────────────
def main():
    lines = []
    lines.append(f"cryptosimple.py — {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')} UTC")
    lines.append("=" * 60)

    results = {}
    for ticker, pair in COINS.items():
        print(f"Fetching {ticker.upper()} ({pair})...")
        try:
            price   = fetch_price(pair)
            history = fetch_history(pair)
            r       = rsi(history)
            r10     = roc(history)
            results[ticker] = True
            lines.append(
                f"{ticker.upper():6}  price=${price:>12,.4f}  "
                f"history={len(history):>3}pts  RSI={r:>6.2f}  ROC={r10:>+7.4f}%"
            )
        except Exception as e:
            results[ticker] = False
            lines.append(f"{ticker.upper():6}  ERROR: {e}")

    lines.append("")
    lines.append("FETCH SUMMARY")
    lines.append("-" * 60)
    ok  = [t for t, v in results.items() if v]
    err = [t for t, v in results.items() if not v]
    lines.append(f"  Success: {', '.join(t.upper() for t in ok)}")
    if err:
        lines.append(f"  Failed:  {', '.join(t.upper() for t in err)}")

    output = "\n".join(lines) + "\n"
    with open(OUTPUT_FILE, "w") as f:
        f.write(output)

    print(f"\nWrote {OUTPUT_FILE}")
    print(output)


if __name__ == "__main__":
    main()
