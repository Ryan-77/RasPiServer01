"""
Alert creation, paper trade lifecycle management, signal persistence.
"""

import json
from datetime import datetime, timezone
from typing import Dict, List

from config import (
    ALERT_STRENGTH_THRESHOLD, ALERT_DEDUP_HOURS,
    PAPER_PORTFOLIO_DEFAULT_FUND, FEE_RATE, log
)
from database import get_db


def _close_open_trades(conn, coin: str, action: str, exit_price: float, ts: str) -> int:
    """Close any open trades for `coin` with the given action at exit_price. Returns count closed."""
    r = conn.execute(
        """UPDATE paper_trades SET status='closed', exit_price=?, closed_at=?
           WHERE coin=? AND action=? AND status='open'""",
        (exit_price, ts, coin, action),
    )
    return r.rowcount


def save_alerts(ranked: List[dict], prices: Dict[str, float], portfolio: Dict) -> int:
    # Lazy import to avoid circular dependency (paper_portfolio imports trades)
    from paper_portfolio import load_paper_portfolio, update_paper_portfolio_on_trade

    actionable = [s for s in ranked if s["strength"] > ALERT_STRENGTH_THRESHOLD and s["signal"] != "hold"]
    ts    = datetime.now(tz=timezone.utc).isoformat()
    count = 0
    with get_db() as conn:
        # Load paper portfolio for portfolio-aware sizing
        pp_cfg = conn.execute("SELECT * FROM paper_portfolio_config WHERE id=1").fetchone()
        pp_active = pp_cfg and pp_cfg["status"] == "active"
        pp_paused = pp_cfg and pp_cfg["status"] == "paused"

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

            # Skip paper trade creation when no portfolio is configured or paused
            if not pp_cfg:
                log.info(f"[TRADE] Skipped — no paper portfolio configured")
                continue
            if pp_paused:
                log.info(f"[TRADE] Skipped — portfolio is paused")
                continue

            # Position sizing — always portfolio-aware (no legacy fallback)
            pp_total = pp_cfg["total_value"] or PAPER_PORTFOLIO_DEFAULT_FUND
            notional_base = round(pp_total * sig["strength"] * 0.10, 2)
            notional_base = max(notional_base, 10.0)  # minimum $10 trade

            # Paper trades — rebalance and momentum: single coin
            if sig["strategy"] in ("rebalance", "momentum"):
                coin   = sig["coins"][0]
                price  = prices.get(coin, 0)
                action = sig["signal"]  # "buy" or "sell"
                if price > 0:
                    # Auto-close any open trades in the opposite direction for this coin
                    opposite = "sell" if action == "buy" else "buy"
                    closed = _close_open_trades(conn, coin, opposite, price, ts)
                    if closed:
                        log.info(f"[TRADE] Auto-closed {closed} open {opposite.upper()} trade(s) for {coin.upper()} at ${price:,.2f}")
                        # Update paper portfolio holdings for auto-close
                        if pp_active:
                            # Estimate USD from closed trades
                            closed_trades = conn.execute(
                                "SELECT amount_coin FROM paper_trades WHERE coin=? AND action=? AND status='closed' AND closed_at=?",
                                (coin, opposite, ts)
                            ).fetchall()
                            for ct in closed_trades:
                                close_usd = round(ct["amount_coin"] * price, 2)
                                update_paper_portfolio_on_trade(conn, coin, "sell" if opposite == "buy" else "buy",
                                                                ct["amount_coin"], price, close_usd)

                    notional  = notional_base
                    trade_amt = round(notional / price, 8)

                    # Paper portfolio: apply fee and check margin before opening
                    if pp_active and action == "buy":
                        fee_adj = round(notional * (1 + FEE_RATE), 2)
                        if not update_paper_portfolio_on_trade(conn, coin, action, trade_amt, price, fee_adj):
                            log.info(f"[TRADE] Skipped — margin limit reached for {coin.upper()}")
                            continue
                    elif pp_active and action == "sell":
                        fee_adj = round(notional * (1 - FEE_RATE), 2)
                        update_paper_portfolio_on_trade(conn, coin, action, trade_amt, price, fee_adj)

                    conn.execute(
                        """INSERT INTO paper_trades
                           (alert_id, timestamp, coin, action, entry_price, amount_coin, amount_usd, status)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'open')""",
                        (alert_id, ts, coin, action, price, trade_amt, notional),
                    )
                    log.info(f"[TRADE] Opened {action.upper()} {coin.upper()} ${notional:.2f} (strength={sig['strength']:.3f})")

            # Pairs: two opposing trades
            elif sig["strategy"] == "pairs" and len(sig["coins"]) == 2:
                details = sig.get("details", {})
                for coin, action in [
                    (sig["coins"][0], details.get("action_a", "buy")),
                    (sig["coins"][1], details.get("action_b", "sell")),
                ]:
                    price = prices.get(coin, 0)
                    if price > 0:
                        # Auto-close same coin's opposite open positions
                        opposite = "sell" if action == "buy" else "buy"
                        closed = _close_open_trades(conn, coin, opposite, price, ts)
                        if closed:
                            log.info(f"[TRADE] Auto-closed {closed} open {opposite.upper()} trade(s) for {coin.upper()} at ${price:,.2f}")

                        notional  = notional_base
                        trade_amt = round(notional / price, 8)

                        # Paper portfolio integration (with fee)
                        if pp_active:
                            fee_adj = round(notional * (1 + FEE_RATE), 2) if action == "buy" else round(notional * (1 - FEE_RATE), 2)
                            if not update_paper_portfolio_on_trade(conn, coin, action, trade_amt, price, fee_adj):
                                log.info(f"[TRADE] Skipped — margin limit for {coin.upper()}")
                                continue

                        conn.execute(
                            """INSERT INTO paper_trades
                               (alert_id, timestamp, coin, action, entry_price, amount_coin, amount_usd, status)
                               VALUES (?, ?, ?, ?, ?, ?, ?, 'open')""",
                            (alert_id, ts, coin, action, price, trade_amt, notional),
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
