<?php
// ══════════════════════════════════════════════════════════════
//  SHARED CONFIG — included by crypto.php, api.php, db.php
// ══════════════════════════════════════════════════════════════

$DB_FILE   = '/var/www/data/crypto.db';
$LOG_FILE  = '/var/www/html/crypto/log.txt';
$LOG_LINES = 200;

$SUPPORTED_COINS = [
    'btc'  => 'Bitcoin',      'eth'  => 'Ethereum',     'xrp'  => 'XRP',
    'bnb'  => 'BNB',          'sol'  => 'Solana',       'doge' => 'Dogecoin',
    'ada'  => 'Cardano',      'trx'  => 'TRON',         'avax' => 'Avalanche',
    'link' => 'Chainlink',    'ton'  => 'TON',          'sui'  => 'Sui',
    'shib' => 'Shiba Inu',    'dot'  => 'Polkadot',     'near' => 'NEAR',
    'ltc'  => 'Litecoin',     'bch'  => 'Bitcoin Cash', 'matic'=> 'Polygon',
    'xlm'  => 'Stellar',      'hbar' => 'Hedera',
];

$STRATEGY_WEIGHTS = [
    'arbitrage' => 1.0,
    'pairs'     => 0.85,
    'rebalance' => 0.70,
    'momentum'  => 0.60,
];

$PAPER_PORTFOLIO_DEFAULT = 1000.0;
