"""
Paper portfolio management — loading, trading, risk management, allocations, snapshots.
"""

import json, math, statistics
from datetime import datetime, timezone
from typing import Dict, List

from config import (
    PAPER_CASH_RESERVE_PCT, PAPER_STOP_LOSS_PCT, PAPER_TAKE_PROFIT_PCT,
    PAPER_MARGIN_LIMIT, PAPER_REBALANCE_COOLDOWN_HRS,
    ALERT_STRENGTH_THRESHOLD, FEE_RATE, log
)
from database import get_db
from trades import _close_open_trades


# ── LOAD / INIT ──────────────────────────────────────────────────────────────
def load_paper_portfolio() -> dict | None:
    """Load paper portfolio config + holdings. Returns None if not funded."""
    with get_db() as conn:
        cfg = conn.execute("SELECT * FROM paper_portfolio_config WHERE id=1").fetchone()
        if not cfg:
            return None
        holdings = conn.execute("SELECT * FROM paper_portfolio_holdings").fetchall()
        return {
            "config": dict(cfg),
            "holdings": {r["coin"]: dict(r) for r in holdings},
        }


def init_paper_portfolio(amount: float) -> None:
    """Initialize or re-fund the paper portfolio. Clears holdings & allocations."""
    ts = datetime.now(tz=timezone.utc).isoformat()
    with get_db() as conn:
        conn.execute("DELETE FROM paper_portfolio_config")
        conn.execute("DELETE FROM paper_portfolio_holdings")
        conn.execute("DELETE FROM paper_portfolio_allocations")
        conn.execute("""
            INSERT INTO paper_portfolio_config
                (id, funded_amount, cash_balance, total_value, high_water_mark,
                 max_drawdown, cash_reserve_pct, stop_loss_pct, take_profit_pct,
                 margin_limit, status, created_at, updated_at)
            VALUES (1, ?, ?, ?, ?, 0.0, ?, ?, ?, ?, 'active', ?, ?)
        """, (amount, amount, amount, amount,
              PAPER_CASH_RESERVE_PCT, PAPER_STOP_LOSS_PCT, PAPER_TAKE_PROFIT_PCT,
              PAPER_MARGIN_LIMIT, ts, ts))
        conn.commit()
    log.info(f"[PAPER] Portfolio initialized with ${amount:,.2f}")


# ── TRADE UPDATES ────────────────────────────────────────────────────────────
def update_paper_portfolio_on_trade(conn, coin: str, action: str,
                                     amount_coin: float, price: float,
                                     amount_usd: float) -> bool:
    """Update paper portfolio state after a trade. Returns False if margin exceeded."""
    cfg = conn.execute("SELECT * FROM paper_portfolio_config WHERE id=1").fetchone()
    if not cfg or cfg["status"] != "active":
        return False

    ts = datetime.now(tz=timezone.utc).isoformat()
    cash = cfg["cash_balance"]
    margin_floor = -(cfg["margin_limit"] * cfg["funded_amount"])

    if action == "buy":
        new_cash = cash - amount_usd
        if new_cash < margin_floor:
            log.warning(f"[PAPER] Margin limit reached — skipping BUY {coin.upper()} ${amount_usd:.2f}")
            return False

        # Update or insert holding (VWAP avg entry price)
        existing = conn.execute(
            "SELECT amount, avg_entry_price, total_cost FROM paper_portfolio_holdings WHERE coin=?",
            (coin,)
        ).fetchone()

        if existing and existing["amount"] > 0:
            old_amt = existing["amount"]
            old_cost = existing["total_cost"]
            new_amt = old_amt + amount_coin
            new_cost = old_cost + amount_usd
            new_avg = new_cost / new_amt if new_amt > 0 else price
        else:
            new_amt = amount_coin
            new_cost = amount_usd
            new_avg = price

        conn.execute("""
            INSERT INTO paper_portfolio_holdings (coin, amount, avg_entry_price, total_cost, current_value, unrealized_pnl, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(coin) DO UPDATE SET
                amount=excluded.amount, avg_entry_price=excluded.avg_entry_price,
                total_cost=excluded.total_cost, current_value=excluded.current_value,
                unrealized_pnl=excluded.unrealized_pnl, updated_at=excluded.updated_at
        """, (coin, new_amt, round(new_avg, 8), round(new_cost, 2),
              round(new_amt * price, 2), round(new_amt * price - new_cost, 2), ts))

        conn.execute("UPDATE paper_portfolio_config SET cash_balance=?, updated_at=? WHERE id=1",
                      (round(new_cash, 2), ts))

    elif action == "sell":
        existing = conn.execute(
            "SELECT amount, avg_entry_price, total_cost FROM paper_portfolio_holdings WHERE coin=?",
            (coin,)
        ).fetchone()

        sell_amt = min(amount_coin, existing["amount"]) if existing else 0
        if sell_amt <= 0:
            # Short selling — just reduce cash and track negative position conceptually
            new_cash = cash + amount_usd
            conn.execute("UPDATE paper_portfolio_config SET cash_balance=?, updated_at=? WHERE id=1",
                          (round(new_cash, 2), ts))
            return True

        # Reduce holding proportionally
        old_amt = existing["amount"]
        old_cost = existing["total_cost"]
        sell_fraction = sell_amt / old_amt if old_amt > 0 else 1.0
        cost_basis = old_cost * sell_fraction
        realized_pnl = amount_usd - cost_basis

        new_amt = old_amt - sell_amt
        new_cost = old_cost - cost_basis

        if new_amt < 0.000000005:  # effectively zero
            conn.execute("DELETE FROM paper_portfolio_holdings WHERE coin=?", (coin,))
        else:
            new_avg = new_cost / new_amt if new_amt > 0 else existing["avg_entry_price"]
            conn.execute("""
                UPDATE paper_portfolio_holdings
                SET amount=?, avg_entry_price=?, total_cost=?, current_value=?, unrealized_pnl=?, updated_at=?
                WHERE coin=?
            """, (new_amt, round(new_avg, 8), round(new_cost, 2),
                  round(new_amt * price, 2), round(new_amt * price - new_cost, 2), ts, coin))

        new_cash = cash + amount_usd
        conn.execute("UPDATE paper_portfolio_config SET cash_balance=?, updated_at=? WHERE id=1",
                      (round(new_cash, 2), ts))

    return True


# ── RISK MANAGEMENT ──────────────────────────────────────────────────────────
def check_stop_loss_take_profit(prices: Dict[str, float]) -> int:
    """Auto-close positions that hit stop-loss or take-profit thresholds. Returns count closed."""
    with get_db() as conn:
        cfg = conn.execute("SELECT * FROM paper_portfolio_config WHERE id=1").fetchone()
        if not cfg or cfg["status"] != "active":
            return 0

        sl_pct = cfg["stop_loss_pct"]
        tp_pct = cfg["take_profit_pct"]
        holdings = conn.execute("SELECT * FROM paper_portfolio_holdings WHERE amount > 0").fetchall()
        ts = datetime.now(tz=timezone.utc).isoformat()
        count = 0

        for h in holdings:
            coin = h["coin"]
            price = prices.get(coin, 0)
            if price <= 0 or h["amount"] <= 0:
                continue

            current_val = h["amount"] * price
            cost = h["total_cost"]
            if cost <= 0:
                continue
            pnl_pct = ((current_val - cost) / cost) * 100

            risk_action = None
            if pnl_pct <= -sl_pct:
                risk_action = "stop_loss"
            elif pnl_pct >= tp_pct:
                risk_action = "take_profit"

            if risk_action:
                amount_usd = round(h["amount"] * price, 2)
                # Close the position in holdings
                update_paper_portfolio_on_trade(conn, coin, "sell", h["amount"], price, amount_usd)

                # Close any open paper_trades for this coin
                closed = conn.execute(
                    """UPDATE paper_trades SET status='closed', exit_price=?, closed_at=?
                       WHERE coin=? AND status='open'""",
                    (price, ts, coin)
                ).rowcount

                log.info(f"[RISK] {risk_action.upper()} triggered for {coin.upper()}: "
                         f"P&L {pnl_pct:+.1f}% | closed {closed} trade(s) at ${price:,.2f}")
                count += 1

        conn.commit()
    return count


# ── ALLOCATIONS ──────────────────────────────────────────────────────────────
def compute_recommended_allocations(prices: Dict[str, float],
                                     market_coins: List[str],
                                     ranked_signals: List[dict]) -> Dict[str, dict]:
    """Compute market-cap-weighted allocations adjusted by signal strength.
    Returns {coin: {recommended_pct, reason}}"""
    # 1. Base weights from market cap rankings
    with get_db() as conn:
        rankings = conn.execute(
            "SELECT coin, rank FROM market_cap_cache ORDER BY rank ASC"
        ).fetchall()

    rank_map = {r["coin"]: r["rank"] for r in rankings}
    tradable = [c for c in market_coins if c in prices and c in rank_map]

    if not tradable:
        return {}

    # Weight: rank 1 → 10, rank 2 → 9, ..., rank 10 → 1
    max_rank = max(rank_map.get(c, 10) for c in tradable)
    raw_weights = {}
    for coin in tradable:
        rank = rank_map.get(coin, max_rank)
        raw_weights[coin] = max(max_rank + 1 - rank, 1)

    total_weight = sum(raw_weights.values())
    equity_pct = 100.0 - PAPER_CASH_RESERVE_PCT  # 95%

    allocations = {}
    for coin in tradable:
        base_pct = (raw_weights[coin] / total_weight) * equity_pct
        allocations[coin] = {"pct": base_pct, "reasons": [f"mkt_cap_rank={rank_map.get(coin, '?')}"]}

    # 2. Signal adjustments
    for sig in ranked_signals:
        if sig["signal"] == "hold" or sig["strength"] < ALERT_STRENGTH_THRESHOLD:
            continue
        for coin in sig["coins"]:
            if coin not in allocations:
                continue
            adjustment = sig["strength"] * 5.0  # up to +5% at full strength
            if "sell" in sig["signal"]:
                adjustment = -adjustment
            allocations[coin]["pct"] += adjustment
            allocations[coin]["reasons"].append(
                f"{sig['strategy']}:{sig['signal']}(s={sig['strength']:.2f})"
            )

    # 3. Clamp [2%, 30%] per coin
    for coin in allocations:
        allocations[coin]["pct"] = max(2.0, min(30.0, allocations[coin]["pct"]))

    # 4. Re-normalize to equity_pct (95%)
    total = sum(a["pct"] for a in allocations.values())
    if total > 0:
        scale = equity_pct / total
        for coin in allocations:
            allocations[coin]["pct"] = round(allocations[coin]["pct"] * scale, 2)

    return {
        coin: {
            "recommended_pct": a["pct"],
            "reason": json.dumps(a["reasons"]),
        }
        for coin, a in allocations.items()
    }


def save_paper_allocations(allocations: Dict[str, dict],
                            prices: Dict[str, float],
                            paper_data: dict | None) -> None:
    """Save recommended allocations and compute actual allocations from holdings.
    Works even when paper_data is None (saves with actual_pct=0 for user portfolio recs)."""
    if paper_data:
        cfg = paper_data["config"]
        holdings = paper_data["holdings"]
        total_val = cfg["total_value"]
    else:
        holdings = {}
        total_val = 0

    ts = datetime.now(tz=timezone.utc).isoformat()

    with get_db() as conn:
        conn.execute("DELETE FROM paper_portfolio_allocations")
        for coin, alloc in allocations.items():
            holding_val = holdings.get(coin, {}).get("current_value", 0) or 0
            if holdings.get(coin):
                # Refresh current_value from live price
                amt = holdings[coin].get("amount", 0)
                price = prices.get(coin, 0)
                holding_val = amt * price if price > 0 else holding_val

            actual_pct = round((holding_val / total_val) * 100, 2) if total_val > 0 else 0
            recommended_pct = alloc["recommended_pct"]
            drift = round(actual_pct - recommended_pct, 2)

            conn.execute("""
                INSERT INTO paper_portfolio_allocations
                    (coin, recommended_pct, actual_pct, drift_pct, reason, updated_at)
                VALUES (?, ?, ?, ?, ?, ?)
            """, (coin, recommended_pct, actual_pct, drift, alloc["reason"], ts))
        conn.commit()


# ── REBALANCE EXECUTION ─────────────────────────────────────────────────────
def execute_paper_rebalance_trades(allocations: Dict[str, dict],
                                    prices: Dict[str, float],
                                    paper_data: dict) -> int:
    """Execute trades to rebalance paper portfolio toward target allocations.
    Sells overweight positions first, then buys underweight.
    Returns the number of trades executed."""
    cfg = paper_data["config"]
    holdings = paper_data["holdings"]
    total_val = cfg["total_value"]
    ts = datetime.now(tz=timezone.utc).isoformat()

    if total_val <= 0:
        return 0

    # Check rebalance cooldown
    last_rebal = cfg.get("last_rebalance_at")
    if last_rebal:
        try:
            last_dt = datetime.fromisoformat(last_rebal)
            elapsed_hrs = (datetime.now(tz=timezone.utc) - last_dt).total_seconds() / 3600
            if elapsed_hrs < PAPER_REBALANCE_COOLDOWN_HRS:
                log.info(f"[PAPER] Rebalance cooldown: {elapsed_hrs:.1f}h < {PAPER_REBALANCE_COOLDOWN_HRS}h — skipping")
                return 0
        except (ValueError, TypeError):
            pass  # Invalid timestamp, proceed with rebalance

    # Compute drift per coin
    drifts = {}  # {coin: drift_pct} where positive = overweight, negative = underweight
    for coin, alloc in allocations.items():
        recommended_pct = alloc["recommended_pct"]
        holding = holdings.get(coin)
        if holding and holding.get("amount", 0) > 0:
            price = prices.get(coin, 0)
            holding_val = holding["amount"] * price if price > 0 else 0
            actual_pct = (holding_val / total_val) * 100 if total_val > 0 else 0
        else:
            actual_pct = 0
        drift = actual_pct - recommended_pct
        drifts[coin] = drift

    # Check if any coin drifts enough to warrant rebalance (> 3%)
    max_drift = max(abs(d) for d in drifts.values()) if drifts else 0
    if max_drift < 3.0:
        log.info(f"[PAPER] Max drift {max_drift:.1f}% < 3% threshold — no rebalance needed")
        return 0

    count = 0
    with get_db() as conn:
        # Phase 1: SELL overweight positions (free up cash)
        sells = sorted(
            [(coin, drift) for coin, drift in drifts.items() if drift > 3.0],
            key=lambda x: x[1], reverse=True  # sell most overweight first
        )
        for coin, drift in sells:
            price = prices.get(coin, 0)
            if price <= 0:
                continue
            holding = holdings.get(coin)
            if not holding or holding.get("amount", 0) <= 0:
                continue

            # Sell amount to bring drift to 0
            sell_usd = abs(drift / 100) * total_val
            sell_usd_after_fee = sell_usd * (1 - FEE_RATE)  # receive less after fee
            sell_amt = round(sell_usd / price, 8)

            # Don't sell more than we hold
            sell_amt = min(sell_amt, holding["amount"])
            actual_sell_usd = round(sell_amt * price, 2)

            if actual_sell_usd < 5.0:  # minimum trade size
                continue

            if update_paper_portfolio_on_trade(conn, coin, "sell", sell_amt, price,
                                                round(actual_sell_usd * (1 - FEE_RATE), 2)):
                conn.execute("""
                    INSERT INTO paper_trades
                        (alert_id, timestamp, coin, action, entry_price, amount_coin, amount_usd, status)
                    VALUES (NULL, ?, ?, 'sell', ?, ?, ?, 'open')
                """, (ts, coin, price, sell_amt, actual_sell_usd))
                log.info(f"[PAPER] Rebalance SELL {coin.upper()} ${actual_sell_usd:.2f} "
                         f"(drift {drift:+.1f}%)")
                count += 1

        # Phase 2: BUY underweight positions (deploy freed cash)
        buys = sorted(
            [(coin, drift) for coin, drift in drifts.items() if drift < -3.0],
            key=lambda x: x[1]  # buy most underweight first
        )
        for coin, drift in buys:
            price = prices.get(coin, 0)
            if price <= 0:
                continue

            # Buy amount to bring drift to 0
            buy_usd = abs(drift / 100) * total_val
            buy_usd_with_fee = buy_usd * (1 + FEE_RATE)  # pay more with fee
            buy_amt = round(buy_usd / price, 8)

            if buy_usd < 5.0:  # minimum trade size
                continue

            if update_paper_portfolio_on_trade(conn, coin, "buy", buy_amt, price,
                                                round(buy_usd_with_fee, 2)):
                conn.execute("""
                    INSERT INTO paper_trades
                        (alert_id, timestamp, coin, action, entry_price, amount_coin, amount_usd, status)
                    VALUES (NULL, ?, ?, 'buy', ?, ?, ?, 'open')
                """, (ts, coin, price, buy_amt, round(buy_usd, 2)))
                log.info(f"[PAPER] Rebalance BUY {coin.upper()} ${buy_usd:.2f} "
                         f"(drift {drift:+.1f}%)")
                count += 1

        # Update last_rebalance_at
        if count > 0:
            conn.execute(
                "UPDATE paper_portfolio_config SET last_rebalance_at=?, updated_at=? WHERE id=1",
                (ts, ts)
            )

        conn.commit()
    return count


# ── SNAPSHOTS ────────────────────────────────────────────────────────────────
def snapshot_paper_portfolio(prices: Dict[str, float], market_coins: List[str]) -> None:
    """Snapshot paper portfolio value + analytics into history table (hourly)."""
    with get_db() as conn:
        cfg = conn.execute("SELECT * FROM paper_portfolio_config WHERE id=1").fetchone()
        if not cfg or cfg["status"] != "active":
            return

        ts = datetime.now(tz=timezone.utc).isoformat()
        hour_key = datetime.now(tz=timezone.utc).strftime("%Y-%m-%dT%H:00:00+00:00")

        # Recompute holdings values
        holdings = conn.execute("SELECT * FROM paper_portfolio_holdings").fetchall()
        holdings_value = 0.0
        for h in holdings:
            price = prices.get(h["coin"], 0)
            if price > 0 and h["amount"] > 0:
                val = h["amount"] * price
                cost = h["total_cost"]
                conn.execute("""
                    UPDATE paper_portfolio_holdings
                    SET current_value=?, unrealized_pnl=?, updated_at=?
                    WHERE coin=?
                """, (round(val, 2), round(val - cost, 2), ts, h["coin"]))
                holdings_value += val

        total_value = cfg["cash_balance"] + holdings_value
        hwm = max(cfg["high_water_mark"], total_value)
        drawdown = round(((hwm - total_value) / hwm) * 100, 2) if hwm > 0 else 0
        max_dd = max(cfg["max_drawdown"], drawdown)

        conn.execute("""
            UPDATE paper_portfolio_config
            SET total_value=?, high_water_mark=?, max_drawdown=?, updated_at=?
            WHERE id=1
        """, (round(total_value, 2), round(hwm, 2), round(max_dd, 2), ts))

        # Compute period + cumulative returns
        funded = cfg["funded_amount"]
        cumulative_return = round(((total_value - funded) / funded) * 100, 2) if funded > 0 else 0

        prev = conn.execute(
            "SELECT total_value FROM paper_portfolio_history ORDER BY recorded_at DESC LIMIT 1"
        ).fetchone()
        prev_val = prev["total_value"] if prev else funded
        period_return = round(((total_value - prev_val) / prev_val) * 100, 2) if prev_val > 0 else 0

        # Sharpe ratio — rolling 30 unique daily returns for stability
        # Get the latest hourly entry per calendar day (end-of-day value)
        daily_returns_rows = conn.execute("""
            SELECT period_return_pct
            FROM paper_portfolio_history
            WHERE period_return_pct IS NOT NULL
              AND recorded_at IN (
                  SELECT MAX(recorded_at)
                  FROM paper_portfolio_history
                  GROUP BY substr(recorded_at, 1, 10)
              )
            ORDER BY recorded_at DESC
            LIMIT 30
        """).fetchall()
        returns_list = [r["period_return_pct"] for r in daily_returns_rows
                        if r["period_return_pct"] is not None]
        returns_list.append(period_return)
        if len(returns_list) >= 5:
            mean_r = statistics.mean(returns_list)
            std_r = statistics.stdev(returns_list)
            sharpe = round((mean_r / std_r) * math.sqrt(365), 2) if std_r > 0 else 0
        else:
            sharpe = 0

        # BTC benchmark — what would $funded in BTC only have returned
        btc_return = 0
        btc_price_now = prices.get("btc", 0)
        if btc_price_now > 0:
            created = cfg["created_at"]
            if created:
                try:
                    btc_start = conn.execute(
                        "SELECT price_usd FROM price_history WHERE coin='btc' AND timestamp >= ? ORDER BY timestamp ASC LIMIT 1",
                        (created,)
                    ).fetchone()
                    if btc_start and btc_start["price_usd"] > 0:
                        btc_return = round(((btc_price_now - btc_start["price_usd"]) / btc_start["price_usd"]) * 100, 2)
                except Exception:
                    pass

        # Equal-weight benchmark
        eq_return = 0
        top_coins_with_price = [c for c in market_coins if c in prices and prices[c] > 0]
        if top_coins_with_price and cfg["created_at"]:
            try:
                returns_sum = 0
                valid_count = 0
                for c in top_coins_with_price[:10]:
                    start_price = conn.execute(
                        "SELECT price_usd FROM price_history WHERE coin=? AND timestamp >= ? ORDER BY timestamp ASC LIMIT 1",
                        (c, cfg["created_at"])
                    ).fetchone()
                    if start_price and start_price["price_usd"] > 0:
                        returns_sum += (prices[c] - start_price["price_usd"]) / start_price["price_usd"]
                        valid_count += 1
                if valid_count > 0:
                    eq_return = round((returns_sum / valid_count) * 100, 2)
            except Exception:
                pass

        # Count open positions and total trades
        open_pos = conn.execute("SELECT COUNT(*) FROM paper_portfolio_holdings WHERE amount > 0").fetchone()[0]
        trade_count = conn.execute("SELECT COUNT(*) FROM paper_trades").fetchone()[0]

        conn.execute("""
            INSERT INTO paper_portfolio_history
                (recorded_at, total_value, cash_balance, holdings_value, funded_amount,
                 period_return_pct, cumulative_return_pct, sharpe_ratio, max_drawdown,
                 btc_return_pct, equal_weight_return_pct, open_positions, trade_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(recorded_at) DO UPDATE SET
                total_value=excluded.total_value, cash_balance=excluded.cash_balance,
                holdings_value=excluded.holdings_value, period_return_pct=excluded.period_return_pct,
                cumulative_return_pct=excluded.cumulative_return_pct, sharpe_ratio=excluded.sharpe_ratio,
                max_drawdown=excluded.max_drawdown, btc_return_pct=excluded.btc_return_pct,
                equal_weight_return_pct=excluded.equal_weight_return_pct,
                open_positions=excluded.open_positions, trade_count=excluded.trade_count
        """, (hour_key, round(total_value, 2), round(cfg["cash_balance"], 2),
              round(holdings_value, 2), funded, period_return, cumulative_return,
              sharpe, round(max_dd, 2), btc_return, eq_return, open_pos, trade_count))
        conn.commit()

    log.info(f"[PAPER] Snapshot: ${total_value:,.2f} (return {cumulative_return:+.1f}%) "
             f"drawdown={max_dd:.1f}% sharpe={sharpe}")


def snapshot_paper_trading(prices: Dict[str, float]) -> None:
    """Snapshot regular paper trade portfolio value hourly (independent of paper portfolio)."""
    hour_key = datetime.now(tz=timezone.utc).strftime("%Y-%m-%dT%H:00:00+00:00")
    with get_db() as conn:
        trades = conn.execute("SELECT * FROM paper_trades").fetchall()
        if not trades:
            return

        open_value = 0.0
        closed_pnl = 0.0
        open_count = 0
        for t in trades:
            price = prices.get(t["coin"], 0)
            if t["status"] == "open" and price > 0:
                if t["action"] == "buy":
                    open_value += (price - t["entry_price"]) * t["amount_coin"]
                else:
                    open_value += (t["entry_price"] - price) * t["amount_coin"]
                open_count += 1
            elif t["status"] == "closed" and t["exit_price"]:
                if t["action"] == "buy":
                    closed_pnl += (t["exit_price"] - t["entry_price"]) * t["amount_coin"]
                else:
                    closed_pnl += (t["entry_price"] - t["exit_price"]) * t["amount_coin"]

        total = round(open_value + closed_pnl, 2)
        conn.execute("""
            INSERT INTO paper_trading_history
                (recorded_at, total_value, open_trade_value, closed_pnl, trade_count, open_count)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(recorded_at) DO UPDATE SET
                total_value=excluded.total_value, open_trade_value=excluded.open_trade_value,
                closed_pnl=excluded.closed_pnl, trade_count=excluded.trade_count,
                open_count=excluded.open_count
        """, (hour_key, total, round(open_value, 2), round(closed_pnl, 2),
              len(trades), open_count))
        conn.commit()
    log.info(f"[TRADES] Snapshot: P&L ${total:,.2f} ({open_count} open)")
