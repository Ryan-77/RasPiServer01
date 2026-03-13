<?php // ══ PORTFOLIO VIEW — Coinbase-style ════════════════════════════════════════ ?>

<?php
// Sort holdings by value descending
$portfolioSorted = $portfolio;
usort($portfolioSorted, function($a, $b) use ($latestPrices) {
    $valA = $a['amount'] * ($latestPrices[$a['coin']] ?? 0);
    $valB = $b['amount'] * ($latestPrices[$b['coin']] ?? 0);
    return $valB <=> $valA;
});
$coinCount   = count($portfolio);
$hasTargets  = (bool)array_filter($portfolio, fn($r) => $r['target_pct'] !== null);
$totalPct    = array_sum(array_column($portfolio, 'target_pct'));

// Paper portfolio shorthand
$ppCfg   = $ppFunded ? ($ppSummary['config'] ?? []) : [];
$ppSet   = $ppFunded ? ($ppSummary['settings'] ?? []) : [];
$ppHold  = $ppFunded ? ($ppSummary['holdings'] ?? []) : [];
$ppAlloc = $ppFunded ? ($ppSummary['allocations'] ?? []) : [];
$ppAn    = $ppFunded ? ($ppSummary['analytics'] ?? []) : [];
$ppRisk  = $ppFunded ? ($ppSummary['risk'] ?? []) : [];
$ppRet   = $ppAn['cumulative_return'] ?? 0;
$ppDaily = $ppAn['daily_return'] ?? 0;
$sharpe  = $ppAn['sharpe_ratio'] ?? 0;

// Recent signal time
$recentSig = null;
try {
    $lastSig = getSignals(null, 1);
    $recentSig = $lastSig[0]['timestamp'] ?? null;
} catch (Exception $e) {}
?>

<!-- HERO -->
<div class="hero">
  <div class="hero-value">
    <?= $totalUsd > 0 ? '$'.number_format($totalUsd, 2) : '<span style="color:var(--t3)">$0.00</span>' ?>
  </div>
  <div class="hero-meta">
    <span><?= $coinCount ?> asset<?= $coinCount !== 1 ? 's' : '' ?></span>
    <span class="hero-sep">·</span>
    <?php if ($ppFunded): ?>
    <span style="color:var(--pu)">
      Paper: <?= $ppRet >= 0 ? '+' : '' ?><?= number_format($ppRet, 1) ?>%
    </span>
    <span class="hero-sep">·</span>
    <?php endif; ?>
    <span>
      <?= $recentSig ? 'Last analysis: ' . date('H:i', strtotime($recentSig)) . ' UTC' : 'No analysis yet' ?>
    </span>
  </div>
</div>

<!-- SUB-TABS -->
<div class="sub-tabs">
  <button class="sub-tab active" data-tab="holdings" onclick="switchTab('holdings')">
    MY HOLDINGS
  </button>
  <button class="sub-tab" data-tab="paper" onclick="switchTab('paper')">
    PAPER PORTFOLIO
    <?php if ($ppFunded): ?>
    <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--pu);margin-left:6px;vertical-align:middle"></span>
    <?php endif; ?>
  </button>
</div>

<!-- ═══ TAB: MY HOLDINGS ═══════════════════════════════════════════════════ -->
<div id="tab-holdings">
<div class="panel">
  <div class="ph">
    <div class="ph-t">PORTFOLIO HOLDINGS</div>
    <div class="ph-m"><?= $coinCount ?> ASSET<?= $coinCount !== 1 ? 'S' : '' ?></div>
  </div>

  <?php if (empty($portfolio)): ?>
  <div class="state">
    <div class="state-i"></div>
    <div class="state-t">NO HOLDINGS YET</div>
    <div class="state-s">Add your first coin below</div>
  </div>
  <?php else: ?>
  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>COIN</th><th>PRICE (USD)</th>
        <th>VALUE (USD)</th><th>ALLOCATION</th><th>TARGET %</th><th>REC %</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($portfolioSorted as $row):
        $price    = $latestPrices[$row['coin']] ?? null;
        $val      = $price ? $row['amount'] * $price : null;
        $allocPct = ($val && $totalUsd > 0) ? ($val/$totalUsd)*100 : null;
        $drift    = ($allocPct !== null && $row['target_pct']) ? $allocPct - $row['target_pct'] : null;
        $driftCol = ($drift !== null) ? (abs($drift) > 5 ? ($drift>0?'var(--rd)':'var(--gn)') : 'var(--t3)') : 'var(--t3)';
      ?>
      <tr>
        <td>
          <span style="font-weight:700;color:var(--ac)"><?= strtoupper(h($row['coin'])) ?></span>
          <span class="muted" style="margin-left:6px"><?= h($SUPPORTED_COINS[$row['coin']] ?? '') ?></span>
          <div style="font-size:.72rem;color:var(--t3);margin-top:2px;font-family:var(--mono)">
            <?= number_format((float)$row['amount'], 8) ?> <?= strtoupper($row['coin']) ?>
          </div>
        </td>
        <td class="num"><?= $price ? '$'.number_format($price, 2) : '<span class="na">no data</span>' ?></td>
        <td class="num" style="font-weight:600"><?= $val ? '$'.number_format($val, 2) : '<span class="na">—</span>' ?></td>
        <td>
          <?php if ($allocPct !== null): ?>
          <span style="font-size:.78rem;color:var(--t2)"><?= round($allocPct,1) ?>%</span>
          <?php if ($row['target_pct']): ?>
          <span style="font-size:.65rem;color:<?= $driftCol ?>;margin-left:4px">
            (<?= $drift>0?'+':'' ?><?= round($drift,1) ?>%)
          </span>
          <?php endif; ?>
          <div class="alloc-bar"><div class="alloc-fill" style="width:<?= min($allocPct,100) ?>%;background:var(--ac)"></div></div>
          <?php else: ?><span class="na">—</span><?php endif; ?>
        </td>
        <td class="muted"><?= $row['target_pct'] !== null ? round($row['target_pct'],1).'%' : '—' ?></td>
        <td>
          <?php
            $recPct = $recAllocMap[$row['coin']] ?? null;
            $recDrift = ($allocPct !== null && $recPct !== null) ? $allocPct - $recPct : null;
            $recCol = ($recDrift !== null) ? (abs($recDrift) > 5 ? ($recDrift>0?'var(--rd)':'var(--gn)') : 'var(--pu)') : 'var(--t3)';
          ?>
          <?php if ($recPct !== null): ?>
          <span style="font-size:.78rem;color:var(--pu)"><?= number_format($recPct, 1) ?>%</span>
          <?php if ($recDrift !== null): ?>
          <span style="font-size:.65rem;color:<?= $recCol ?>;margin-left:3px">
            (<?= $recDrift>0?'+':'' ?><?= round($recDrift,1) ?>%)
          </span>
          <?php endif; ?>
          <?php else: ?><span class="na">—</span><?php endif; ?>
        </td>
        <td>
          <form method="POST" onsubmit="return confirm('Remove <?= strtoupper($row['coin']) ?>?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="coin" value="<?= h($row['coin']) ?>">
            <button type="submit" class="btn-del">REMOVE</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if ($hasTargets): ?>
      <tr style="border-top:2px solid var(--b2)">
        <td colspan="4" style="font-size:.62rem;letter-spacing:2px;color:var(--t3)">TARGET TOTAL</td>
        <td class="muted" style="color:<?= abs($totalPct-100)<1?'var(--gn)':'var(--rd)' ?>">
          <?= round($totalPct,1) ?>%
          <?= abs($totalPct-100)<1 ? '' : '(should = 100%)' ?>
        </td>
        <td></td><td></td>
      </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Add/Update Form -->
  <div class="port-form">
    <div style="font-size:.65rem;letter-spacing:3px;color:var(--t3);margin-bottom:14px">
      ADD / UPDATE HOLDING
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="upsert">
      <div class="form-row">
        <div class="form-group">
          <label>COIN</label>
          <select name="coin">
            <?php foreach ($SUPPORTED_COINS as $ticker => $name): ?>
            <option value="<?= $ticker ?>"><?= strtoupper($ticker) ?> — <?= $name ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>AMOUNT HELD</label>
          <input type="number" name="amount" step="0.00000001" min="0.00000001" placeholder="e.g. 0.05" required>
        </div>
        <div class="form-group">
          <label>TARGET % (optional)</label>
          <input type="number" name="target_pct" step="0.1" min="0" max="100" placeholder="e.g. 40">
        </div>
        <button type="submit" class="btn-primary">+ ADD / UPDATE</button>
      </div>
    </form>
  </div>
</div>
</div><!-- /tab-holdings -->

<!-- ═══ TAB: PAPER PORTFOLIO ═══════════════════════════════════════════════ -->
<div id="tab-paper" style="display:none">

<?php if (!$ppFunded): ?>
<!-- Not funded CTA -->
<div class="panel">
  <div class="pp-cta">
    <div class="pp-cta-t">PAPER PORTFOLIO</div>
    <div class="pp-cta-s">Fund a virtual portfolio and let the analysis engine manage it autonomously.<br>
      Includes stop-loss, take-profit, recommended allocations, and performance tracking.</div>
    <form method="POST" style="display:inline-flex;gap:10px;align-items:flex-end;flex-wrap:wrap;justify-content:center">
      <input type="hidden" name="action" value="fund_paper">
      <div class="form-group" style="min-width:160px">
        <label style="font-size:.72rem;color:var(--t3)">STARTING CAPITAL (USD)</label>
        <input type="number" name="amount" value="1000" min="100" max="1000000" step="100"
               style="background:var(--s1);border:1px solid var(--b2);color:var(--t1);font-family:var(--font);font-size:.85rem;padding:8px 12px;border-radius:8px">
      </div>
      <button type="submit" class="btn-primary" style="background:var(--pu)">FUND PORTFOLIO</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- Paper Portfolio Funded -->

<div class="pp-header">
  <div class="pp-dot"></div>
  <div class="pp-title">PAPER PORTFOLIO</div>
  <span class="pp-status <?= $ppCfg['status'] ?>"><?= strtoupper($ppCfg['status']) ?></span>
  <div style="flex:1"></div>
  <form method="POST" style="display:inline;margin:0">
    <input type="hidden" name="action" value="toggle_paper">
    <input type="hidden" name="status" value="<?= $ppCfg['status'] === 'active' ? 'paused' : 'active' ?>">
    <button type="submit" class="btn btn-g" style="font-size:.72rem;padding:5px 12px">
      <?= $ppCfg['status'] === 'active' ? 'PAUSE' : 'RESUME' ?>
    </button>
  </form>
  <form method="POST" style="display:inline;margin:0" onsubmit="return confirm('Reset paper portfolio? Holdings and history will be deleted.')">
    <input type="hidden" name="action" value="reset_paper">
    <button type="submit" class="btn-del" style="font-size:.72rem">RESET</button>
  </form>
</div>

<!-- Inner sub-tab navigation -->
<div class="pp-sub-tabs">
  <button class="pp-sub-tab active" data-ptab="overview"     onclick="switchPaperTab('overview')">OVERVIEW</button>
  <button class="pp-sub-tab"        data-ptab="holdings"     onclick="switchPaperTab('holdings')">HOLDINGS</button>
  <button class="pp-sub-tab"        data-ptab="allocations"  onclick="switchPaperTab('allocations')">ALLOCATIONS</button>
  <button class="pp-sub-tab"        data-ptab="trades"       onclick="switchPaperTab('trades')">
    TRADES
    <?php if (!empty($ppOpenTrades)): ?>
    <span style="display:inline-block;background:var(--gn);color:#000;font-size:.58rem;padding:1px 5px;border-radius:3px;margin-left:4px;vertical-align:middle"><?= count($ppOpenTrades) ?></span>
    <?php endif; ?>
  </button>
  <button class="pp-sub-tab"        data-ptab="performance"  onclick="switchPaperTab('performance')">PERFORMANCE</button>
  <button class="pp-sub-tab"        data-ptab="settings"     onclick="switchPaperTab('settings')">SETTINGS</button>
</div>

<!-- ── OVERVIEW: stats + equity curve ─────────────────────── -->
<div id="pp-tab-overview">

<div class="pp-stats">
  <div class="pp-stat">
    <div class="pp-stat-l">TOTAL VALUE</div>
    <div class="pp-stat-v" style="color:var(--pu)">$<?= number_format($ppCfg['total_value'], 2) ?></div>
    <div class="pp-stat-s">funded $<?= number_format($ppCfg['funded_amount'], 0) ?></div>
  </div>
  <div class="pp-stat">
    <div class="pp-stat-l">CASH BALANCE</div>
    <div class="pp-stat-v" style="color:<?= $ppCfg['cash_balance'] >= 0 ? 'var(--t1)' : 'var(--yw)' ?>">
      <?= $ppCfg['cash_balance'] < 0 ? '-' : '' ?>$<?= number_format(abs($ppCfg['cash_balance']), 2) ?>
    </div>
    <div class="pp-stat-s"><?= $ppRisk['is_on_margin'] ? '<span style="color:var(--yw)">ON MARGIN</span>' : 'cash reserve' ?></div>
  </div>
  <div class="pp-stat">
    <div class="pp-stat-l">RETURN</div>
    <div class="pp-stat-v" style="color:<?= $ppRet >= 0 ? 'var(--gn)' : 'var(--rd)' ?>">
      <?= $ppRet >= 0 ? '+' : '' ?><?= number_format($ppRet, 2) ?>%
    </div>
    <div class="pp-stat-s">daily: <?= $ppDaily >= 0 ? '+' : '' ?><?= number_format($ppDaily, 2) ?>%</div>
  </div>
  <div class="pp-stat">
    <div class="pp-stat-l">MAX DRAWDOWN</div>
    <div class="pp-stat-v" style="color:<?= $ppCfg['max_drawdown'] > 5 ? 'var(--rd)' : 'var(--t2)' ?>">
      -<?= number_format($ppCfg['max_drawdown'], 1) ?>%
    </div>
    <div class="pp-stat-s">HWM: $<?= number_format($ppCfg['high_water_mark'], 2) ?></div>
  </div>
  <div class="pp-stat">
    <div class="pp-stat-l">SHARPE RATIO</div>
    <div class="pp-stat-v" style="color:<?= $sharpe > 1 ? 'var(--gn)' : ($sharpe > 0.5 ? 'var(--yw)' : 'var(--rd)') ?>">
      <?= number_format($sharpe, 2) ?>
    </div>
    <div class="pp-stat-s"><?= $sharpe > 1 ? 'good' : ($sharpe > 0.5 ? 'moderate' : ($sharpe == 0 ? 'n/a' : 'low')) ?></div>
  </div>
  <div class="pp-stat">
    <div class="pp-stat-l">VS BENCHMARKS</div>
    <div class="pp-stat-v" style="font-size:.82rem">
      <span style="color:var(--or)">BTC <?= ($ppAn['btc_return'] ?? 0) >= 0 ? '+' : '' ?><?= number_format($ppAn['btc_return'] ?? 0, 1) ?>%</span>
    </div>
    <div class="pp-stat-s">EQ-WT <?= ($ppAn['equal_weight_return'] ?? 0) >= 0 ? '+' : '' ?><?= number_format($ppAn['equal_weight_return'] ?? 0, 1) ?>%</div>
  </div>
</div>

<div class="panel">
  <div class="ph">
    <div class="ph-t" style="color:var(--pu)">EQUITY CURVE</div>
    <div class="ph-m"><?= count($ppHistory) ?> DATA POINT<?= count($ppHistory) !== 1 ? 'S' : '' ?></div>
  </div>
  <div style="padding:14px 16px">
    <div class="tf-btns" id="pp-tf-btns">
      <button class="tf-btn" data-hours="24">24H</button>
      <button class="tf-btn" data-hours="168">1W</button>
      <button class="tf-btn active" data-hours="720">1M</button>
      <button class="tf-btn" data-hours="2160">3M</button>
      <button class="tf-btn" data-hours="4380">6M</button>
      <button class="tf-btn" data-hours="8760">1Y</button>
    </div>
    <canvas id="pp-equity-chart" style="width:100%;height:180px"></canvas>
    <div style="display:flex;gap:14px;justify-content:center;font-size:.68rem;color:var(--t3);margin-top:6px">
      <span><span style="color:var(--pu)">●</span> portfolio</span>
      <span><span style="color:var(--or)">●</span> BTC benchmark</span>
      <span><span style="color:#60a5fa">●</span> equal-weight</span>
      <span><span style="color:var(--b2)">- -</span> funded ($<?= number_format($ppCfg['funded_amount'] ?? 0, 0) ?>)</span>
    </div>
  </div>
</div>

</div><!-- /pp-tab-overview -->

<!-- ── HOLDINGS ────────────────────────────────────────────── -->
<div id="pp-tab-holdings" style="display:none">

<?php if (!empty($ppHold)): ?>
<div class="panel">
  <div class="ph">
    <div class="ph-t" style="color:var(--pu)">HOLDINGS</div>
    <div class="ph-m"><?= count($ppHold) ?> POSITION<?= count($ppHold) !== 1 ? 'S' : '' ?></div>
  </div>
  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>COIN</th><th>AMOUNT</th><th>AVG ENTRY</th><th>CURRENT</th>
        <th>VALUE</th><th>P&amp;L</th><th>P&amp;L %</th><th>ALLOC</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ppHold as $h):
        $pnlClass = $h['unrealized_pnl'] > 0 ? 'pnl-pos' : ($h['unrealized_pnl'] < 0 ? 'pnl-neg' : 'pnl-zero');
      ?>
      <tr>
        <td>
          <span style="font-weight:700;color:var(--pu)"><?= strtoupper(h($h['coin'])) ?></span>
          <br><span class="muted"><?= h($SUPPORTED_COINS[$h['coin']] ?? '') ?></span>
        </td>
        <td class="num"><?= number_format($h['amount'], 6) ?></td>
        <td class="num">$<?= number_format($h['avg_entry_price'], 2) ?></td>
        <td class="num">$<?= number_format($h['current_price'], 2) ?></td>
        <td class="num">$<?= number_format($h['current_value'], 2) ?></td>
        <td class="num <?= $pnlClass ?>"><?= $h['unrealized_pnl'] >= 0 ? '+' : '' ?>$<?= number_format(abs($h['unrealized_pnl']), 2) ?></td>
        <td class="num <?= $pnlClass ?>"><?= $h['unrealized_pct'] >= 0 ? '+' : '' ?><?= $h['unrealized_pct'] ?>%</td>
        <td class="num" style="color:var(--pu)"><?= $h['actual_pct'] ?>%</td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="panel">
  <div class="state">
    <div class="state-i"></div>
    <div class="state-t">NO POSITIONS YET</div>
    <div class="state-s">Holdings will appear once the engine opens trades</div>
  </div>
</div>
<?php endif; ?>

</div><!-- /pp-tab-holdings -->

<!-- ── ALLOCATIONS ────────────────────────────────────────── -->
<div id="pp-tab-allocations" style="display:none">

<?php if (!empty($ppAlloc)): ?>
<div class="panel">
  <div class="ph">
    <div class="ph-t" style="color:var(--pu)">RECOMMENDED ALLOCATIONS</div>
    <div class="ph-m">CASH RESERVE: <?= number_format($ppSet['cash_reserve_pct'], 0) ?>%</div>
  </div>
  <div style="padding:14px 16px">
    <?php foreach ($ppAlloc as $al):
      $recPct = (float)$al['recommended_pct'];
      $actPct = (float)$al['actual_pct'];
      $drift  = (float)$al['drift_pct'];
      $driftClass = abs($drift) < 2 ? 'ok' : ($drift > 0 ? 'over' : 'under');
    ?>
    <div class="pp-alloc-row">
      <div class="pp-alloc-coin"><?= strtoupper(h($al['coin'])) ?></div>
      <div class="pp-alloc-bars">
        <div class="pp-bar pp-bar-rec" style="width:<?= min($recPct * 3, 100) ?>%"></div>
        <div class="pp-bar pp-bar-act" style="width:<?= min($actPct * 3, 100) ?>%"></div>
      </div>
      <div class="pp-alloc-pcts">
        <span style="color:var(--pu)"><?= number_format($recPct, 1) ?>%</span> /
        <span style="color:var(--t2)"><?= number_format($actPct, 1) ?>%</span>
      </div>
      <span class="pp-drift <?= $driftClass ?>">
        <?= abs($drift) < 0.1 ? '=' : ($drift > 0 ? '+' : '') . number_format($drift, 1) . '%' ?>
      </span>
    </div>
    <?php endforeach; ?>
    <div style="margin-top:8px;font-size:.68rem;color:var(--t3)">
      <span style="color:var(--pu)">purple</span> = recommended &nbsp;
      <span style="color:var(--t2)">gray</span> = actual
    </div>
  </div>
</div>
<?php else: ?>
<div class="panel">
  <div class="state">
    <div class="state-i"></div>
    <div class="state-t">NO ALLOCATIONS YET</div>
    <div class="state-s">Recommended allocations appear after the first analysis run</div>
  </div>
</div>
<?php endif; ?>

</div><!-- /pp-tab-allocations -->

<!-- ── TRADES ─────────────────────────────────────────────── -->
<div id="pp-tab-trades" style="display:none">

<?php
$ppTotalPnl   = round(array_sum(array_column($allTrades, 'pnl_usd')), 2);
$ppAllTradesD = array_merge($ppOpenTrades, $ppClosedTrades);
?>

<div class="panel">
  <div class="ph">
    <div class="ph-t" style="color:var(--pu)">TRADE HISTORY</div>
    <div class="ph-m">
      <?= count($ppOpenTrades) ?> OPEN ·
      <?= count($ppClosedTrades) ?> CLOSED ·
      P&amp;L:
      <span style="color:<?= $ppTotalPnl >= 0 ? 'var(--gn)' : 'var(--rd)' ?>">
        <?= $ppTotalPnl >= 0 ? '+' : '' ?>$<?= number_format(abs($ppTotalPnl), 2) ?>
      </span>
    </div>
  </div>

  <?php if (!empty($ppAllTradesD)): ?>
  <div class="trade-sort-bar" id="trade-sort-status"></div>
  <div class="tbl-wrap">
    <table id="pp-trades-table">
      <thead><tr>
        <th class="sortable" data-col="date"     onclick="tradeSort(this)">DATE<span class="sort-lbl"></span><span class="sort-hint">⇅</span></th>
        <th class="sortable" data-col="coin"     onclick="tradeSort(this)">COIN<span class="sort-lbl"></span><span class="sort-hint">⇅</span></th>
        <th class="sortable" data-col="strategy" onclick="tradeSort(this)">STRATEGY<span class="sort-lbl"></span><span class="sort-hint">⇅</span></th>
        <th class="sortable" data-col="action"   onclick="tradeSort(this)">ACTION<span class="sort-lbl"></span><span class="sort-hint">⇅</span></th>
        <th class="sortable" data-col="entry"    onclick="tradeSort(this)">ENTRY<span class="sort-lbl"></span><span class="sort-hint">⇅</span></th>
        <th class="sortable" data-col="exit"     onclick="tradeSort(this)">EXIT / CURRENT<span class="sort-lbl"></span><span class="sort-hint">⇅</span></th>
        <th class="sortable" data-col="size"     onclick="tradeSort(this)">SIZE (USD)<span class="sort-lbl"></span><span class="sort-hint">⇅</span></th>
        <th class="sortable" data-col="pnl"      onclick="tradeSort(this)">P&amp;L<span class="sort-lbl"></span><span class="sort-hint">⇅</span></th>
        <th class="sortable" data-col="status"   onclick="tradeSort(this)">STATUS<span class="sort-lbl"></span><span class="sort-hint">⇅</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($ppAllTradesD as $t):
        $pnlClass = $t['pnl_usd'] > 0 ? 'pnl-pos' : ($t['pnl_usd'] < 0 ? 'pnl-neg' : 'pnl-zero');
        $exitVal  = $t['status'] === 'closed' ? $t['exit_price'] : $t['current_price'];
        $dateStr  = date('Y-m-d H:i', strtotime($t['timestamp']));
        $dateSort = strtotime($t['timestamp']);
      ?>
      <tr>
        <td data-col="date"     data-val="<?= $dateSort ?>"><span style="font-size:.75rem;color:var(--t3)"><?= $dateStr ?></span></td>
        <td data-col="coin"     data-val="<?= h($t['coin']) ?>" style="font-weight:700;color:var(--pu)"><?= strtoupper(h($t['coin'])) ?></td>
        <td data-col="strategy" data-val="<?= h($t['strategy'] ?? 'unknown') ?>">
          <span style="font-size:.72rem;color:<?= strategyColor($t['strategy'] ?? '') ?>"><?= strtoupper(h($t['strategy'] ?? '—')) ?></span>
        </td>
        <td data-col="action"   data-val="<?= h($t['action']) ?>">
          <span class="<?= $t['action'] === 'buy' ? 'pnl-pos' : 'pnl-neg' ?>" style="font-weight:600"><?= strtoupper(h($t['action'])) ?></span>
        </td>
        <td data-col="entry"  data-val="<?= (float)$t['entry_price'] ?>" class="num">$<?= number_format((float)$t['entry_price'], 2) ?></td>
        <td data-col="exit"   data-val="<?= $exitVal !== null ? (float)$exitVal : 0 ?>" class="num">
          <?= $exitVal !== null ? '$'.number_format((float)$exitVal, 2) : '<span class="na">—</span>' ?>
          <?php if ($t['status'] === 'open'): ?>
          <span class="na" style="font-size:.65rem"> live</span>
          <?php endif; ?>
        </td>
        <td data-col="size"   data-val="<?= (float)$t['amount_usd'] ?>" class="num">$<?= number_format((float)$t['amount_usd'], 2) ?></td>
        <td data-col="pnl"    data-val="<?= $t['pnl_usd'] ?>" class="num <?= $pnlClass ?>">
          <?= $t['pnl_usd'] >= 0 ? '+' : '' ?>$<?= number_format(abs($t['pnl_usd']), 2) ?>
        </td>
        <td data-col="status" data-val="<?= h($t['status']) ?>">
          <span style="font-size:.68rem;color:<?= $t['status'] === 'open' ? 'var(--gn)' : 'var(--t3)' ?>;letter-spacing:.05em">
            <?= strtoupper(h($t['status'])) ?>
          </span>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="state">
    <div class="state-i"></div>
    <div class="state-t">NO TRADES YET</div>
    <div class="state-s">Trades will appear once the analysis engine generates signals</div>
  </div>
  <?php endif; ?>
</div>

</div><!-- /pp-tab-trades -->

<!-- ── PERFORMANCE ────────────────────────────────────────── -->
<div id="pp-tab-performance" style="display:none">

<?php $ppPerf = getPaperPortfolioPerformance(); ?>
<?php if (!empty($ppPerf)): ?>
<div class="panel">
  <div class="ph">
    <div class="ph-t" style="color:var(--pu)">STRATEGY ATTRIBUTION</div>
    <div class="ph-m">SINCE <?= $ppCfg['created_at'] ? strtoupper(date('d M Y', strtotime($ppCfg['created_at']))) : '—' ?></div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;padding:14px 16px">
    <?php foreach ($ppPerf as $sp): $sc = strategyColor($sp['strategy']); ?>
    <div style="padding:10px 14px;background:var(--s2);border:1px solid var(--b1);border-radius:8px;min-width:140px;flex:1">
      <div style="font-size:.68rem;color:<?= $sc ?>;font-weight:600;letter-spacing:.04em;margin-bottom:6px"><?= strtoupper(h($sp['strategy'])) ?></div>
      <div style="font-size:1rem;font-weight:700;color:<?= $sp['total_pnl'] >= 0 ? 'var(--gn)' : 'var(--rd)' ?>;margin-bottom:4px">
        <?= $sp['total_pnl'] >= 0 ? '+' : '' ?>$<?= number_format(abs($sp['total_pnl']), 2) ?>
      </div>
      <div style="font-size:.72rem;color:var(--t3);line-height:1.8">
        <?= $sp['total_trades'] ?> trades · <?= $sp['win_rate'] ?>% win rate<br>
        W<?= $sp['wins'] ?> / L<?= $sp['losses'] ?> · <?= $sp['open_trades'] ?> open
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php else: ?>
<div class="panel">
  <div class="state">
    <div class="state-i"></div>
    <div class="state-t">NO PERFORMANCE DATA YET</div>
    <div class="state-s">Strategy attribution appears once trades have been closed</div>
  </div>
</div>
<?php endif; ?>

</div><!-- /pp-tab-performance -->

<!-- ── SETTINGS ───────────────────────────────────────────── -->
<div id="pp-tab-settings" style="display:none">

<div class="panel">
  <div class="ph">
    <div class="ph-t" style="color:var(--pu)">RISK MANAGEMENT</div>
  </div>
  <div style="padding:14px 16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
    <div>
      <div style="font-size:.72rem;color:var(--t3);margin-bottom:6px;font-weight:500">MARGIN USAGE</div>
      <?php
        $marginTotal = $ppSet['margin_limit'] * $ppCfg['funded_amount'];
        $cashPct = $marginTotal > 0 ? min(100, max(0, ($ppCfg['cash_balance'] / ($ppCfg['funded_amount'] + $marginTotal)) * 100)) : 0;
        $barColor = $ppRisk['is_on_margin'] ? 'var(--rd)' : ($ppCfg['cash_balance'] < $ppCfg['funded_amount'] * 0.1 ? 'var(--yw)' : 'var(--gn)');
      ?>
      <div class="margin-bar">
        <div class="margin-fill" style="width:<?= max(5, $cashPct) ?>%;background:<?= $barColor ?>"></div>
      </div>
      <div style="font-size:.68rem;color:var(--t3);margin-top:4px">
        Cash: $<?= number_format(max(0, $ppCfg['cash_balance']), 2) ?> ·
        Margin avail: $<?= number_format($ppRisk['margin_available'], 2) ?>
      </div>
    </div>
    <div>
      <div style="font-size:.72rem;color:var(--t3);margin-bottom:6px;font-weight:500">CURRENT SETTINGS</div>
      <div style="font-size:.82rem;color:var(--t2);line-height:2">
        Stop-Loss: <strong style="color:var(--rd)"><?= number_format($ppSet['stop_loss_pct'], 0) ?>%</strong> ·
        Take-Profit: <strong style="color:var(--gn)"><?= number_format($ppSet['take_profit_pct'], 0) ?>%</strong> ·
        Cash Reserve: <strong style="color:var(--t1)"><?= number_format($ppSet['cash_reserve_pct'], 0) ?>%</strong>
      </div>
    </div>
    <div>
      <div style="font-size:.72rem;color:var(--t3);margin-bottom:6px;font-weight:500">ADJUST SETTINGS</div>
      <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="action" value="update_paper_settings">
        <input type="number" name="stop_loss_pct" placeholder="SL %" step="1" min="1" max="50" value="<?= (int)$ppSet['stop_loss_pct'] ?>"
               style="width:60px;background:var(--s1);border:1px solid var(--b2);color:var(--t1);padding:5px 8px;border-radius:6px;font-size:.78rem;font-family:var(--font)">
        <input type="number" name="take_profit_pct" placeholder="TP %" step="1" min="5" max="200" value="<?= (int)$ppSet['take_profit_pct'] ?>"
               style="width:60px;background:var(--s1);border:1px solid var(--b2);color:var(--t1);padding:5px 8px;border-radius:6px;font-size:.78rem;font-family:var(--font)">
        <input type="number" name="cash_reserve_pct" placeholder="Cash %" step="1" min="0" max="50" value="<?= (int)$ppSet['cash_reserve_pct'] ?>"
               style="width:60px;background:var(--s1);border:1px solid var(--b2);color:var(--t1);padding:5px 8px;border-radius:6px;font-size:.78rem;font-family:var(--font)">
        <button type="submit" class="btn btn-g" style="font-size:.72rem;padding:5px 10px">SAVE</button>
      </form>
    </div>
  </div>
</div>

</div><!-- /pp-tab-settings -->

<?php endif; // ppFunded ?>

</div><!-- /tab-paper -->
