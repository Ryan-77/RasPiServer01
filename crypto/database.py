"""
Database connection, schema setup, pruning, P&L snapshots, portfolio loading.
"""

import sqlite3
from datetime import datetime, timezone, timedelta
from typing import Dict

from config import (
    DB_PATH, PRICE_HISTORY_KEEP_DAYS, SIGNALS_KEEP_DAYS, ALERTS_KEEP_DAYS, log
)


# ── CONNECTION ────────────────────────────────────────────────────────────────
def get_db() -> sqlite3.Connection:
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    return conn


# ── SCHEMA ────────────────────────────────────────────────────────────────────
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
                recorded_at             TEXT PRIMARY KEY,
                total_value             REAL,
                cash_balance            REAL,
                holdings_value          REAL,
                funded_amount           REAL,
                period_return_pct       REAL,
                cumulative_return_pct   REAL,
                sharpe_ratio            REAL,
                max_drawdown            REAL,
                btc_return_pct          REAL,
                equal_weight_return_pct REAL,
                open_positions          INTEGER,
                trade_count             INTEGER
            )
        """)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS paper_trading_history (
                recorded_at      TEXT PRIMARY KEY,
                total_value      REAL,
                open_trade_value REAL,
                closed_pnl       REAL,
                trade_count      INTEGER,
                open_count       INTEGER
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
            "CREATE INDEX IF NOT EXISTS idx_paper_history_ts ON paper_portfolio_history(recorded_at DESC)",
            "CREATE INDEX IF NOT EXISTS idx_paper_trading_history_ts ON paper_trading_history(recorded_at DESC)",
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
        # Migrate paper_portfolio_history: old schema used 'date' PK, new uses 'recorded_at'
        try:
            conn.execute("SELECT date FROM paper_portfolio_history LIMIT 1")
            # Old schema exists — drop and recreate (data loss acceptable for dev)
            conn.execute("DROP TABLE paper_portfolio_history")
            conn.execute("""
                CREATE TABLE paper_portfolio_history (
                    recorded_at             TEXT PRIMARY KEY,
                    total_value             REAL,
                    cash_balance            REAL,
                    holdings_value          REAL,
                    funded_amount           REAL,
                    period_return_pct       REAL,
                    cumulative_return_pct   REAL,
                    sharpe_ratio            REAL,
                    max_drawdown            REAL,
                    btc_return_pct          REAL,
                    equal_weight_return_pct REAL,
                    open_positions          INTEGER,
                    trade_count             INTEGER
                )
            """)
            conn.commit()
            log.info("[DB] Migrated paper_portfolio_history: date → recorded_at (hourly)")
        except Exception:
            pass  # Already migrated or table is new

        # Drop legacy tables no longer used
        try:
            conn.execute("DROP TABLE IF EXISTS arbitrage_results")
            conn.commit()
        except Exception:
            pass


# ── PRUNING ───────────────────────────────────────────────────────────────────
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
        # Prune paper portfolio history older than 1 year (hourly data)
        conn.execute("DELETE FROM paper_portfolio_history WHERE recorded_at < datetime('now', '-365 days')")
        # Prune paper trading history older than 1 year
        conn.execute("DELETE FROM paper_trading_history WHERE recorded_at < datetime('now', '-365 days')")
        conn.commit()
    # VACUUM must run outside any transaction
    raw = sqlite3.connect(DB_PATH)
    raw.isolation_level = None
    raw.execute("VACUUM")
    raw.close()
    log.info(
        f"[PRUNE] Removed: price_history={r1.rowcount}, signals={r2.rowcount}, alerts={r3.rowcount} rows. VACUUM done."
    )


# ── SNAPSHOTS & PORTFOLIO ────────────────────────────────────────────────────
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
