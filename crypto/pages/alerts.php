<?php // ══ EVENTS VIEW ══════════════════════════════════════════════════════════════ ?>

<!-- ── Section A: Risk Events ──────────────────────────────────────────────── -->
<div class="panel">
  <div class="ph">
    <div class="ph-t">RISK EVENTS</div>
    <div class="ph-m">STOP-LOSS &amp; TAKE-PROFIT TRIGGERS</div>
  </div>

  <?php if (empty($riskEvents)): ?>
  <div class="state">
    <div class="state-i"></div>
    <div class="state-t">NO RISK EVENTS YET</div>
    <div class="state-s">Stop-loss and take-profit triggers will appear here when the engine closes a position automatically</div>
  </div>
  <?php else: ?>
  <div class="signals-grid">
    <?php foreach ($riskEvents as $ev):
      $evD       = json_decode($ev['details'] ?? '{}', true) ?? [];
      $isSL      = $ev['signal'] === 'stop_loss';
      $evColor   = $isSL ? 'var(--rd)' : 'var(--gn)';
      $evIcon    = $isSL ? '↓' : '↑';
      $evLabel   = $isSL ? 'STOP LOSS' : 'TAKE PROFIT';
      $coin      = $ev['coins'];
      $pnlPct    = (float)($evD['pnl_pct']   ?? 0);
      $exitPrice = (float)($evD['exit_price'] ?? 0);
      $ts        = date('d M H:i', strtotime($ev['timestamp']));
      $isNew     = $ev['status'] === 'new';
    ?>
    <div class="event-card <?= $isSL ? 'risk-sl' : 'risk-tp' ?> <?= $isNew ? '' : 'seen' ?>">
      <div class="alert-top">
        <?php if ($isNew): ?>
        <span style="font-size:.58rem;letter-spacing:2px;color:<?= $evColor ?>;font-weight:700">NEW</span>
        <?php endif ?>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span class="strat-badge" style="background:<?= $evColor ?>22;border:1px solid <?= $evColor ?>44;color:<?= $evColor ?>">RISK</span>
          <span class="coin-badge" style="background:<?= $evColor ?>22;border-color:<?= $evColor ?>44;color:<?= $evColor ?>"><?= strtoupper(h($coin)) ?></span>
          <span class="pos-badge" style="background:<?= $evColor ?>22;color:<?= $evColor ?>;border-color:<?= $evColor ?>44"><?= $evIcon ?> <?= $evLabel ?></span>
        </div>
        <div class="sig-sp"></div>
        <?php if ($exitPrice > 0): ?>
        <div class="sig-usd">exit $<?= number_format($exitPrice, 2) ?></div>
        <?php endif ?>
      </div>
      <div style="font-size:.82rem;color:var(--t2);margin:6px 0 4px">
        Position closed with
        <strong style="color:<?= $pnlPct >= 0 ? 'var(--gn)' : 'var(--rd)' ?>">
          <?= $pnlPct >= 0 ? '+' : '' ?><?= number_format($pnlPct, 2) ?>%
        </strong>
        P&amp;L<?= $exitPrice > 0 ? ' at exit $' . number_format($exitPrice, 2) : '' ?>
      </div>
      <div class="sig-ts"><?= $ts ?> UTC · event #<?= $ev['id'] ?></div>
    </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>

<!-- ── Section B: Signal Events ────────────────────────────────────────────── -->
<div class="panel" style="margin-top:14px">
  <div class="ph">
    <div class="ph-t">
      SIGNAL EVENTS<?php if ($unseenCount > 0): ?> <span style="color:var(--rd)">(<?= $unseenCount ?> NEW)</span><?php endif ?>
    </div>
    <div style="display:flex;align-items:center;gap:10px">
      <div class="ph-m"><?= count($signalAlerts) ?> TOTAL</div>
      <?php if ($unseenCount > 0): ?>
      <form method="POST" style="margin:0">
        <input type="hidden" name="action" value="dismiss_all">
        <button type="submit" class="btn btn-g">DISMISS ALL</button>
      </form>
      <?php endif ?>
    </div>
  </div>

  <?php if (empty($signalAlerts)): ?>
  <div class="state">
    <div class="state-i"></div>
    <div class="state-t">NO SIGNAL EVENTS YET</div>
    <div class="state-s">Strong signals fire here once they exceed the alert threshold — run the analysis first</div>
  </div>
  <?php else: ?>
  <div class="signals-grid">
    <?php foreach ($signalAlerts as $al):
      $sc      = strategyColor($al['strategy']);
      $sigc    = signalColor($al['signal']);
      $icon    = signalIcon($al['signal']);
      $coins   = array_map('trim', explode(',', $al['coins']));
      $pct     = round($al['strength'] * 100);
      $ts      = date('d M H:i', strtotime($al['timestamp']));
      $isNew   = $al['status'] === 'new';
      $alD     = json_decode($al['details'] ?? '{}', true) ?? [];
      $isPairs = $al['strategy'] === 'pairs' && count($coins) === 2;
      $sigForVerb = array_merge($al, ['_d' => $alD]);
    ?>
    <div class="event-card <?= $isNew ? '' : 'seen' ?>">
      <div class="alert-top">
        <?php if ($isNew): ?>
        <span style="font-size:.58rem;letter-spacing:2px;color:var(--rd);font-weight:700">NEW</span>
        <?php endif ?>
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
          <div class="strat-badge" style="background:<?= $sc ?>22;border:1px solid <?= $sc ?>44;color:<?= $sc ?>">
            <?= strtoupper(h($al['strategy'])) ?>
          </div>
          <div class="coin-badges">
            <?php foreach ($coins as $c): ?>
            <span class="coin-badge"><?= strtoupper(h($c)) ?></span>
            <?php endforeach ?>
          </div>
        </div>

        <?php if ($al['signal'] !== 'hold' && $al['signal'] !== 'rebalance'):
          if ($isPairs):
            $actionA = $alD['action_a'] ?? $al['signal'];
            $actionB = $alD['action_b'] ?? ($al['signal'] === 'buy' ? 'sell' : 'buy');
            $pmA = positionMeta($actionA, $al['strategy']);
            $pmB = positionMeta($actionB, $al['strategy']);
          else:
            $pm = positionMeta($al['signal'], $al['strategy']);
          endif;
        ?>
        <div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;margin-top:4px">
          <?php if ($isPairs): ?>
            <span class="pos-badge" style="background:<?= $pmA['color'] ?>22;color:<?= $pmA['color'] ?>;border-color:<?= $pmA['color'] ?>44"><?= $pmA['icon'] ?> <?= $pmA['label'] ?> <?= strtoupper(h($coins[0])) ?></span>
            <span class="pos-badge" style="background:<?= $pmB['color'] ?>22;color:<?= $pmB['color'] ?>;border-color:<?= $pmB['color'] ?>44"><?= $pmB['icon'] ?> <?= $pmB['label'] ?> <?= strtoupper(h($coins[1])) ?></span>
          <?php else: ?>
            <?php foreach ($coins as $c): ?>
            <span class="pos-badge" style="background:<?= $pm['color'] ?>22;color:<?= $pm['color'] ?>;border-color:<?= $pm['color'] ?>44"><?= $pm['icon'] ?> <?= $pm['label'] ?> <?= strtoupper(h($c)) ?></span>
            <?php endforeach ?>
          <?php endif ?>
          <span style="font-size:.6rem;color:var(--t3);letter-spacing:.04em">MARKET</span>
        </div>
        <?php endif ?>

        <div class="sig-signal" style="color:<?= $sigc ?>"><?= $icon ?> <?= h($al['signal']) ?></div>
        <div class="sig-sp"></div>
        <?php if ($al['expected_usd'] > 0): ?>
        <div class="sig-usd">~$<?= number_format((float)$al['expected_usd'], 2) ?></div>
        <?php endif ?>
      </div>

      <div style="font-size:.76rem;color:var(--t2);margin:6px 0 4px;line-height:1.4">
        <?= h(signalVerb($sigForVerb)) ?>
      </div>

      <div class="strength-wrap">
        <span style="font-size:.6rem;letter-spacing:2px;color:var(--t3)">STRENGTH</span>
        <div class="strength-bar">
          <div class="strength-fill" style="width:<?= $pct ?>%;background:<?= $sc ?>"></div>
        </div>
        <div class="strength-val"><?= $pct ?>% · score <?= number_format((float)$al['strength'], 3) ?></div>
      </div>
      <div class="sig-ts"><?= $ts ?> UTC · event #<?= $al['id'] ?></div>
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
  <a href="<?= buildUrl(['view'=>'portfolio']) ?>#paper:trades" class="btn" style="font-size:.78rem;padding:6px 14px">View Trades</a>
</div>
<?php endif ?>
