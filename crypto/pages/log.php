<?php // ══ LOG VIEW ═══════════════════════════════════════════════════════════════ ?>
<div class="panel">
  <div class="ph">
    <div class="ph-t">SYSTEM LOG — LAST <?= $LOG_LINES ?> LINES</div>
    <div class="ph-m">UPDATED: <?= date('H:i:s') ?></div>
  </div>
  <?php if ($logError): ?>
    <div class="err-box"><?= h($logError) ?></div>
  <?php elseif (!$logContent): ?>
    <div class="state"><div class="state-i"></div><div class="state-t">LOG IS EMPTY</div></div>
  <?php else: ?>
  <div class="log-body" id="log-body"><?php
    foreach (explode("\n", $logContent) as $line) {
      $cls = 'll-d';
      if (preg_match('/error|fail|exception|critical/i', $line))         $cls = 'll-err';
      elseif (preg_match('/warn/i', $line))                              $cls = 'll-warn';
      elseif (preg_match('/success|profit|opportunit|found/i', $line))   $cls = 'll-ok';
      elseif (preg_match('/\d{4}-\d{2}-\d{2}|\d{2}:\d{2}:\d{2}/', $line)) $cls = 'll-ts';
      echo '<span class="ll '.$cls.'">'.h($line).'</span>';
    }
  ?></div>
  <?php endif; ?>
</div>
