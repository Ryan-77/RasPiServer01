# Ryan Bayles — Full Project Reference

## About Ryan
- **Name:** Ryan Bayles | **Email:** ryanrollup@gmail.com
- **Web server:** Apache, root at `/var/www/html/`
- **Symlink:** `~/html-project` → `/var/www/html/` (use this for Cowork access)
- **Everything in `/var/www/html/` is one git repo** — changes across projects share commit history
- **DB storage path:** `/var/www/data/crypto.db` (outside web root, not `/var/www/html/crypto/crypto.db` which is a dev copy)

---

## Project 1: Personal Portfolio Site (`/var/www/html/`)

**Stack:** Plain HTML/CSS, no JS framework
**Theme:** Dark, modern

**Pages:**
- `index.html` — home/landing
- `about.html` — about Ryan
- `contact.html` — contact page
- `calc.html` — calculator tool
- `loancalc.html` — loan calculator
- `ski.html` — skiing hobby page
- `js1.html` — JS playground/scratch
- `images/temp.html` — temp scratch

**Assets:**
- `css/styles.css` — shared stylesheet for all pages
- `images/` — `banner.jpg`, `home.jpg`, `snowboarding_1.jpg`

---

## Project 2: Crypto Trading Platform (`/var/www/html/crypto/`)

### Overview
A self-hosted, full-stack personal crypto portfolio tracker and algorithmic trading simulation. PHP serves the dashboard and REST API; Python runs the analysis engine every 5 minutes via cron.

**Cron:** `*/5 * * * * /var/www/html/crypto/venv/bin/python /var/www/html/crypto/crypto.py`

---

### File Structure
```
crypto/
├── crypto.py            # Main Python entrypoint — orchestrates all modules
├── config.php           # Shared PHP config (DB path, coin list, weights)
├── config.py            # Shared Python config (all env vars, thresholds, coin maps)
├── strategies.py        # 4 strategy engines (rebalance, pairs, arbitrage, momentum)
├── market_data.py       # External API calls (Kraken, CoinPaprika), OHLC caching
├── indicators.py        # Pure math: zscore(), rsi(), roc()
├── database.py          # DB connection, schema setup, pruning, P&L snapshots
├── paper_portfolio.py   # Paper portfolio: trading, risk mgmt, allocations, snapshots
├── trades.py            # Alert creation, paper trade lifecycle, signal persistence
├── crypto.php           # PHP dashboard (SPA-style with tab routing)
├── api.php              # REST API (GET + POST endpoints, CORS enabled)
├── db.php               # PHP DB helper (loads db/ modules)
├── helpers.php          # PHP utility functions
├── db/                  # Modular PHP DB layer
│   ├── core.php         # DB connection
│   ├── portfolio.php    # portfolio CRUD
│   ├── prices.php       # latest prices, price history
│   ├── signals.php      # analysis_signals read/write
│   ├── alerts.php       # alerts CRUD (get, dismiss, count unseen)
│   ├── trades.php       # paper_trades CRUD, P&L compute, close/reset
│   ├── paper.php        # paper portfolio summary, history, performance
│   └── summary.php      # getDashboardSummary(), getStrategyPerformance()
├── pages/               # PHP dashboard tab partials
│   ├── portfolio.php
│   ├── alerts.php
│   ├── analysis.php
│   └── log.php
├── assets/
│   ├── app.js           # Frontend JS for dashboard SPA
│   └── style.css        # Dashboard CSS
├── coininfo.html        # Standalone coin info page
├── coininfo_cache.php   # Coin info cache handler
├── log.txt              # Runtime log (last 200 lines shown in dashboard)
├── crypto.db            # DEV COPY of SQLite DB (production DB is /var/www/data/crypto.db)
└── venv/                # Python virtualenv
```

---

### Python Config (`config.py`) — All Key Constants

| Constant | Default | Description |
|---|---|---|
| `DB_PATH` | `/var/www/data/crypto.db` | Production SQLite path |
| `ARB_THRESHOLD` | `1.002` | Min round-trip factor to fire arbitrage alert |
| `PAIRS_ZSCORE_ENTRY` | `1.5` | Min z-score for pairs trade signal |
| `MOMENTUM_RSI_HIGH` | `65.0` | Overbought threshold (sell candidate) |
| `MOMENTUM_RSI_LOW` | `35.0` | Oversold threshold (buy candidate) |
| `REBALANCE_DRIFT` | `5.0%` | Min drift % to trigger rebalance signal |
| `FEE_RATE` | `0.001` | 0.1% trade fee applied to all paper trades |
| `ALERT_STRENGTH_THRESHOLD` | `0.2` | Min signal strength to create an alert |
| `ALERT_DEDUP_HOURS` | `1` | Dedup window — suppress duplicate alerts |
| `BASE_PAPER_TRADE` | `$1000` | Max USD per trade at strength=1.0 |
| `PRICE_HISTORY_KEEP_DAYS` | `14` | Price history retention |
| `SIGNALS_KEEP_DAYS` | `90` | Signal history retention |
| `ALERTS_KEEP_DAYS` | `90` | Alert history retention |
| `RSI_PERIOD` | `14` | Wilder's RSI lookback |
| `PAIRS_LOOKBACK_DAYS` | `30` | Pairs trading history window |
| `PAPER_CASH_RESERVE_PCT` | `5.0%` | Cash kept out of play |
| `PAPER_STOP_LOSS_PCT` | `10.0%` | Auto-close position if loss exceeds this |
| `PAPER_TAKE_PROFIT_PCT` | `25.0%` | Auto-close position if gain exceeds this |
| `PAPER_MARGIN_LIMIT` | `0.30` | Max 30% of funded amount in margin |
| `PAPER_REBALANCE_COOLDOWN_HRS` | `1` | Min time between paper portfolio rebalances |

**Strategy weights:** `arbitrage=1.0`, `pairs=0.85`, `rebalance=0.70`, `momentum=0.60`

---

### Supported Coins (20)
`btc, eth, xrp, bnb, sol, doge, ada, trx, avax, link, ton, sui, shib, dot, near, ltc, bch, matic, xlm, hbar`

**Plus extended for Python analysis:** `uni (Uniswap), atom (Cosmos), algo (Algorand), aave (Aave), fil (Filecoin)`

**Kraken pairs mapped for all:** Uses Kraken public API (no auth, ~15 req/sec limit). BTC maps to `XBTUSD` (Kraken uses XBT internally).

---

### External APIs
- **Kraken** (`api.kraken.com/0/public/`) — prices (`/Ticker`), OHLC history (`/OHLC?interval=60` = hourly candles, ~720 candles max per call)
- **CoinPaprika** (`api.coinpaprika.com/v1/tickers?limit=50`) — top-N market cap ranking, cached 1 hour in `market_cap_cache` table
- **CoinGecko** — used by CryptoLens frontend only (not by the Python engine)

---

### SQLite Schema (`database.py`)

**Tables:**

| Table | Purpose |
|---|---|
| `portfolio` | User's actual holdings: `coin, amount, target_pct, updated_at` |
| `price_history` | Every price fetch: `timestamp, coin, price_usd` |
| `analysis_signals` | All signals (including hold): `timestamp, strategy, coins, signal, strength, expected_usd, details (JSON)` |
| `alerts` | Actionable signals (strength > 0.2): same columns + `status (new/seen/dismissed)` |
| `paper_trades` | Trade log: `alert_id, timestamp, coin, action, entry_price, amount_coin, amount_usd, status, exit_price, closed_at` |
| `ohlc_cache` | Hourly candle cache: `coin, timestamp (unix), close` — 45-day retention |
| `market_cap_cache` | Top-N coin rankings: `coin, rank, updated_at` — 1-hour TTL |
| `pnl_snapshots` | Daily P&L per strategy: `date, strategy, trade_count, total_pnl, win_count, loss_count` |
| `paper_portfolio_config` | Single-row config: `funded_amount, cash_balance, total_value, high_water_mark, max_drawdown, stop_loss_pct, take_profit_pct, cash_reserve_pct, margin_limit, status, last_rebalance_at` |
| `paper_portfolio_holdings` | Engine holdings: `coin, amount, avg_entry_price, total_cost, current_value, unrealized_pnl` |
| `paper_portfolio_allocations` | Target vs actual: `coin, recommended_pct, actual_pct, drift_pct, reason` |
| `paper_portfolio_history` | Hourly equity snapshots: `recorded_at, total_value, cash_balance, holdings_value, period_return_pct, cumulative_return_pct, sharpe_ratio, max_drawdown, btc_return_pct, equal_weight_return_pct, open_positions, trade_count` |
| `paper_trading_history` | Signal-based trade equity curve: `recorded_at, total_value, open_trade_value, closed_pnl, trade_count, open_count` |

**DB size limit:** 10 GB. Two-phase pruner: age-based first (always runs), then size-based (oldest rows from largest tables). VACUUM only runs if rows were deleted.

**Key indexes:** `idx_price_coin_ts`, `idx_alerts_strat_coins_ts`, `idx_trades_coin_status`, `idx_ohlc_coin_ts`, `idx_paper_history_ts`

---

### Strategy Engines (`strategies.py`)

#### 1. Rebalance (`analyze_rebalance`)
- Compares each portfolio coin's actual % vs `target_pct`
- Fires if `|drift| >= REBALANCE_DRIFT (5%)`
- `strength = min(|drift| / 20.0, 1.0)` — maxes at 20% drift
- Signal: `"buy"` or `"sell"` with `delta_usd` to restore target

#### 2. Pairs Trading (`analyze_pairs`)
- Tests every combination of market coins using 30-day hourly history
- Computes ratio series `(price_a / price_b)`, then z-score of that ratio
- Fires if `|z| >= 1.5 (PAIRS_ZSCORE_ENTRY)`
- `strength = min(|z| / 4.0, 1.0)` — z=1.5→0.375, z=4.0→1.0
- Signal: `"sell_A/buy_B"` or `"buy_A/sell_B"` based on direction of deviation
- Skip pairs with < 10 data points

#### 3. Arbitrage (`analyze_arbitrage`)
- Builds a directed graph from cross-rates (computed from USD prices — no extra API call)
- Uses `networkx.find_negative_cycle()` with a virtual source node (Bellman-Ford)
- Effective rate = `rate × (1 - FEE_RATE)`, stored as `-log(effective)` on edges
- Fires if round-trip factor > `ARB_THRESHOLD (1.002)` — i.e. >0.2% net profit after fees
- `strength = min((factor - 1.0) / 0.01, 1.0)` — maxes at 1% gain
- Returns the negative cycle as the trade route

#### 4. Momentum (`analyze_momentum`)
- Computes Wilder's smoothed RSI (14-period) + Rate of Change (10-period) on 30-day hourly prices
- **Sell signal:** RSI > 65 → `strength = (RSI - 65) / 35 + ROC_confirm×0.25`
  - RSI ~72 needed to clear the 0.2 alert threshold (without ROC boost)
- **Buy signal:** RSI < 35 → `strength = (35 - RSI) / 35 + ROC_confirm×0.25`
  - ROC confirm: falling price boosts sell, rising price boosts buy (max +0.25)
- Hold if 35 ≤ RSI ≤ 65

#### Scoring (`score_and_rank`)
- `score = strength × strategy_weight`
- Sorted descending by score; this is the order alerts/trades are processed

---

### REST API (`api.php`) — All Endpoints

**GET endpoints** (`?action=...`):
| Action | Returns |
|---|---|
| `portfolio` | Holdings with live prices, alloc_pct, drift_pct, total_usd |
| `signals` | Signals (filterable by strategy, limit up to 500) |
| `alerts` | All alerts + unseen_count |
| `trades` | All trades with PnL, open/closed counts, PnL by strategy |
| `prices` | Latest prices for all tracked coins |
| `summary` | Dashboard summary (getDashboardSummary()) |
| `log` | Last N lines of log.txt (max 1000) |
| `performance` | Strategy performance stats |
| `paper_portfolio` | Paper portfolio config + holdings summary |
| `paper_portfolio_history` | Equity history (hours param, max 8760) |
| `paper_portfolio_performance` | Sharpe, drawdown, benchmark returns |
| `paper_trading_history` | Signal-based trade equity curve (hours param) |

**POST endpoints** (`action=...` in body):
| Action | Description |
|---|---|
| `portfolio_upsert` | Add/update coin holding (coin, amount, target_pct) |
| `portfolio_delete` | Remove coin from portfolio |
| `run_analysis` | Trigger `crypto.py` via shell_exec (async, background) |
| `dismiss_alert` | Dismiss one alert by alert_id |
| `dismiss_all_alerts` | Dismiss all alerts |
| `close_trade` | Close a paper trade (trade_id, coin) |
| `close_all_trades` | Close all open paper trades |
| `reset_trades` | Wipe paper trading history |
| `fund_paper_portfolio` | Set funded amount ($100–$1M) |
| `reset_paper_portfolio` | Wipe and reinitialize paper portfolio |
| `update_paper_settings` | Update stop_loss_pct, take_profit_pct, cash_reserve_pct, margin_limit |
| `toggle_paper_portfolio` | Set status to 'active' or 'paused' |

**CORS:** `Access-Control-Allow-Origin: *` — open for future React frontend.

---

### Paper Portfolio Engine (`paper_portfolio.py`)
- **Initialization:** `init_paper_portfolio(amount)` — clears holdings/allocations, sets `cash_balance = funded_amount`
- **VWAP tracking:** Buy trades use weighted-average entry price across all buys of same coin
- **Margin floor:** `-(margin_limit × funded_amount)` — buy rejected if new cash < floor
- **Stop-loss / Take-profit:** Checked every run cycle in `check_stop_loss_take_profit()`. Fires if `pnl_pct <= -stop_loss_pct` or `pnl_pct >= take_profit_pct`. Auto-closes all open trades for that coin and inserts a `risk` alert.
- **Allocation engine:** `compute_recommended_allocations()` — market-cap-weighted base (rank 1=10pts, rank 10=1pt), then signal adjustments (±5% per signal at full strength), clamped per coin [2%, 30%], re-normalized to 95% equity allocation
- **Rebalance cooldown:** `PAPER_REBALANCE_COOLDOWN_HRS (1h)` — skips rebalance if last one was < 1h ago
- **Rebalance logic:** Sell overweight >2% drift first (frees cash), then buy underweight >2% drift. Min trade size $5.
- **Snapshots:** Hourly equity curve with period_return, cumulative_return, Sharpe ratio (rolling 30-day daily returns, annualized), max drawdown, BTC benchmark, equal-weight benchmark

---

### Technical Indicators (`indicators.py`)
- **`zscore(series)`** — returns z-score of last value vs series mean/stdev; returns 0 if < 5 pts or stdev=0
- **`rsi(prices, period=14)`** — Wilder's smoothed RSI, seeded with simple average for first period then exponential smoothing; returns 50.0 if insufficient data
- **`roc(prices, period=10)`** — % change over last N periods

---

## Project 3: CryptoLens (`/var/www/html/CryptoLens/`)

### Overview
A public-facing crypto media and consulting brand site. Static HTML/CSS/JS — no backend, no server-side code.

**Brand:** CryptoLens
**Target domain:** `cryptolens.io`
**Twitter:** `@CryptoLensIO`
**CoinGecko API key:** `CG-aQzxrfUMSk8gSSKBgmpA3Uht` (free Demo key, in `js/config.js`)

---

### File Structure
```
CryptoLens/
├── index.html           # Home — trending coins, top-10 table, market overview
├── coin-explorer.html   # Full coin browser + detail modal
├── news.html            # Crypto news aggregation
├── reports.html         # Market reports
├── consulting.html      # Consulting services page
├── faq.html             # FAQ
├── refund-policy.html   # Refund policy
├── about.html           # About CryptoLens
├── contact.html         # Contact form
├── css/style.css        # Global dark-theme stylesheet
└── js/
    ├── config.js        # CoinGecko API key, base URL, shared helper functions
    ├── ticker.js        # Price ticker strip (top 20 coins, runs on every page)
    ├── coins.js         # Coin market data: home table, coin explorer, modal
    ├── news.js          # News aggregation
    └── contact.js       # Contact form handler
```

---

### JS Architecture
All JS files import helpers from `config.js`. No build step, no bundler — plain `<script>` tags.

**`config.js` helpers:**
- `cgUrl(endpoint, params)` — builds CoinGecko URL with API key appended
- `fetchCG(endpoint, params)` — fetch with error handling, returns null on failure
- `timeAgo(unixTs)` — relative time string
- `fmtNum(n)` — formats large numbers as $1.2T, $3.4B, $5.6M
- `fmtPrice(p)` — smart decimal formatting (2dp for >$1000, 8dp for tiny coins)
- `fmtPct(n, decimals)` — formatted percentage with sign
- `changeClass(n)` — returns `'change-pos'` or `'change-neg'` CSS class
- `truncate(str, len)` — truncates with ellipsis

**`ticker.js`:** Fetches top 20 coins from `/coins/markets`, renders animated price strip, duplicates HTML for seamless CSS loop. Runs on DOMContentLoaded on every page.

**`coins.js` functions:**
- `initTrending()` — fetches `/search/trending`, renders 7 trending chips with 24h change
- `initHomeTable()` — fetches top 10 by market cap, renders sortable table
- (coin-explorer has full paginated table + modal with chart data)

**CoinGecko endpoints used:**
- `/coins/markets` — market cap ranked list with prices, 24h change, volume
- `/search/trending` — trending coins
- `/coins/{id}` — individual coin detail for explorer modal

---

### SEO
Structured data: JSON-LD, Open Graph tags on all pages. Pages are designed for `cryptolens.io` as the canonical domain.

---

## Project 4: Military Flight Analysis (`/var/www/html/flight-analysis/`)

### Overview
A real-time military aircraft monitoring and anomaly detection system. Polls a public ADS-B API every 5 minutes, detects behavioral patterns, correlates them into named intelligence events, and stores alerts in SQLite + CSV.

**Cron:** `*/5 * * * * cd /var/www/html/flight-analysis && /path/to/venv/bin/python monitor.py >> /tmp/monitor.log 2>&1`

**Lock file:** `/tmp/flight_monitor.lock` — prevents overlapping runs (flock-based)

---

### File Structure
```
flight-analysis/
├── monitor.py           # Main entrypoint — single run cycle, called by cron
├── correlator.py        # Combines detections into named alert events
├── db.py                # SQLite schema, insert/query helpers, CSV export
├── qc.py                # Snapshot quality control (coverage, count drift)
├── pruner.py            # DB pruning (old positions, old alerts)
├── requirements.txt     # pandas, numpy, requests, streamlit, plotly, scipy, pyarrow, apscheduler, scikit-learn, reverse_geocoder
├── detectors/
│   ├── ghost.py         # Ghost aircraft detector (no callsign/reg/type)
│   ├── cluster.py       # Formation/cluster detector (DBSCAN)
│   ├── circular.py      # Orbit/holding pattern detector (cumulative heading)
│   ├── ew.py            # EW/ISR aircraft classifier (type + behavioral scoring)
│   └── __init__.py
├── dashboard/
│   ├── app.py           # Streamlit main dashboard
│   └── pages/
│       └── 1_Control_Room.py  # Streamlit Control Room page
├── analysis/
│   ├── analyze.py       # Offline analysis utilities
│   └── download_data.py # Data download helpers
└── data/
    ├── monitor.db       # Production SQLite DB
    ├── alerts.csv       # Exported alerts (updated when new alerts written)
    ├── anomalies.csv
    ├── analyzed_tracks.csv
    ├── aircraft_metrics.csv
    └── raw_tracks.csv
```

---

### Data Source
- **API:** `https://api.adsb.lol/v2/mil` — public military ADS-B feed, no auth required
- **Poll interval:** Every 5 minutes via cron
- **Returns:** JSON with `ac` array — each aircraft has: `hex, flight, r (registration), t (type), lat, lon, alt_baro, gs (ground speed), ...`

---

### Focus Regions
| Region | Lat Range | Lon Range | Description |
|---|---|---|---|
| NTTR | 35.5–38.5°N | 117.5–114.0°W | Nevada Test & Training Range |
| UTTR | 39.5–42.0°N | 114.0–111.5°W | Utah Test & Training Range |
| OTHER | — | — | Everything else globally |

---

### SQLite Schema (`db.py`)

**Tables:**
| Table | Purpose |
|---|---|
| `aircraft_positions` | Every position snapshot: `hex, flight, registration, type, lat, lon, alt_baro, gs, snapshot_time, region` |
| `alerts` | Detected events: `timestamp, alert_type, severity, region, summary, detail, aircraft_hexes (JSON), centroid_lat, centroid_lon, acknowledged` |
| `ew_contacts` | EW/ISR classified aircraft per cycle |
| `snapshot_qc` | Quality control records per cycle |

**Indexes:** `idx_positions_time`, `idx_positions_hex`

**Dedup:** `DEDUP_RADIUS_NM=50`, `DEDUP_MINUTES=30` — alerts with same type within 50nm and 30min are suppressed

**CSV export:** `data/alerts.csv` updated only when new alerts are written

---

### Run Cycle (`monitor.py` → `run_cycle()`)
1. `fetch_military()` — HTTP GET to ADS-B API, returns `ac` list
2. `qc.compute_snapshot_qc()` — evaluates snapshot quality; FAILED QC aborts cycle
3. `db.insert_positions()` — persists all positions with timestamp and region tag
4. `db.get_recent_positions(minutes=60)` — pulls last 60 min of history for track-based detectors
5. Run detectors:
   - `detect_ghosts(snapshot)` — snapshot-only
   - `detect_clusters(snapshot)` — snapshot-only
   - `detect_circular_flight(history)` — history-based
   - `detect_ew_aircraft(snapshot, orbits, clusters)` — uses output of other detectors
6. `correlate(ghosts, clusters, orbits, ew_contacts)` — produces named events
7. `db.bulk_insert_alerts(conn, events)` — deduped batch insert
8. If new alerts: `db.export_alerts_csv()`

---

### Detectors

#### Ghost Detector (`ghost.py`)
- **Definition:** Aircraft broadcasting position but with no callsign, registration, OR type
- **Filters:** Must be airborne (alt_baro not "ground"/0/None), must have lat/lon, must be moving (`gs >= 50 kts`)
- **Severity function:** `ghost_severity()` — HIGH if ≥3 ghosts, LOW if ≥1

#### Cluster Detector (`cluster.py`)
- **Algorithm:** Pure-Python DBSCAN (O(n²) haversine distance)
- **Parameters:** `EPS_NM=15` (tightened from 30 — 30nm was grouping aircraft at separate bases), `MIN_SAMPLES=3`
- **Filters:** Airborne only (alt_baro not "ground")
- **Output per cluster:** `centroid_lat/lon, count, types (set), avg_gs, hex_list`

#### Circular Flight Detector (`circular.py`)
- **Algorithm:** Groups positions by hex, sorts by time, computes cumulative heading change
- **Thresholds:**
  - `MIN_POINTS=8` — needs at least 8 position reports
  - `HEADING_THRESHOLD_DEG=540` — 1.5 full loops required (raised from 360 to suppress training maneuvers)
  - `MAX_RADIUS_STD_NM=5` — tight radius consistency (genuine racetrack vs straight-leg drift)
  - `MIN_RADIUS_NM=3` — excludes tight maneuvering circles
  - `MAX_TIME_GAP_MINUTES=15` — skips legs with stale position data (heading delta meaningless across large gaps)
- **Output:** `hex, flight, type, centroid_lat/lon, direction (CW/CCW), radius_nm, cumulative_heading_deg, last_lat, last_lon`

#### EW/ISR Classifier (`ew.py`)
**Type tiers:**

| Tier | Types | Confidence |
|---|---|---|
| CONFIRMED | EA18, EA6B, RC135, RC12, EP3, E8, E3CF, E7, E2, U2, RQ4, MQ9, MC12, EC130 | CONFIRMED on type alone (military-only feed) |
| PROBABLE | B350, BE20, PC12 (King Air ISR variants) | PROBABLE by type; CONFIRMED if also in orbit, near cluster, or callsign match |
| POSSIBLE | C130 | POSSIBLE by type; PROBABLE with orbit OR cluster proximity; CONFIRMED with both OR callsign |

**Behavioral scoring (used when no type match):**
- In confirmed orbit: +0.35
- Alt > 25,000 ft: +0.20
- Alt > 55,000 ft: +0.15 additional
- Speed 250–380 kts: +0.10
- Near cluster (≤50 nm): +0.20
- Score ≥ 0.55 → PROBABLE; ≥ 0.40 → POSSIBLE; < 0.40 → skip
- **Altitude gate:** Aircraft below 5,000 ft are excluded (not scored) — prevents approach/departure false positives

**EW callsign prefixes:** `AWACS, RIVET, COBRA, IRON, SHADOW, TACAMO, RECON, JANET`

---

### Correlator (`correlator.py`) — Named Events

| Alert Type | Severity | Trigger |
|---|---|---|
| `EW_ASSET_ACTIVITY` | CRITICAL/HIGH/MEDIUM | CONFIRMED/PROBABLE/POSSIBLE EW contacts |
| `PROBABLE_REFUELING_OP` | CRITICAL | Tanker-type in orbit + cluster within 20nm + cluster avg speed 180–380 kts + tanker still near orbit centroid (staleness gate ≤35nm) |
| `SENSITIVE_ASSET_ACTIVITY` | HIGH | Ghost(s) within 60nm of a cluster |
| `HOLDING_ORBIT` | HIGH/MEDIUM/LOW | Remaining orbits (no nearby cluster). HIGH if tanker type or ≥720° cumulative. LOW if trainer type. |
| `ATTACK_PACKAGE` | HIGH | Remaining clusters: ≥5 aircraft, avg_gs >350 kts, contains fighter types |
| `FORMATION_ACTIVITY` | MEDIUM | Remaining clusters that don't qualify as ATTACK_PACKAGE |
| `UNIDENTIFIED_CONTACTS` | HIGH/LOW | Ghosts with no nearby clusters. HIGH if ≥3 ghosts. |

**Tanker types:** `KC135, KC46, KC10, A330, DC10`
**Fighter types:** `F16, F35, F15, FA18, F18, EF2000, GRPN, F22, A10, F117, B1, B52, B2, B21`
**Trainer types (downgrade to LOW orbit):** `T38, TEX2, T45, T6, PC21, HAWK, MB339, L159, BE40`

**Geocoding:** Batch geocoding via `reverse_geocoder` library for all event centroids in one call per cycle. Falls back to lat/lon coordinates on error.

**Processing order:** EW contacts first → refueling ops → ghost+cluster pairs → remaining orbits → remaining clusters → ghost-only

---

### Streamlit Dashboard (`dashboard/app.py`)
- Reads from `data/monitor.db` and CSV files
- Pages: main dashboard + `1_Control_Room.py` page
- Interactive Plotly maps, color-coded by severity (CRITICAL → LOW)

---

## Notes for Claude

- **Always read this file at the start of any session involving these projects**
- **Update this file after any meaningful structural change** — new files, renamed modules, changed thresholds, new endpoints, schema changes
- **DB locations:** Production crypto DB is at `/var/www/data/crypto.db` (outside web root). `/var/www/html/crypto/crypto.db` is a dev copy. Don't confuse them.
- **One git repo:** All projects share commit history. Be mindful when staging — changes to CryptoLens and flight-analysis are in the same repo as the portfolio site.
- **Python venvs:** Crypto has `/var/www/html/crypto/venv/`. Flight analysis has `/var/www/html/flight-analysis/venv/`. Always use the correct venv for each project.
- **No CoinGecko in Python:** The crypto engine uses Kraken (prices) + CoinPaprika (market cap ranking) only. CoinGecko is CryptoLens frontend only.
- **Future React frontend planned for crypto:** `api.php` has CORS open (`*`) in anticipation of this.
- **Ryan works across Cowork (Dispatch) and Claude Code in terminal**

---

*Last updated: 2026-04-11 — Initial deep-read pass. Full schema, all strategies, all API endpoints, all detectors documented.*
