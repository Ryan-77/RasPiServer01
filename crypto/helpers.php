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

function positionMeta(string $action, string $strategy): array {
    if ($strategy === 'rebalance') {
        return [
            'label' => $action === 'buy' ? 'ADD'       : 'TRIM',
            'icon'  => $action === 'buy' ? '↑'         : '↓',
            'color' => $action === 'buy' ? 'var(--gn)' : 'var(--yw)',
        ];
    }
    return [
        'label' => $action === 'buy' ? 'LONG'      : 'SHORT',
        'icon'  => $action === 'buy' ? '↑'         : '↓',
        'color' => $action === 'buy' ? 'var(--gn)' : 'var(--rd)',
    ];
}

function parseAllocReason(string $reasonJson): string {
    $reasons = json_decode($reasonJson, true);
    if (!is_array($reasons) || empty($reasons)) return '—';
    $parts = [];
    foreach ($reasons as $r) {
        if (preg_match('/^mkt_cap_rank=(\d+)$/', $r, $m)) {
            $parts[] = '#' . $m[1] . ' by mkt cap';
        } elseif (preg_match('/^(\w+):([\w_]+)\(s=([\d.]+)\)$/', $r, $m)) {
            $strat   = $m[1];
            $action  = $m[2];
            $str     = (float)$m[3];
            $boost   = number_format($str * 5.0, 1);
            $sign    = in_array($action, ['buy', 'opportunity']) ? '+' : '-';
            $parts[] = "{$sign}{$boost}% {$strat} " . ucfirst($action) . ' (' . round($str * 100) . '%)';
        } else {
            $parts[] = $r;
        }
    }
    return implode(' · ', $parts);
}

function signalVerb(array $sig): string {
    $d = $sig['_d'] ?? (is_string($sig['details'] ?? '') ? (json_decode($sig['details'] ?? '{}', true) ?? []) : []);
    switch ($sig['strategy'] ?? '') {
        case 'momentum':
            $rsi  = isset($d['rsi'])    ? number_format((float)$d['rsi'], 1)    : '?';
            $lbl  = ucfirst($d['label'] ?? 'neutral');
            $hi   = $d['rsi_high']      ?? 65;
            $lo   = $d['rsi_low']       ?? 35;
            $roc  = isset($d['roc_10']) ? number_format((float)$d['roc_10'], 2) . '%' : '?';
            return "RSI {$rsi} — {$lbl} (thresholds {$lo}/{$hi}). 10h price change: {$roc}.";
        case 'pairs':
            $desc = $d['description']        ?? 'Pairs divergence detected';
            $z    = isset($d['zscore'])       ? number_format((float)$d['zscore'], 2)       : '?';
            $cur  = isset($d['current_ratio'])? number_format((float)$d['current_ratio'], 4): '?';
            $mean = isset($d['mean_ratio'])   ? number_format((float)$d['mean_ratio'], 4)   : '?';
            $days = $d['lookback_days']       ?? 30;
            return "{$desc}. Z-score: {$z}σ. Ratio {$cur} vs {$days}-day avg {$mean}.";
        case 'arbitrage':
            $cycle = isset($d['cycle']) && is_array($d['cycle'])
                     ? implode(' → ', array_map('strtoupper', $d['cycle'])) : '?';
            $gain  = isset($d['net_gain_pct']) ? number_format((float)$d['net_gain_pct'], 3) . '%' : '?';
            $fee   = isset($d['fee_rate'])      ? number_format((float)$d['fee_rate'] * 100, 2) . '%' : '?';
            $not   = (float)($d['trade_usd_est'] ?? 1000);
            $est   = isset($d['net_gain_pct'])  ? '$' . number_format($not * (float)$d['net_gain_pct'] / 100, 2) : '?';
            return "Cycle: {$cycle}. Net gain {$gain} after fees ({$fee} total). Est. {$est} on \$" . number_format($not, 0) . ".";
        case 'rebalance':
            $drift = isset($d['drift_pct'])  ? sprintf('%+.1f%%', (float)$d['drift_pct']) : '?';
            $tgt   = isset($d['target_pct']) ? number_format((float)$d['target_pct'], 1) . '%' : '?';
            $delta = isset($d['delta_usd'])  ? '$' . number_format(abs((float)$d['delta_usd']), 2) : '?';
            return "Drift {$drift} from {$tgt} target. Recommended trade: {$delta}.";
        default:
            return h($sig['signal'] ?? '');
    }
}

function driftAction(float $driftPct, float $totalValue): array {
    $amount = round(abs($driftPct / 100) * $totalValue, 2);
    if ($driftPct < -2.0)
        return ['label' => 'BUY',  'icon' => '↑', 'color' => 'var(--gn)', 'amount_usd' => $amount];
    if ($driftPct > 2.0)
        return ['label' => 'TRIM', 'icon' => '↓', 'color' => 'var(--yw)', 'amount_usd' => $amount];
    return     ['label' => 'HOLD', 'icon' => '=', 'color' => 'var(--t3)', 'amount_usd' => 0];
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
