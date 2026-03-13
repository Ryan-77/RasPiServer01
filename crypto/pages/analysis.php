<?php // ══ ANALYSIS VIEW ════════════════════════════════════════════════════════════ ?>
<?php
$hasPP    = !empty($ppConfigA) && isset($ppConfigA['total_value']);
$ppVal    = $hasPP ? (float)$ppConfigA['total_value']   : 0;
$ppCash   = $hasPP ? (float)$ppConfigA['cash_balance']  : 0;
$ppFunded = $hasPP ? (float)$ppConfigA['funded_amount'] : 0;
$ppReturn = $ppFunded > 0 ? round(($ppVal - $ppFunded) / $ppFunded * 100, 2) : 0;
$ppStatus = $hasPP ? ($ppConfigA['status'] ?? 'unknown') : null;

$lastTs   = $signals[0]['timestamp'] ?? null;
$minsAgo  = $lastTs ? max(0, round((time() - strtotime($lastTs)) / 60)) : null;

// Signals from the most recent run (within 10 min of latest timestamp)
$currentRun = array_filter($signals, fn($s) =>
    $lastTs && abs(strtotime($s['timestamp']) - strtotime($lastTs)) < 600
);
$currentRunCount = count($currentRun);

// Pre-compute counts for tab badges
$cntEngine   = count($ppAllocations ?? []);
$cntMomentum = count($byStrategy['momentum'] ?? []);
$cntPairs    = count($byStrategy['pairs']    ?? []);
$cntArb      = count($byStrategy['arbitrage'] ?? []);
$feedSignals = array_filter($signals, fn($s) => $s['signal'] !== 'hold');
$feedSignals = array_slice(array_values($feedSignals), 0, 20);
$cntSignals  = count($feedSignals);
?>

<!-- ── Section A: Engine Status Bar ──────────────────────────────────────── -->
<div class="analysis-status-bar">
  <div class="status-block">
    <span class="status-lbl">LAST RUN</span>
    <span class="status-val">
      <?= $lastTs ? date('H:i', strtotime($lastTs)) . ' UTC' : '—' ?>
      <?php if ($minsAgo !== null): ?>
      <span class="status-sub"><?= $minsAgo ?>m ago</span>
      <?php endif; ?>
    </span>
  </div>
  <div class="status-block">
    <span class="status-lbl">SCHEDULE</span>
    <span class="status-val">*/5 * * * *</span>
    <span class="status-sub">every 5 min</span>
  </div>
  <?php if ($hasPP): ?>
  <div class="status-block">
    <span class="status-lbl">PORTFOLIO</span>
    <span class="status-val" style="color:var(--pu)">$<?= number_format($ppVal, 2) ?></span>
    <span class="status-sub">
      cash $<?= number_format($ppCash, 2) ?> ·
      <span style="color:<?= $ppReturn >= 0 ? 'var(--gn)' : 'var(--rd)' ?>"><?= $ppReturn >= 0 ? '+' : '' ?><?= number_format($ppReturn, 2) ?>%</span>
    </span>
  </div>
  <?php endif; ?>
  <div class="status-block">
    <span class="status-lbl">SIGNALS</span>
    <span class="status-val"><?= $currentRunCount ?> this run</span>
    <?php if ($unseenCount > 0): ?>
    <span class="status-sub" style="color:var(--rd)"><?= $unseenCount ?> new event<?= $unseenCount !== 1 ? 's' : '' ?></span>
    <?php endif; ?>
  </div>
  <div class="status-block" style="margin-left:auto">
    <form method="POST" style="margin:0">
      <input type="hidden" name="action" value="run_analysis">
      <button type="submit" class="btn btn-g">▶ RUN NOW</button>
    </form>
  </div>
</div>

<!-- ── Sub-tabs ──────────────────────────────────────────────────────────── -->
<div class="sub-tabs analysis-tabs">
  <button class="sub-tab active" data-atab="engine" onclick="switchAnalysisTab('engine')">
    ENGINE<?php if ($cntEngine): ?><span class="atab-count"><?= $cntEngine ?></span><?php endif ?>
  </button>
  <button class="sub-tab" data-atab="momentum" onclick="switchAnalysisTab('momentum')">
    MOMENTUM<?php if ($cntMomentum): ?><span class="atab-count"><?= $cntMomentum ?></span><?php endif ?>
  </button>
  <button class="sub-tab" data-atab="pairs" onclick="switchAnalysisTab('pairs')">
    PAIRS<?php if ($cntPairs): ?><span class="atab-count"><?= $cntPairs ?></span><?php endif ?>
  </button>
  <button class="sub-tab" data-atab="arbitrage" onclick="switchAnalysisTab('arbitrage')">
    ARBITRAGE<?php if ($cntArb): ?><span class="atab-count"><?= $cntArb ?></span><?php endif ?>
  </button>
  <button class="sub-tab" data-atab="signals" onclick="switchAnalysisTab('signals')">
    SIGNALS<?php if ($cntSignals): ?><span class="atab-count"><?= $cntSignals ?></span><?php endif ?>
  </button>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- TAB: ENGINE — Allocation Engine + User Rebalance                       -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div id="atab-engine">
<?php if (!empty($ppAllocations)): ?>
<div class="panel" style="margin-top:14px">
  <div class="ph">
    <div class="ph-t">ALLOCATION ENGINE</div>
    <div class="ph-m">
      <?= count($ppAllocations) ?> COINS ·
      <?php if ($hasPP): ?>
      PORTFOLIO $<?= number_format($ppVal, 2) ?> · CASH $<?= number_format($ppCash, 2) ?>
      <?php if ($ppStatus === 'paused'): ?>
      · <span style="color:var(--yw)">PAUSED</span>
      <?php endif; ?>
      <?php else: ?>
      PORTFOLIO NOT FUNDED — REC % ONLY
      <?php endif; ?>
    </div>
  </div>
  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>RANK</th>
        <th>COIN</th>
        <th>PRICE</th>
        <th>REC %</th>
        <?php if ($hasPP): ?>
        <th>ACTUAL %</th>
        <th style="min-width:130px">DRIFT</th>
        <th>ACTION</th>
        <?php endif; ?>
        <th>RATIONALE</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ppAllocations as $al):
        $coin       = $al['coin'];
        $rank       = $rankMap[$coin] ?? null;
        $price      = $latestPrices[$coin] ?? null;
        $rec        = (float)$al['recommended_pct'];
        $act        = (float)$al['actual_pct'];
        $drift      = (float)$al['drift_pct'];
        $da         = $hasPP ? driftAction($drift, $ppVal) : null;
        $driftW     = min(abs($drift) * 4, 100);
        $driftColor = $drift < -2 ? 'var(--gn)' : ($drift > 2 ? 'var(--rd)' : 'var(--t3)');
      ?>
      <tr>
        <td class="muted" style="font-size:.72rem;text-align:center">
          <?= $rank !== null ? '#' . $rank : '—' ?>
        </td>
        <td>
          <span style="font-weight:700;color:var(--pu)"><?= strtoupper(h($coin)) ?></span>
          <?php if (isset($SUPPORTED_COINS[$coin])): ?>
          <div style="font-size:.68rem;color:var(--t3)"><?= h($SUPPORTED_COINS[$coin]) ?></div>
          <?php endif; ?>
        </td>
        <td class="num"><?= $price !== null ? '$' . number_format($price, 2) : '<span class="na">—</span>' ?></td>
        <td class="num" style="color:var(--pu);font-weight:600"><?= number_format($rec, 1) ?>%</td>
        <?php if ($hasPP): ?>
        <td class="num"><?= number_format($act, 1) ?>%</td>
        <td>
          <div class="drift-bar-cell">
            <div class="drift-bar-track">
              <div class="drift-fill" style="width:<?= $driftW ?>%;background:<?= $driftColor ?>"></div>
            </div>
            <span style="font-size:.72rem;color:<?= $driftColor ?>;min-width:46px;text-align:right;display:inline-block">
              <?= $drift >= 0 ? '+' : '' ?><?= number_format($drift, 1) ?>%
            </span>
          </div>
        </td>
        <td>
          <?php if ($da): ?>
          <span class="action-badge" style="background:<?= $da['color'] ?>22;color:<?= $da['color'] ?>;border-color:<?= $da['color'] ?>44">
            <?= $da['icon'] ?> <?= $da['label'] ?>
            <?php if ($da['amount_usd'] > 0): ?>&nbsp;~$<?= number_format($da['amount_usd'], 0) ?><?php endif; ?>
          </span>
          <?php endif; ?>
        </td>
        <?php endif; ?>
        <td style="font-size:.72rem;color:var(--t3);max-width:240px">
          <?= h(parseAllocReason($al['reason'] ?? '[]')) ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php /* ── Rebalance (user targets) — inside ENGINE tab ─────── */ ?>
<?php if (!empty($byStrategy['rebalance']) && $hasTargets): ?>
<div class="panel strategy-card" style="margin-top:14px">
  <div class="ph">
    <div class="ph-t" style="color:var(--yw)">⇄ PORTFOLIO REBALANCE — USER TARGETS</div>
    <div class="ph-m"><?= count($byStrategy['rebalance']) ?> SIGNAL<?= count($byStrategy['rebalance']) !== 1 ? 'S' : '' ?></div>
  </div>
  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>COIN</th><th>CURRENT %</th><th>TARGET %</th><th>DRIFT</th><th>ACTION</th><th>TRADE $</th><th>EXPLANATION</th>
      </tr></thead>
      <tbody>
      <?php foreach ($byStrategy['rebalance'] as $sig):
        $d      = $sig['_d'] ?? [];
        $coin   = trim(explode(',', $sig['coins'])[0]);
        $curPct = (float)($d['current_pct'] ?? 0);
        $tgtPct = (float)($d['target_pct']  ?? 0);
        $dPct   = (float)($d['drift_pct']   ?? 0);
        $delta  = (float)($d['delta_usd']   ?? 0);
        $dCol   = $dPct > 0 ? 'var(--rd)' : 'var(--gn)';
        $pm     = positionMeta($sig['signal'], 'rebalance');
      ?>
      <tr>
        <td style="font-weight:700;color:var(--yw)"><?= strtoupper(h($coin)) ?></td>
        <td class="num"><?= number_format($curPct, 1) ?>%</td>
        <td class="num"><?= number_format($tgtPct, 1) ?>%</td>
        <td class="num" style="color:<?= $dCol ?>"><?= $dPct >= 0 ? '+' : '' ?><?= number_format($dPct, 1) ?>%</td>
        <td>
          <span class="pos-badge" style="background:<?= $pm['color'] ?>22;color:<?= $pm['color'] ?>;border-color:<?= $pm['color'] ?>44">
            <?= $pm['icon'] ?> <?= $pm['label'] ?>
          </span>
        </td>
        <td class="num">$<?= number_format(abs($delta), 2) ?></td>
        <td style="font-size:.74rem;color:var(--t3)"><?= h(signalVerb($sig)) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (empty($ppAllocations)): ?>
<div class="panel" style="margin-top:14px">
  <div class="state">
    <div class="state-i">⚙</div>
    <div class="state-t">NO ALLOCATION DATA</div>
    <div class="state-s">Run the engine to generate recommended allocations</div>
  </div>
</div>
<?php endif; ?>
</div><!-- /atab-engine -->

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- TAB: MOMENTUM — RSI Signals                                            -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div id="atab-momentum" style="display:none">
<?php if (!empty($byStrategy['momentum'])): ?>
<div class="panel strategy-card" style="margin-top:14px">
  <div class="ph">
    <div class="ph-t" style="color:var(--or)">⚡ MOMENTUM — RSI SIGNALS</div>
    <div class="ph-m"><?= $cntMomentum ?> COIN<?= $cntMomentum !== 1 ? 'S' : '' ?></div>
  </div>
  <?php foreach ($byStrategy['momentum'] as $sig):
    $d      = $sig['_d'] ?? [];
    $rsi    = (float)($d['rsi']      ?? 50);
    $rsiH   = (float)($d['rsi_high'] ?? 65);
    $rsiL   = (float)($d['rsi_low']  ?? 35);
    $roc    = (float)($d['roc_10']   ?? 0);
    $lbl    = ucfirst($d['label']    ?? 'neutral');
    $coin   = trim(explode(',', $sig['coins'])[0]);
    $price  = $latestPrices[$coin]   ?? null;
    $sigc   = signalColor($sig['signal']);
    $icon   = signalIcon($sig['signal']);
    $pct    = round($sig['strength'] * 100);
    $rsiColor = $rsi >= $rsiH ? 'var(--rd)' : ($rsi <= $rsiL ? 'var(--gn)' : 'var(--t2)');
    $expUsd = $hasPP ? round($ppVal * $sig['strength'] * 0.10, 2) : (float)($sig['expected_usd'] ?? 0);
  ?>
  <div style="padding:14px 16px;border-top:1px solid var(--b1);display:grid;grid-template-columns:110px 1fr 100px;gap:16px;align-items:start">
    <!-- Coin info -->
    <div>
      <div style="font-weight:700;font-size:.96rem;color:var(--or)"><?= strtoupper(h($coin)) ?></div>
      <?php if ($price !== null): ?>
      <div class="muted" style="font-size:.74rem;margin-top:2px">$<?= number_format($price, 2) ?></div>
      <?php endif; ?>
      <div style="margin-top:6px">
        <span class="pos-badge" style="background:<?= $rsiColor ?>22;color:<?= $rsiColor ?>;border-color:<?= $rsiColor ?>44">
          <?= $lbl ?>
        </span>
      </div>
    </div>
    <!-- RSI gauge + verbal -->
    <div>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <span style="font-size:.64rem;color:var(--t3);letter-spacing:.06em;min-width:28px">RSI</span>
        <div class="rsi-gauge" style="flex:1;max-width:220px">
          <div class="rsi-marker" style="left:<?= $rsiL ?>%"></div>
          <div class="rsi-marker" style="left:<?= $rsiH ?>%"></div>
          <div style="position:absolute;top:50%;left:<?= min(max($rsi,2),98) ?>%;transform:translate(-50%,-50%);width:11px;height:11px;border-radius:50%;background:<?= $rsiColor ?>;border:2px solid var(--s1);z-index:2"></div>
        </div>
        <span style="font-size:.88rem;font-weight:700;color:<?= $rsiColor ?>;min-width:38px"><?= number_format($rsi, 1) ?></span>
        <span style="font-size:.62rem;color:var(--t3)"><?= $rsiL ?>↓ <?= $rsiH ?>↑</span>
      </div>
      <div style="font-size:.78rem;color:var(--t2);line-height:1.5"><?= h(signalVerb($sig)) ?></div>
    </div>
    <!-- Signal + strength -->
    <div style="text-align:right">
      <div style="font-size:.88rem;font-weight:700;color:<?= $sigc ?>"><?= $icon ?> <?= h($sig['signal']) ?></div>
      <div style="font-size:.68rem;color:var(--t3);margin-top:2px"><?= $pct ?>% strength</div>
      <div class="strength-bar" style="margin-top:6px;margin-left:auto;width:60px">
        <div class="strength-fill" style="width:<?= $pct ?>%;background:var(--or)"></div>
      </div>
      <div style="font-size:.68rem;color:var(--t3);margin-top:4px">
        10h: <?= $roc >= 0 ? '+' : '' ?><?= number_format($roc, 2) ?>%
      </div>
      <?php if ($expUsd > 0): ?>
      <div style="font-size:.72rem;color:var(--t2);margin-top:4px">~$<?= number_format($expUsd, 2) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="panel" style="margin-top:14px">
  <div class="state">
    <div class="state-i">⚡</div>
    <div class="state-t">NO MOMENTUM SIGNALS</div>
    <div class="state-s">No overbought or oversold conditions detected this run</div>
  </div>
</div>
<?php endif; ?>
</div><!-- /atab-momentum -->

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- TAB: PAIRS — Divergence Signals                                        -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div id="atab-pairs" style="display:none">
<?php if (!empty($byStrategy['pairs'])): ?>
<div class="panel strategy-card" style="margin-top:14px">
  <div class="ph">
    <div class="ph-t" style="color:var(--pu)">⇄ PAIRS TRADING — DIVERGENCE</div>
    <div class="ph-m"><?= $cntPairs ?> PAIR<?= $cntPairs !== 1 ? 'S' : '' ?></div>
  </div>
  <?php foreach ($byStrategy['pairs'] as $sig):
    $d     = $sig['_d'] ?? [];
    $z     = (float)($d['zscore'] ?? 0);
    $zCol  = abs($z) >= 2 ? 'var(--rd)' : (abs($z) >= 1.5 ? 'var(--yw)' : 'var(--t2)');
    $coins = array_map('trim', explode(',', $sig['coins']));
    $actA  = $d['action_a'] ?? $sig['signal'];
    $actB  = $d['action_b'] ?? ($sig['signal'] === 'buy' ? 'sell' : 'buy');
    $pmA   = positionMeta($actA, 'pairs');
    $pmB   = positionMeta($actB, 'pairs');
    $pct   = round($sig['strength'] * 100);
    $expUsd = $hasPP ? round($ppVal * $sig['strength'] * 0.05, 2) : (float)($sig['expected_usd'] ?? 0);
  ?>
  <div style="padding:14px 16px;border-top:1px solid var(--b1)">
    <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <!-- Pair + actions -->
      <div style="min-width:170px">
        <div class="coin-badges" style="margin-bottom:8px">
          <?php foreach ($coins as $c): ?>
          <span class="coin-badge" style="background:var(--pu)22;border-color:var(--pu)44;color:var(--pu)"><?= strtoupper(h($c)) ?></span>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;flex-direction:column;gap:4px">
          <?php if (count($coins) >= 2): ?>
          <span class="pos-badge" style="background:<?= $pmA['color'] ?>22;color:<?= $pmA['color'] ?>;border-color:<?= $pmA['color'] ?>44">
            <?= $pmA['icon'] ?> <?= $pmA['label'] ?> <?= strtoupper(h($coins[0])) ?>
          </span>
          <span class="pos-badge" style="background:<?= $pmB['color'] ?>22;color:<?= $pmB['color'] ?>;border-color:<?= $pmB['color'] ?>44">
            <?= $pmB['icon'] ?> <?= $pmB['label'] ?> <?= strtoupper(h($coins[1])) ?>
          </span>
          <?php endif; ?>
        </div>
        <?php if ($expUsd > 0): ?>
        <div style="font-size:.7rem;color:var(--t3);margin-top:6px">~$<?= number_format($expUsd, 0) ?>/side</div>
        <?php endif; ?>
      </div>
      <!-- Verbal + z-score -->
      <div style="flex:1;min-width:200px">
        <div style="font-size:.78rem;color:var(--t2);line-height:1.5;margin-bottom:10px">
          <?= h(signalVerb($sig)) ?>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <div>
            <span style="font-size:.62rem;color:var(--t3);letter-spacing:.06em;display:block;margin-bottom:2px">Z-SCORE</span>
            <span style="font-size:1.3rem;font-weight:700;color:<?= $zCol ?>"><?= number_format($z, 2) ?>σ</span>
          </div>
          <div>
            <span style="font-size:.62rem;color:var(--t3);letter-spacing:.06em;display:block;margin-bottom:2px">STRENGTH</span>
            <div style="display:flex;align-items:center;gap:6px">
              <div class="strength-bar" style="width:80px">
                <div class="strength-fill" style="width:<?= $pct ?>%;background:var(--pu)"></div>
              </div>
              <span style="font-size:.72rem;color:var(--t3)"><?= $pct ?>%</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="panel" style="margin-top:14px">
  <div class="state">
    <div class="state-i">⇄</div>
    <div class="state-t">NO PAIRS SIGNALS</div>
    <div class="state-s">No statistically significant price divergences detected</div>
  </div>
</div>
<?php endif; ?>
</div><!-- /atab-pairs -->

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- TAB: ARBITRAGE — Cycle Opportunities                                   -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div id="atab-arbitrage" style="display:none">
<?php if (!empty($byStrategy['arbitrage'])): ?>
<div class="panel strategy-card" style="margin-top:14px">
  <div class="ph">
    <div class="ph-t" style="color:var(--ac)">△ ARBITRAGE — CYCLE OPPORTUNITIES</div>
    <div class="ph-m"><?= $cntArb ?> CYCLE<?= $cntArb !== 1 ? 'S' : '' ?></div>
  </div>
  <?php foreach ($byStrategy['arbitrage'] as $sig):
    $d        = $sig['_d'] ?? [];
    $cycle    = $d['cycle']        ?? [];
    $gain     = (float)($d['net_gain_pct']  ?? 0);
    $factor   = (float)($d['factor']        ?? 1);
    $feeRate  = (float)($d['fee_rate']      ?? 0.001);
    $notional = $hasPP ? round($ppVal * 0.10, 2) : (float)($d['trade_usd_est'] ?? 1000);
    $estProfit = round($notional * $gain / 100, 2);
    $gainColor = $gain > 0 ? 'var(--gn)' : 'var(--rd)';
    $pct       = round($sig['strength'] * 100);
  ?>
  <div style="padding:14px 16px;border-top:1px solid var(--b1)">
    <!-- Cycle path -->
    <?php if (!empty($cycle)): ?>
    <div class="arb-path" style="margin-bottom:10px">
      <?php foreach ($cycle as $i => $c): ?>
      <?php if ($i > 0): ?><span class="arb-arrow">→</span><?php endif; ?>
      <span class="coin-badge" style="background:var(--ac)22;border-color:var(--ac)44;color:var(--ac)"><?= strtoupper(h($c)) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <!-- Metrics row -->
    <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;margin-bottom:10px">
      <div>
        <div style="font-size:.62rem;color:var(--t3);letter-spacing:.06em;margin-bottom:2px">NET GAIN</div>
        <div style="font-size:1.2rem;font-weight:700;color:<?= $gainColor ?>"><?= $gain >= 0 ? '+' : '' ?><?= number_format($gain, 3) ?>%</div>
      </div>
      <div>
        <div style="font-size:.62rem;color:var(--t3);letter-spacing:.06em;margin-bottom:2px">EST. PROFIT</div>
        <div style="font-size:.92rem;font-weight:600;color:<?= $gainColor ?>">~$<?= number_format(abs($estProfit), 2) ?></div>
        <div style="font-size:.64rem;color:var(--t3)">on $<?= number_format($notional, 0) ?> notional</div>
      </div>
      <div>
        <div style="font-size:.62rem;color:var(--t3);letter-spacing:.06em;margin-bottom:2px">FACTOR</div>
        <div style="font-size:.88rem;font-weight:600;color:var(--t2)"><?= number_format($factor, 5) ?>x</div>
      </div>
      <div>
        <div style="font-size:.62rem;color:var(--t3);letter-spacing:.06em;margin-bottom:2px">FEES</div>
        <div style="font-size:.88rem;color:var(--t2)"><?= number_format($feeRate * 100, 2) ?>%</div>
      </div>
      <div>
        <div style="font-size:.62rem;color:var(--t3);letter-spacing:.06em;margin-bottom:2px">STRENGTH</div>
        <div style="display:flex;align-items:center;gap:6px">
          <div class="strength-bar" style="width:60px">
            <div class="strength-fill" style="width:<?= $pct ?>%;background:var(--ac)"></div>
          </div>
          <span style="font-size:.72rem;color:var(--t3)"><?= $pct ?>%</span>
        </div>
      </div>
    </div>
    <div style="font-size:.78rem;color:var(--t2)"><?= h(signalVerb($sig)) ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="panel" style="margin-top:14px">
  <div class="state">
    <div class="state-i">△</div>
    <div class="state-t">NO ARBITRAGE OPPORTUNITIES</div>
    <div class="state-s">No profitable exchange rate cycles found this run</div>
  </div>
</div>
<?php endif; ?>
</div><!-- /atab-arbitrage -->

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- TAB: SIGNALS — Compact Feed                                            -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div id="atab-signals" style="display:none">
<?php if (!empty($feedSignals)): ?>
<div class="panel" style="margin-top:14px">
  <div class="ph">
    <div class="ph-t">SIGNAL FEED</div>
    <div class="ph-m">LAST <?= count($feedSignals) ?> NON-HOLD SIGNALS</div>
  </div>
  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>TIME (UTC)</th><th>STRATEGY</th><th>COINS</th><th>SIGNAL</th><th>STRENGTH</th><th>EXPECTED $</th>
      </tr></thead>
      <tbody>
      <?php foreach ($feedSignals as $sig):
        $sc    = strategyColor($sig['strategy']);
        $sigc  = signalColor($sig['signal']);
        $icon  = signalIcon($sig['signal']);
        $coins = array_map('trim', explode(',', $sig['coins']));
        $ts    = date('d M H:i', strtotime($sig['timestamp']));
        $pct   = round($sig['strength'] * 100);
        $expUsd = $hasPP ? round($ppVal * $sig['strength'] * 0.05, 2) : (float)($sig['expected_usd'] ?? 0);
      ?>
      <tr>
        <td class="muted" style="font-size:.72rem"><?= $ts ?></td>
        <td>
          <span class="strat-badge" style="background:<?= $sc ?>22;border:1px solid <?= $sc ?>44;color:<?= $sc ?>">
            <?= strtoupper(h($sig['strategy'])) ?>
          </span>
        </td>
        <td>
          <div class="coin-badges">
            <?php foreach ($coins as $c): ?>
            <span class="coin-badge"><?= strtoupper(h($c)) ?></span>
            <?php endforeach; ?>
          </div>
        </td>
        <td style="color:<?= $sigc ?>;font-weight:600;font-size:.82rem"><?= $icon ?> <?= h($sig['signal']) ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:6px">
            <div class="strength-bar" style="width:56px">
              <div class="strength-fill" style="width:<?= $pct ?>%;background:<?= $sc ?>"></div>
            </div>
            <span style="font-size:.68rem;color:var(--t3)"><?= $pct ?>%</span>
          </div>
        </td>
        <td class="num">
          <?= $expUsd > 0 ? '~$' . number_format($expUsd, 2) : '<span class="na">—</span>' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="panel" style="margin-top:14px">
  <div class="state">
    <div class="state-i">📡</div>
    <div class="state-t">NO SIGNALS</div>
    <div class="state-s">Add coins to your portfolio and run the engine using the button above</div>
  </div>
</div>
<?php endif; ?>
</div><!-- /atab-signals -->
