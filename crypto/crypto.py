#!/usr/bin/env python3
"""
Portfolio-Directed Crypto Analysis Engine
Modules: rebalance | pairs trading | arbitrage (Bellman-Ford) | momentum (RSI)

Entry point — orchestrates all modules. Run via cron: */5 * * * *
"""

import sys

from config import log
from database import setup_db, prune_db, load_portfolio, save_price_history, snapshot_pnl
from market_data import fetch_usd_prices, fetch_top_coins, build_cross_prices
from strategies import (
    analyze_rebalance, analyze_pairs, analyze_arbitrage, analyze_momentum,
    score_and_rank,
)
from trades import save_alerts, save_signal
from paper_portfolio import (
    load_paper_portfolio, compute_recommended_allocations,
    save_paper_allocations, check_stop_loss_take_profit,
    execute_paper_rebalance_trades, snapshot_paper_portfolio,
    snapshot_paper_trading,
)


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
    log.info("Fetching current prices...")
    prices = fetch_usd_prices(all_coins)
    if not prices:
        log.error("No prices fetched — check Kraken/CoinPaprika connectivity.")
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

    # Record daily P&L snapshot for long-term performance tracking
    snapshot_pnl()

    # Snapshot regular paper trading equity (hourly, always runs)
    snapshot_paper_trading(prices)

    # ── Recommended Allocations (always computed — used by both portfolios) ──
    paper_data = load_paper_portfolio()
    allocations = compute_recommended_allocations(prices, market_coins, ranked)
    if allocations:
        save_paper_allocations(allocations, prices, paper_data)
        log.info(f"[ALLOC] Updated recommended allocations for {len(allocations)} coins")

    # ── Paper Portfolio Management ────────────────────────────────
    if paper_data and paper_data["config"]["status"] == "active":
        pp = paper_data["config"]
        log.info("[PAPER] Running paper portfolio management...")

        # 1. Check stop-loss / take-profit
        risk_closed = check_stop_loss_take_profit(prices)
        if risk_closed:
            log.info(f"[PAPER] Risk management closed {risk_closed} position(s)")
            paper_data = load_paper_portfolio()  # reload after changes

        # 2. Execute rebalance trades to match allocations
        if allocations and paper_data:
            rebal_count = execute_paper_rebalance_trades(allocations, prices, paper_data)
            if rebal_count:
                log.info(f"[PAPER] Rebalanced: {rebal_count} trade(s)")
                paper_data = load_paper_portfolio()  # reload after trades

        # 3. Snapshot equity curve + analytics (hourly)
        snapshot_paper_portfolio(prices, market_coins)

        # Reload for final log
        paper_data = load_paper_portfolio()
        if paper_data:
            pp = paper_data["config"]
            log.info(f"[PAPER] Portfolio: ${pp['total_value']:,.2f} | "
                     f"Cash: ${pp['cash_balance']:,.2f} | "
                     f"Drawdown: {pp['max_drawdown']:.1f}%")

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
