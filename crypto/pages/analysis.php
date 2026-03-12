<?php // ══ ANALYSIS VIEW ══════════════════════════════════════════════════════════ ?>
<div class="panel">
  <div class="ph">
    <div class="ph-t">RANKED SIGNALS — ALL MODULES</div>
    <div style="display:flex;align-items:center;gap:12px">
      <div class="ph-m">UPDATED: <?= date('H:i:s') ?></div>
      <form method="POST" style="margin:0">
        <input type="hidden" name="action" value="run_analysis">
        <button type="submit" class="btn btn-g">RUN NOW</button>
      </form>
    </div>
  </div>

  <?php
  $filterStrat = $_GET['strat'] ?? 'all';
  $stratCounts = [];
  foreach ($signals as $s) $stratCounts[$s['strategy']] = ($stratCounts[$s['strategy']] ?? 0) + 1;
  $displayed   = $filterStrat === 'all' ? $signals
               : array_values(array_filter($signals, fn($s) => $s['strategy'] === $filterStrat));
  ?>

  <div class="filter-bar">
    <?php
    $pills = ['all'=>'ALL'] + array_map(fn($k)=>strtoupper($k), array_combine(
        ['arbitrage','pairs','rebalance','momentum'],
        ['arbitrage','pairs','rebalance','momentum']
    ));
    $pillColors = ['arbitrage'=>'var(--ac)','pairs'=>'var(--pu)','rebalance'=>'var(--yw)','momentum'=>'var(--or)','all'=>'var(--t1)'];
    foreach ($pills as $key => $label):
      $cnt    = $key==='all' ? count($signals) : ($stratCounts[$key] ?? 0);
      $active = $filterStrat === $key ? 'active' : '';
      $col    = $pillColors[$key] ?? 'var(--t2)';
      $style  = $active ? "background:$col;border-color:$col;" : '';
    ?>
    <a href="<?= buildUrl(['view'=>'analysis','strat'=>$key]) ?>"
       class="filter-pill <?= $active ?>" style="<?= $style ?>">
      <?= $label ?> <span style="opacity:.6"><?= $cnt ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($sigError): ?>
    <div class="err-box"><?= $sigError ?></div>
  <?php elseif (empty($displayed)): ?>
    <div class="state">
      <div class="state-i"></div>
      <div class="state-t">NO SIGNALS YET</div>
      <div class="state-s">Add coins to your portfolio and wait for the cron job to run</div>
    </div>
  <?php else: ?>
  <div class="signals-grid">
    <?php foreach ($displayed as $i => $sig):
      $details  = json_decode($sig['details'] ?? '{}', true) ?? [];
      $sc       = strategyColor($sig['strategy']);
      $sigc     = signalColor($sig['signal']);
      $icon     = signalIcon($sig['signal']);
      $coins    = explode(',', $sig['coins']);
      $pct      = round(($sig['strength'] ?? 0) * 100);
      $ts       = date('d M H:i', strtotime($sig['timestamp']));
    ?>
    <div class="sig-card" style="border-left:3px solid <?= $sc ?>">
      <div class="sig-top">
        <div class="sig-rank">#<?= $i+1 ?></div>
        <div class="strat-badge" style="background:<?= $sc ?>22;border:1px solid <?= $sc ?>44;color:<?= $sc ?>">
          <?= strtoupper(h($sig['strategy'])) ?>
        </div>
        <div class="coin-badges">
          <?php foreach ($coins as $c): ?>
          <span class="coin-badge"><?= strtoupper(h($c)) ?></span>
          <?php endforeach; ?>
        </div>
        <div class="sig-signal" style="color:<?= $sigc ?>"><?= $icon ?> <?= h($sig['signal']) ?></div>
        <div class="sig-sp"></div>
        <?php if ($sig['expected_usd'] > 0): ?>
        <div class="sig-usd">~$<?= number_format((float)$sig['expected_usd'], 2) ?></div>
        <?php else: ?>
        <div class="sig-usd zero">—</div>
        <?php endif; ?>
      </div>
      <div class="strength-wrap">
        <span style="font-size:.6rem;letter-spacing:2px;color:var(--t3)">STRENGTH</span>
        <div class="strength-bar">
          <div class="strength-fill" style="width:<?= $pct ?>%;background:<?= $sc ?>"></div>
        </div>
        <div class="strength-val"><?= $pct ?>% · score <?= number_format((float)($sig['strength']??0),3) ?></div>
      </div>
      <?php if (!empty($details)): ?>
      <div class="sig-detail">
        <details>
          <summary>DETAILS</summary>
          <div class="detail-json"><?= h(json_encode($details, JSON_PRETTY_PRINT)) ?></div>
        </details>
      </div>
      <?php endif; ?>
      <div class="sig-ts"><?= $ts ?> UTC</div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
