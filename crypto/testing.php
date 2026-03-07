<?php
// ---------------- CONFIG ----------------
$logFile = '/var/www/html/crypto/log.txt';
$dbFile  = '/var/www/data/crypto.db';
$table   = 'arbitrage_results';

// Determine view (log or db)
$view = $_GET['view'] ?? 'log';

// Helper for safe output
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Crypto Arbitrage</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: monospace;
            background: #f4f4f4;
            padding: 20px;
        }
        h1 {
            margin-bottom: 10px;
        }
        .buttons {
            margin-bottom: 20px;
        }
        .buttons a {
            display: inline-block;
            padding: 10px 15px;
            margin-right: 10px;
            text-decoration: none;
            background: #333;
            color: #fff;
            border-radius: 4px;
        }
        .buttons a.active {
            background: #007acc;
        }
        .log, .db {
            background: #fff;
            border: 1px solid #ccc;
            padding: 15px;
            white-space: pre-wrap;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #eee;
        }
    </style>
</head>
<body>

<h1>Crypto Arbitrage Dashboard</h1>

<div class="buttons">
    <a href="?view=log" class="<?= $view === 'log' ? 'active' : '' ?>">Latest Log</a>
    <a href="?view=db"  class="<?= $view === 'db'  ? 'active' : '' ?>">All Results (DB)</a>
</div>

<?php
// ---------------- LOG VIEW ----------------
if ($view === 'log') {

    if (!file_exists($logFile)) {
        echo "<div class='log'><strong>Log file not found.</strong></div>";
    } else {
        $contents = file_get_contents($logFile);
        if ($contents === false) {
            echo "<div class='log'><strong>Unable to read log file.</strong></div>";
        } else {
            echo "<div class='log'>" . h($contents) . "</div>";
        }
    }

// ---------------- DB VIEW ----------------
} elseif ($view === 'db') {

    if (!file_exists($dbFile)) {
        echo "<div class='db'><strong>Database file not found.</strong></div>";
    } else {
        try {
            $pdo = new PDO("sqlite:$dbFile");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->query("SELECT * FROM $table ORDER BY timestamp ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                echo "<div class='db'><strong>No records found.</strong></div>";
            } else {
                echo "<div class='db'><table>";
                echo "<tr>";
                foreach (array_keys($rows[0]) as $col) {
                    echo "<th>" . h($col) . "</th>";
                }
                echo "</tr>";

                foreach ($rows as $row) {
                    echo "<tr>";
                    foreach ($row as $value) {
                        echo "<td>" . h($value) . "</td>";
                    }
                    echo "</tr>";
                }

                echo "</table></div>";
            }

        } catch (PDOException $e) {
            echo "<div class='db'><strong>DB Error:</strong> " . h($e->getMessage()) . "</div>";
        }
    }

}
?>

</body>
</html>
