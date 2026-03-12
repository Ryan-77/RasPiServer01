<?php
// ══════════════════════════════════════════════════════════════
//  SHARED VIEW HELPERS — used by all page templates
// ══════════════════════════════════════════════════════════════

function buildUrl(array $p = []): string {
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    $q    = array_merge(['view' => $_GET['view'] ?? 'portfolio'], $p);
    unset($q['page']);
    if (isset($p['page'])) $q['page'] = $p['page'];
    return $base . '?' . http_build_query($q);
}

function strategyColor(string $s): string {
    return match($s) {
        'arbitrage' => '#2563eb', 'pairs'  => '#7c3aed',
        'rebalance' => '#d97706', 'momentum' => '#ea580c',
        default     => '#78716c',
    };
}

function signalColor(string $sig): string {
    if (str_contains($sig,'buy') || $sig==='opportunity') return '#16a34a';
    if (str_contains($sig,'sell')) return '#dc2626';
    if ($sig==='rebalance') return '#d97706';
    return '#78716c';
}

function signalIcon(string $sig): string {
    if (str_contains($sig,'buy') || $sig==='opportunity') return '↑';
    if (str_contains($sig,'sell')) return '↓';
    if ($sig==='rebalance') return '⇄';
    return '—';
}

function tradeRow($t, $showClose = false) {
    $pnlClass  = $t['pnl_usd'] > 0 ? 'pnl-pos' : ($t['pnl_usd'] < 0 ? 'pnl-neg' : 'pnl-zero');
    $actColor  = $t['action'] === 'buy' ? 'var(--gn)' : 'var(--rd)';
    $strat     = $t['strategy'] ?? 'unknown';
    $stratColor = strategyColor($strat);
    $openTs    = strtotime($t['timestamp']);
    $closeTs   = $t['closed_at'] ? strtotime($t['closed_at']) : time();
    $dur       = $closeTs - $openTs;
    $durLabel  = $dur < 3600 ? round($dur/60).'m' : ($dur < 86400 ? round($dur/3600).'h' : round($dur/86400).'d');
    ?>
    <tr>
      <td>
        <span style="font-size:.68rem;padding:2px 7px;border-radius:4px;background:<?= $stratColor ?>22;color:<?= $stratColor ?>;font-weight:600;letter-spacing:.04em"><?= strtoupper(h($strat)) ?></span>
      </td>
      <td style="font-weight:700;color:var(--ac)"><?= strtoupper(h($t['coin'])) ?></td>
      <td style="color:<?= $actColor ?>;font-weight:600;font-size:.78rem"><?= strtoupper(h($t['action'])) ?></td>
      <td class="num">$<?= number_format((float)$t['entry_price'], 2) ?></td>
      <td class="num"><?= $t['status'] === 'closed' ? '<span style="color:var(--t3);font-size:.72rem">EXIT&nbsp;</span>' : '' ?>$<?= number_format((float)$t['current_price'], 2) ?></td>
      <td class="num"><?= number_format((float)$t['amount_usd'], 0) ?></td>
      <td class="num <?= $pnlClass ?>"><?= $t['pnl_usd'] >= 0 ? '+' : '' ?>$<?= number_format(abs($t['pnl_usd']), 2) ?></td>
      <td class="num <?= $pnlClass ?>" style="font-size:.78rem"><?= $t['pnl_pct'] >= 0 ? '+' : '' ?><?= $t['pnl_pct'] ?>%</td>
      <td class="muted" style="font-size:.72rem"><?= $durLabel ?></td>
      <?php if ($showClose): ?>
      <td>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="close_trade">
          <input type="hidden" name="trade_id" value="<?= (int)$t['id'] ?>">
          <input type="hidden" name="coin" value="<?= h($t['coin']) ?>">
          <button type="submit" style="font-size:.72rem;padding:3px 10px;background:var(--s3);border:1px solid var(--b1);border-radius:5px;color:var(--t2);cursor:pointer;font-family:inherit">Close</button>
        </form>
      </td>
      <?php else: ?>
      <td class="muted" style="font-size:.72rem"><?= $t['closed_at'] ? date('d M H:i', strtotime($t['closed_at'])) : '—' ?></td>
      <?php endif ?>
    </tr>
    <?php
}
