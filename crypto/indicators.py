"""
Technical indicators — pure math functions with no side effects.
"""

import statistics
from typing import List

from config import RSI_PERIOD


def zscore(series: List[float]) -> float:
    if len(series) < 5:
        return 0.0
    mean = statistics.mean(series)
    std  = statistics.stdev(series)
    return 0.0 if std == 0 else (series[-1] - mean) / std


def rsi(prices: List[float], period: int = RSI_PERIOD) -> float:
    """Wilder's Smoothed RSI — matches TradingView / most charting platforms."""
    if len(prices) < period + 1:
        return 50.0
    deltas = [prices[i] - prices[i - 1] for i in range(1, len(prices))]
    gains  = [max(d, 0.0) for d in deltas]
    losses = [max(-d, 0.0) for d in deltas]
    # Seed with simple average of the first period
    avg_g = sum(gains[:period]) / period
    avg_l = sum(losses[:period]) / period
    # Wilder's exponential smoothing for remaining candles
    for g, l in zip(gains[period:], losses[period:]):
        avg_g = (avg_g * (period - 1) + g) / period
        avg_l = (avg_l * (period - 1) + l) / period
    return 100.0 if avg_l == 0 else round(100 - (100 / (1 + avg_g / avg_l)), 2)


def roc(prices: List[float], period: int = 10) -> float:
    if len(prices) < period + 1:
        return 0.0
    return round(((prices[-1] - prices[-period - 1]) / prices[-period - 1]) * 100, 4)
