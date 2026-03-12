<?php // ══ TRADES VIEW ════════════════════════════════════════════════════════════ ?>

<?php
// P&L breakdown by strategy for the summary bar
$pnlByStrategy = [];
foreach ($allTrades as $t) {
    $strat = $t['strategy'] ?? 'unknown';
    if (!isset($pnlByStrategy[$strat])) $pnlByStrategy[$strat] = 0.0;
    $pnlByStrategy[$strat] += $t['pnl_usd'];
}
// Win rate calculation
$closedWithPnl = array_filter($closedTrades, fn($t) => $t['pnl_usd'] != 0);
$wins = count(array_filter($closedTrades, fn($t) => $t['pnl_usd'] > 0));
$winRate = count($closedTrades) > 0 ? round(($wins / count($closedTrades)) * 100) : 0;
?>

<!-- Performance Summary Cards -->
<div class="stat-row">
  <div class="pstat">
    <div class="pstat-l">TOTAL P&amp;L</div>
    <div class="pstat-v" style="color:<?= $paperPnl >= 0 ? 'var(--gn)' : 'var(--rd)' ?>">
      <?= $paperPnl >= 0 ? '+' : '' ?>$<?= number_format(abs($paperPnl), 2) ?>
    </div>
    <div class="pstat-s"><?= count($allTrades) ?> total trade<?= count($allTrades) !== 1 ? 's' : '' ?></div>
  </div>
  <div class="pstat">
    <div class="pstat-l">OPEN TRADES</div>
    <div class="pstat-v" style="color:var(--gn)"><?= count($openTrades) ?></div>
    <div class="pstat-s">active position<?= count($openTrades) !== 1 ? 's' : '' ?></div>
  </div>
  <div class="pstat">
    <div class="pstat-l">CLOSED TRADES</div>
    <div class="pstat-v" style="color:var(--t1)"><?= count($closedTrades) ?></div>
    <div class="pstat-s">completed trade<?= count($closedTrades) !== 1 ? 's' : '' ?></div>
  </div>
  <div class="pstat">
    <div class="pstat-l">WIN RATE</div>
    <div class="pstat-v" style="color:<?= $winRate >= 60 ? 'var(--gn)' : ($winRate >= 40 ? 'var(--yw)' : 'var(--rd)') ?>">
      <?= $winRate ?>%
    </div>
    <div class="pstat-s"><?= $wins ?>W / <?= count($closedTrades) - $wins ?>L</div>
  </div>
</div>

<div class="panel">
  <div class="ph">
    <div class="ph-t">Paper Trades</div>
    <div style="display:flex;gap:8px;align-items:center">
      <?php if (!empty($allTrades)): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Reset all paper trade history?')">
        <input type="hidden" name="action" value="reset_trades">
        <button type="submit" style="font-size:.75rem;padding:5px 12px;background:transparent;border:1px solid var(--rd);border-radius:6px;color:var(--rd);cursor:pointer;font-family:inherit">Reset</button>
      </form>
      <?php endif ?>
    </div>
  </div>

  <?php if (!empty($pnlByStrategy)): ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap;padding:12px 16px 0">
    <?php foreach ($pnlByStrategy as $strat => $spnl): $sc = strategyColor($strat); ?>
    <div style="padding:8px 14px;background:var(--s2);border:1px solid var(--b1);border-radius:8px;min-width:130px">
      <div style="font-size:.68rem;color:<?= $sc ?>;font-weight:600;letter-spacing:.04em;margin-bottom:4px"><?= strtoupper(h($strat)) ?></div>
      <div style="font-size:.95rem;font-weight:700;color:<?= $spnl >= 0 ? 'var(--gn)' : 'var(--rd)' ?>">
        <?= $spnl >= 0 ? '+' : '' ?>$<?= number_format(abs($spnl), 2) ?>
      </div>
    </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>

  <!-- Paper Trading Equity Curve -->
  <?php if (!empty($ptHistory) && count($ptHistory) > 1): ?>
  <div style="padding:14px 16px">
    <div style="font-size:.72rem;font-weight:600;letter-spacing:.05em;color:var(--t3);margin-bottom:8px">P&L EQUITY CURVE</div>
    <div class="tf-btns" id="pt-tf-btns">
      <button class="tf-btn" data-hours="24">24H</button>
      <button class="tf-btn" data-hours="168">1W</button>
      <button class="tf-btn active" data-hours="720">1M</button>
      <button class="tf-btn" data-hours="2160">3M</button>
      <button class="tf-btn" data-hours="4380">6M</button>
      <button class="tf-btn" data-hours="8760">1Y</button>
    </div>
    <canvas id="pt-equity-chart" style="width:100%;height:160px"></canvas>
    <div style="display:flex;gap:14px;justify-content:center;font-size:.68rem;color:var(--t3);margin-top:6px">
      <span><span style="color:var(--ac)">●</span> P&L</span>
      <span><span style="color:var(--b2)">- -</span> breakeven ($0)</span>
    </div>
  </div>
  <?php endif; ?>

  <?php if (empty($allTrades)): ?>
    <div class="state"><div class="state-i"></div><div class="state-t">No paper trades yet</div><div class="state-s">Trades are created automatically when signals fire above the strength threshold.</div></div>
  <?php else: ?>

  <!-- Open trades -->
  <?php if (!empty($openTrades)): ?>
  <div style="padding:16px 16px 0;display:flex;align-items:center;justify-content:space-between">
    <div style="font-size:.82rem;font-weight:600;color:var(--gn)">OPEN (<?= count($openTrades) ?>)</div>
    <form method="post" onsubmit="return confirm('Close all open trades at current prices?')">
      <input type="hidden" name="action" value="close_all_trades">
      <button type="submit" style="font-size:.75rem;padding:5px 12px;background:transparent;border:1px solid var(--b1);border-radius:6px;color:var(--t2);cursor:pointer;font-family:inherit">Close All</button>
    </form>
  </div>
  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>STRATEGY</th><th>COIN</th><th>ACTION</th>
        <th>ENTRY</th><th>CURRENT</th><th>SIZE (USD)</th><th>P&amp;L $</th><th>P&amp;L %</th><th>AGE</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($openTrades as $t): tradeRow($t, true); endforeach ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>

  <!-- Closed trades -->
  <?php if (!empty($closedTrades)): ?>
  <div style="padding:16px 16px 0">
    <div style="font-size:.82rem;font-weight:600;color:var(--t3)">CLOSED (<?= count($closedTrades) ?>)</div>
  </div>
  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>STRATEGY</th><th>COIN</th><th>ACTION</th>
        <th>ENTRY</th><th>EXIT</th><th>SIZE (USD)</th><th>P&amp;L $</th><th>P&amp;L %</th><th>HELD</th><th>CLOSED</th>
      </tr></thead>
      <tbody>
      <?php foreach ($closedTrades as $t): tradeRow($t, false); endforeach ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>

  <?php endif ?>
</div>
