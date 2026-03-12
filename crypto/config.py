"""
Shared configuration — constants, environment variables, coin maps, logging.
Every module imports from here.
"""

import os, sys, logging
from typing import Dict
from dotenv import load_dotenv

load_dotenv()

# ── PATHS ─────────────────────────────────────────────────────────────────────
DB_PATH      = "/var/www/data/crypto.db"
LOG_FILE     = "/var/www/html/crypto/log.txt"

# ── THRESHOLDS ────────────────────────────────────────────────────────────────
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

# ── PAPER PORTFOLIO DEFAULTS ─────────────────────────────────────────────────
PAPER_PORTFOLIO_DEFAULT_FUND = float(os.getenv("PAPER_PORTFOLIO_DEFAULT_FUND", "1000.0"))
PAPER_CASH_RESERVE_PCT       = float(os.getenv("PAPER_CASH_RESERVE_PCT",       "5.0"))
PAPER_STOP_LOSS_PCT          = float(os.getenv("PAPER_STOP_LOSS_PCT",          "10.0"))
PAPER_TAKE_PROFIT_PCT        = float(os.getenv("PAPER_TAKE_PROFIT_PCT",        "25.0"))
PAPER_MARGIN_LIMIT           = float(os.getenv("PAPER_MARGIN_LIMIT",           "0.30"))
PAPER_REBALANCE_COOLDOWN_HRS = int(os.getenv("PAPER_REBALANCE_COOLDOWN_HRS",   "6"))

# ── COIN MAPS ────────────────────────────────────────────────────────────────
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

# ── STRATEGY WEIGHTS ─────────────────────────────────────────────────────────
WEIGHTS = {"arbitrage": 1.0, "pairs": 0.85, "rebalance": 0.70, "momentum": 0.60}

# ── LOGGING ───────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler(sys.stdout),
    ],
)
log = logging.getLogger(__name__)
