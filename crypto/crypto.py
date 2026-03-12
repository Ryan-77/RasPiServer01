#!/usr/bin/env python3
"""
Portfolio-Directed Crypto Analysis Engine
Modules: rebalance | pairs trading | arbitrage (Bellman-Ford) | momentum (RSI)
"""

import os, sys, json, math, time, sqlite3, logging, statistics, itertools
import requests
import networkx as nx
from datetime import datetime, timezone, timedelta
from typing import Dict, List
from dotenv import load_dotenv

load_dotenv()

# ── CONFIG ─────────────────────────────────────────────────────────────────────
DB_PATH      = "/var/www/data/crypto.db"
LOG_FILE     = "/var/www/html/crypto/log.txt"

ARB_THRESHOLD            = float(os.getenv("ARBITRAGE_THRESHOLD",    "1.002"))
PAIRS_ZSCORE_ENTRY       = float(os.getenv("PAIRS_ZSCORE_ENTRY",     "1.5"))   # z>1.5 SD = meaningful deviation
MOMENTUM_RSI_HIGH        = float(os.getenv("MOMENTUM_RSI_HIGH",      "65.0"))  # >65 overbought; need RSI~72 to clear alert threshold
MOMENTUM_RSI_LOW         = float(os.getenv("MOMENTUM_RSI_LOW",       "35.0"))  # <35 oversold
REBALANCE_DRIFT          = float(os.getenv("REBALANCE_DRIFT_PCT",    "5.0"))
FEE_RATE                 = float(os.getenv("FEE_RATE",               "0.001"))
ALERT_STRENGTH_THRESHOLD = float(os.getenv("ALERT_STRENGTH_THRESHOLD","0.2"))
ALERT_DEDUP_HOURS        = int(os.getenv("ALERT_DEDUP_HOURS",         "1"))
BASE_PAPER_TRADE         = float(os.getenv("BASE_PAPER_TRADE",        "1000.0"))  # max USD per trade at strength=1.0
PRICE_HISTORY_KEEP_DAYS  = int(os.getenv("PRICE_HISTORY_KEEP_DAYS",   "14"))
SIGNALS_KEEP_DAYS        = int(os.getenv("SIGNALS_KEEP_DAYS",         "90"))
ALERTS_KEEP_DAYS         = int(os.getenv("ALERTS_KEEP_DAYS",          "90"))
RSI_PERIOD          = 14
PAIRS_LOOKBACK_DAYS = 30

# Paper Portfolio defaults
PAPER_PORTFOLIO_DEFAULT_FUND = float(os.getenv("PAPER_PORTFOLIO_DEFAULT_FUND", "1000.0"))
PAPER_CASH_RESERVE_PCT       = float(os.getenv("PAPER_CASH_RESERVE_PCT",       "5.0"))
PAPER_STOP_LOSS_PCT          = float(os.getenv("PAPER_STOP_LOSS_PCT",          "10.0"))
PAPER_TAKE_PROFIT_PCT        = float(os.getenv("PAPER_TAKE_PROFIT_PCT",        "25.0"))
PAPER_MARGIN_LIMIT           = float(os.getenv("PAPER_MARGIN_LIMIT",           "0.30"))
PAPER_REBALANCE_COOLDOWN_HRS = int(os.getenv("PAPER_REBALANCE_COOLDOWN_HRS",   "6"))

# Internal id ↔ ticker mapping used by arbitrage graph (build_cross_prices / analyze_arbitrage)
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
    "uniswap":            "uni",
    "cosmos":             "atom",
    "algorand":           "algo",
    "aave":               "aave",
    "filecoin":           "fil",
}
TICKER_TO_ID = {v: k for k, v in COIN_MAP.items()}

# Kraken pair mapping — US-accessible, free, no auth, ~15 req/sec public limit
KRAKEN_PAIRS: Dict[str, str] = {
    "btc":  "XBTUSD",   # Bitcoin   (Kraken uses XBT internally)
    "eth":  "ETHUSD",   # Ethereum
    "xrp":  "XRPUSD",   # XRP
    "sol":  "SOLUSD",   # Solana
    "doge": "DOGEUSD",  # Dogecoin
    "ada":  "ADAUSD",   # Cardano
    "avax": "AVAXUSD",  # Avalanche
    "link": "LINKUSD",  # Chainlink
    "dot":  "DOTUSD",   # Polkadot
    "ltc":  "LTCUSD",   # Litecoin
    "uni":  "UNIUSD",   # Uniswap
    "atom": "ATOMUSD",  # Cosmos
    "near": "NEARUSD",  # NEAR Protocol
    "xlm":  "XLMUSD",   # Stellar
    "bch":  "BCHUSD",   # Bitcoin Cash
    "algo": "ALGOUSD",  # Algorand
    "aave": "AAVEUSD",  # Aave
    "fil":  "FILUSD",   # Filecoin
    "matic":"MATICUSD", # Polygon
    "hbar": "HBARUSD",  # Hedera
}

# Stablecoins to skip when selecting top market coins
STABLECOINS = {"usdt", "usdc", "dai", "busd", "tusd", "fdusd", "usdp", "pyusd", "usde", "susde", "usds", "frax", "lusd", "gusd", "usdx"}

# Exchange tokens / wrapped assets / other junk to skip
SKIP_TICKERS = {"wbt", "wbtc", "leo", "ht", "okb", "gt", "kcs", "btt", "wemix", "nexo"}


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
    conn.execute("PRAGMA journal_mode=WAL")
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
                status       TEXT DEFAULT 'open',
                exit_price   REAL,
                closed_at    TEXT
            )
        """)
        # OHLC cache — stores hourly candle data to avoid re-fetching full history each run
        conn.execute("""
            CREATE TABLE IF NOT EXISTS ohlc_cache (
                coin      TEXT,
                timestamp INTEGER,
                close     REAL,
                PRIMARY KEY (coin, timestamp)
            )
        """)
        # Market cap cache — avoids hitting CoinPaprika every 5-minute run
        conn.execute("""
            CREATE TABLE IF NOT EXISTS market_cap_cache (
                coin       TEXT PRIMARY KEY,
                rank       INTEGER,
                updated_at TEXT
            )
        """)
        # P&L snapshots — daily strategy performance that persists after trade pruning
        conn.execute("""
            CREATE TABLE IF NOT EXISTS pnl_snapshots (
                date        TEXT,
                strategy    TEXT,
                trade_count INTEGER,
                total_pnl   REAL,
                win_count   INTEGER,
                loss_count  INTEGER,
                PRIMARY KEY (date, strategy)
            )
        """)
        # Paper portfolio — engine-managed autonomous portfolio
        conn.execute("""
            CREATE TABLE IF NOT EXISTS paper_portfolio_config (
                id              INTEGER PRIMARY KEY CHECK (id = 1),
                funded_amount   REAL DEFAULT 1000.0,
                cash_balance    REAL DEFAULT 1000.0,
                total_value     REAL DEFAULT 1000.0,
                high_water_mark REAL DEFAULT 1000.0,
                max_drawdown    REAL DEFAULT 0.0,
                cash_reserve_pct REAL DEFAULT 5.0,
                stop_loss_pct   REAL DEFAULT 10.0,
                take_profit_pct REAL DEFAULT 25.0,
                margin_limit    REAL DEFAULT 0.30,
                status          TEXT DEFAULT 'active',
                last_rebalance_at TEXT,
                created_at      TEXT,
                updated_at      TEXT
            )
        """)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS paper_portfolio_holdings (
                coin            TEXT PRIMARY KEY,
                amount          REAL DEFAULT 0.0,
                avg_entry_price REAL DEFAULT 0.0,
                total_cost      REAL DEFAULT 0.0,
                current_value   REAL DEFAULT 0.0,
                unrealized_pnl  REAL DEFAULT 0.0,
                updated_at      TEXT
            )
        """)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS paper_portfolio_allocations (
                coin            TEXT PRIMARY KEY,
                recommended_pct REAL,
                actual_pct      REAL,
                drift_pct       REAL,
                reason          TEXT,
                updated_at      TEXT
            )
        """)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS paper_portfolio_history (
                date                    TEXT PRIMARY KEY,
                total_value             REAL,
                cash_balance            REAL,
                holdings_value          REAL,
                funded_amount           REAL,
                daily_return_pct        REAL,
                cumulative_return_pct   REAL,
                sharpe_ratio            REAL,
                max_drawdown            REAL,
                btc_return_pct          REAL,
                equal_weight_return_pct REAL,
                open_positions          INTEGER,
                trade_count_today       INTEGER
            )
        """)
        conn.commit()

        # Indexes — critical for query performance
        indexes = [
            "CREATE INDEX IF NOT EXISTS idx_price_coin_ts ON price_history(coin, timestamp DESC)",
            "CREATE INDEX IF NOT EXISTS idx_alerts_strat_coins_ts ON alerts(strategy, coins, timestamp DESC)",
            "CREATE INDEX IF NOT EXISTS idx_trades_coin_status ON paper_trades(coin, status)",
            "CREATE INDEX IF NOT EXISTS idx_alerts_status ON alerts(status)",
            "CREATE INDEX IF NOT EXISTS idx_ohlc_coin_ts ON ohlc_cache(coin, timestamp DESC)",
            "CREATE INDEX IF NOT EXISTS idx_paper_holdings_coin ON paper_portfolio_holdings(coin)",
            "CREATE INDEX IF NOT EXISTS idx_paper_history_date ON paper_portfolio_history(date DESC)",
        ]
        for idx_sql in indexes:
            try:
                conn.execute(idx_sql)
            except Exception:
                pass
        conn.commit()

        # Migrate existing tables — add columns if they don't exist yet
        for col, typedef in [("exit_price", "REAL"), ("closed_at", "TEXT")]:
            try:
                conn.execute(f"ALTER TABLE paper_trades ADD COLUMN {col} {typedef}")
                conn.commit()
                log.info(f"[DB] Migrated paper_trades: added column {col}")
            except Exception:
                pass  # Column already exists
        # Drop legacy tables no longer used
        try:
            conn.execute("DROP TABLE IF EXISTS arbitrage_results")
            conn.commit()
        except Exception:
            pass

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
        # Note: paper_trades are NOT cascade-deleted — closed trade history is retained indefinitely
        # Only prune closed paper trades older than 180 days to prevent unbounded growth
        conn.execute(
            "DELETE FROM paper_trades WHERE status='closed' AND closed_at < datetime('now', '-180 days')"
        )
        # Prune OHLC cache older than 45 days (buffer beyond 30-day lookback)
        cutoff_ts = int((datetime.now(tz=timezone.utc) - timedelta(days=45)).timestamp())
        conn.execute("DELETE FROM ohlc_cache WHERE timestamp < ?", (cutoff_ts,))
        # Prune stale market cap cache (safety cleanup, >24h old)
        conn.execute("DELETE FROM market_cap_cache WHERE updated_at < datetime('now', '-1 day')")
        # Prune paper portfolio history older than 1 year
        conn.execute("DELETE FROM paper_portfolio_history WHERE date < datetime('now', '-365 days')")
        conn.commit()
    # VACUUM must run outside any transaction
    raw = sqlite3.connect(DB_PATH)
    raw.isolation_level = None
    raw.execute("VACUUM")
    raw.close()
    log.info(
        f"[PRUNE] Removed: price_history={r1.rowcount}, signals={r2.rowcount}, alerts={r3.rowcount} rows. VACUUM done."
    )


def snapshot_pnl() -> None:
    """Record daily P&L stats per strategy into pnl_snapshots. Persists after trade pruning."""
    today = datetime.now(tz=timezone.utc).strftime("%Y-%m-%d")
    with get_db() as conn:
        rows = conn.execute("""
            SELECT COALESCE(a.strategy, 'unknown') as strategy,
                   COUNT(*) as cnt,
                   SUM(CASE WHEN (pt.exit_price - pt.entry_price) * pt.amount_coin > 0 THEN 1
                            WHEN pt.action = 'sell' AND (pt.entry_price - pt.exit_price) * pt.amount_coin > 0 THEN 1
                            ELSE 0 END) as wins,
                   SUM(CASE WHEN pt.action = 'buy'
                            THEN (pt.exit_price - pt.entry_price) * pt.amount_coin
                            ELSE (pt.entry_price - pt.exit_price) * pt.amount_coin END) as pnl
            FROM paper_trades pt
            LEFT JOIN alerts a ON pt.alert_id = a.id
            WHERE pt.status = 'closed' AND pt.closed_at >= ? AND pt.closed_at < datetime(?, '+1 day')
            GROUP BY a.strategy
        """, (today, today)).fetchall()

        for r in rows:
            losses = r["cnt"] - r["wins"]
            conn.execute("""
                INSERT INTO pnl_snapshots (date, strategy, trade_count, total_pnl, win_count, loss_count)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT(date, strategy) DO UPDATE SET
                    trade_count=excluded.trade_count, total_pnl=excluded.total_pnl,
                    win_count=excluded.win_count, loss_count=excluded.loss_count
            """, (today, r["strategy"], r["cnt"], round(r["pnl"] or 0, 2), r["wins"], losses))
        conn.commit()
    if rows:
        log.info(f"[PNL] Snapshot for {today}: {len(rows)} strategy(ies) recorded")


# ── PAPER PORTFOLIO ───────────────────────────────────────────────────────────

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
                            paper_data: dict) -> None:
    """Save recommended allocations and compute actual allocations from holdings."""
    cfg = paper_data["config"]
    holdings = paper_data["holdings"]
    total_val = cfg["total_value"]
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


def snapshot_paper_portfolio(prices: Dict[str, float], market_coins: List[str]) -> None:
    """Snapshot paper portfolio value + analytics into history table."""
    with get_db() as conn:
        cfg = conn.execute("SELECT * FROM paper_portfolio_config WHERE id=1").fetchone()
        if not cfg or cfg["status"] != "active":
            return

        ts = datetime.now(tz=timezone.utc).isoformat()
        today = datetime.now(tz=timezone.utc).strftime("%Y-%m-%d")

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

        # Compute daily + cumulative returns
        funded = cfg["funded_amount"]
        cumulative_return = round(((total_value - funded) / funded) * 100, 2) if funded > 0 else 0

        prev = conn.execute(
            "SELECT total_value FROM paper_portfolio_history ORDER BY date DESC LIMIT 1"
        ).fetchone()
        prev_val = prev["total_value"] if prev else funded
        daily_return = round(((total_value - prev_val) / prev_val) * 100, 2) if prev_val > 0 else 0

        # Sharpe ratio — rolling 30-day
        daily_returns = conn.execute(
            "SELECT daily_return_pct FROM paper_portfolio_history ORDER BY date DESC LIMIT 30"
        ).fetchall()
        returns_list = [r["daily_return_pct"] for r in daily_returns if r["daily_return_pct"] is not None]
        returns_list.append(daily_return)  # include today
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
                    # Get BTC price at fund time from price_history
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

        # Count open positions and today's trades
        open_pos = conn.execute("SELECT COUNT(*) FROM paper_portfolio_holdings WHERE amount > 0").fetchone()[0]
        today_trades = conn.execute(
            "SELECT COUNT(*) FROM paper_trades WHERE timestamp >= ?", (today,)
        ).fetchone()[0]

        conn.execute("""
            INSERT INTO paper_portfolio_history
                (date, total_value, cash_balance, holdings_value, funded_amount,
                 daily_return_pct, cumulative_return_pct, sharpe_ratio, max_drawdown,
                 btc_return_pct, equal_weight_return_pct, open_positions, trade_count_today)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(date) DO UPDATE SET
                total_value=excluded.total_value, cash_balance=excluded.cash_balance,
                holdings_value=excluded.holdings_value, daily_return_pct=excluded.daily_return_pct,
                cumulative_return_pct=excluded.cumulative_return_pct, sharpe_ratio=excluded.sharpe_ratio,
                max_drawdown=excluded.max_drawdown, btc_return_pct=excluded.btc_return_pct,
                equal_weight_return_pct=excluded.equal_weight_return_pct,
                open_positions=excluded.open_positions, trade_count_today=excluded.trade_count_today
        """, (today, round(total_value, 2), round(cfg["cash_balance"], 2),
              round(holdings_value, 2), funded, daily_return, cumulative_return,
              sharpe, round(max_dd, 2), btc_return, eq_return, open_pos, today_trades))
        conn.commit()

    log.info(f"[PAPER] Snapshot: ${total_value:,.2f} (return {cumulative_return:+.1f}%) "
             f"drawdown={max_dd:.1f}% sharpe={sharpe}")


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

def _close_open_trades(conn, coin: str, action: str, exit_price: float, ts: str) -> int:
    """Close any open trades for `coin` with the given action at exit_price. Returns count closed."""
    r = conn.execute(
        """UPDATE paper_trades SET status='closed', exit_price=?, closed_at=?
           WHERE coin=? AND action=? AND status='open'""",
        (exit_price, ts, coin, action),
    )
    return r.rowcount


def save_alerts(ranked: List[dict], prices: Dict[str, float], portfolio: Dict) -> int:
    actionable = [s for s in ranked if s["strength"] > ALERT_STRENGTH_THRESHOLD and s["signal"] != "hold"]
    ts    = datetime.now(tz=timezone.utc).isoformat()
    count = 0
    with get_db() as conn:
        # Load paper portfolio for portfolio-aware sizing
        pp_cfg = conn.execute("SELECT * FROM paper_portfolio_config WHERE id=1").fetchone()
        pp_active = pp_cfg and pp_cfg["status"] == "active"

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

            # Position sizing — portfolio-aware if paper portfolio is active
            if pp_active:
                # Risk 2-10% of portfolio per trade, scaled by signal strength
                pp_total = pp_cfg["total_value"] or PAPER_PORTFOLIO_DEFAULT_FUND
                notional_base = round(pp_total * sig["strength"] * 0.10, 2)
                notional_base = max(notional_base, 10.0)  # minimum $10 trade
            else:
                # Legacy flat sizing
                notional_base = round(BASE_PAPER_TRADE * sig["strength"], 2)

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

                    # Paper portfolio: check margin before opening
                    if pp_active and action == "buy":
                        if not update_paper_portfolio_on_trade(conn, coin, action, trade_amt, price, notional):
                            log.info(f"[TRADE] Skipped — margin limit reached for {coin.upper()}")
                            continue
                    elif pp_active and action == "sell":
                        update_paper_portfolio_on_trade(conn, coin, action, trade_amt, price, notional)

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

                        # Paper portfolio integration
                        if pp_active:
                            if not update_paper_portfolio_on_trade(conn, coin, action, trade_amt, price, notional):
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


# ── HELPERS ────────────────────────────────────────────────────────────────────
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

    # ── Paper Portfolio Management ────────────────────────────────
    paper_data = load_paper_portfolio()
    if paper_data and paper_data["config"]["status"] == "active":
        pp = paper_data["config"]
        log.info("[PAPER] Running paper portfolio management...")

        # 1. Check stop-loss / take-profit
        risk_closed = check_stop_loss_take_profit(prices)
        if risk_closed:
            log.info(f"[PAPER] Risk management closed {risk_closed} position(s)")
            paper_data = load_paper_portfolio()  # reload after changes

        # 2. Compute and save recommended allocations
        allocations = compute_recommended_allocations(prices, market_coins, ranked)
        if allocations and paper_data:
            save_paper_allocations(allocations, prices, paper_data)
            log.info(f"[PAPER] Updated allocations for {len(allocations)} coins")

        # 3. Snapshot equity curve + analytics
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
