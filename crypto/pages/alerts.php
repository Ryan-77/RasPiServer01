<?php // ══ ALERTS VIEW ════════════════════════════════════════════════════════════ ?>
<div class="panel">
  <div class="ph">
    <div class="ph-t">ALERTS<?php if ($unseenCount > 0): ?> <span style="color:var(--rd)">(<?= $unseenCount ?> NEW)</span><?php endif ?></div>
    <div style="display:flex;align-items:center;gap:10px">
      <div class="ph-m"><?= count($alerts) ?> TOTAL</div>
      <?php if ($unseenCount > 0): ?>
      <form method="POST" style="margin:0">
        <input type="hidden" name="action" value="dismiss_all">
        <button type="submit" class="btn btn-g">DISMISS ALL</button>
      </form>
      <?php endif ?>
    </div>
  </div>

  <?php if (empty($alerts)): ?>
    <div class="state">
      <div class="state-i"></div>
      <div class="state-t">NO ALERTS YET</div>
      <div class="state-s">Alerts fire when signal strength exceeds the threshold — run the analysis first</div>
    </div>
  <?php else: ?>
  <div class="signals-grid">
    <?php foreach ($alerts as $al):
      $sc    = strategyColor($al['strategy']);
      $sigc  = signalColor($al['signal']);
      $icon  = signalIcon($al['signal']);
      $coins = explode(',', $al['coins']);
      $pct   = round($al['strength'] * 100);
      $ts    = date('d M H:i', strtotime($al['timestamp']));
      $isNew = $al['status'] === 'new';
    ?>
    <div class="alert-card <?= $isNew ? '' : 'seen' ?>">
      <div class="alert-top">
        <?php if ($isNew): ?>
        <span style="font-size:.58rem;letter-spacing:2px;color:var(--rd);font-weight:700">NEW</span>
        <?php endif ?>
        <div class="strat-badge" style="background:<?= $sc ?>22;border:1px solid <?= $sc ?>44;color:<?= $sc ?>">
          <?= strtoupper(h($al['strategy'])) ?>
        </div>
        <div class="coin-badges">
          <?php foreach ($coins as $c): ?>
          <span class="coin-badge"><?= strtoupper(h($c)) ?></span>
          <?php endforeach ?>
        </div>
        <div class="sig-signal" style="color:<?= $sigc ?>"><?= $icon ?> <?= h($al['signal']) ?></div>
        <div class="sig-sp"></div>
        <?php if ($al['expected_usd'] > 0): ?>
        <div class="sig-usd">~$<?= number_format((float)$al['expected_usd'], 2) ?></div>
        <?php endif ?>
        <?php if ($isNew): ?>
        <form method="POST" style="margin:0">
          <input type="hidden" name="action" value="dismiss_alert">
          <input type="hidden" name="alert_id" value="<?= $al['id'] ?>">
          <button type="submit" class="btn-del">SEEN</button>
        </form>
        <?php endif ?>
      </div>
      <div class="strength-wrap">
        <span style="font-size:.6rem;letter-spacing:2px;color:var(--t3)">STRENGTH</span>
        <div class="strength-bar">
          <div class="strength-fill" style="width:<?= $pct ?>%;background:<?= $sc ?>"></div>
        </div>
        <div class="strength-val"><?= $pct ?>% · score <?= number_format((float)$al['strength'], 3) ?></div>
      </div>
      <div class="sig-ts"><?= $ts ?> UTC · alert #<?= $al['id'] ?></div>
    </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>

<?php if (!empty($allTrades)): ?>
<div style="margin-top:14px;padding:12px 16px;background:var(--s2);border:1px solid var(--b1);border-radius:var(--r);display:flex;align-items:center;justify-content:space-between">
  <span style="font-size:.84rem;color:var(--t2)">
    <?= $openCount ?> open trade<?= $openCount !== 1 ? 's' : '' ?> · <?= count($closedTrades) ?> closed ·
    Total P&amp;L: <strong style="color:<?= $paperPnl >= 0 ? 'var(--gn)' : 'var(--rd)' ?>"><?= $paperPnl >= 0 ? '+' : '' ?>$<?= number_format(abs($paperPnl), 2) ?></strong>
  </span>
  <a href="<?= buildUrl(['view'=>'trades']) ?>" class="btn" style="font-size:.78rem;padding:6px 14px">View Trades</a>
</div>
<?php endif ?>
