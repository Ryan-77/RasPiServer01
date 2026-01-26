<?php
/**
 * crypto.php
 *
 * Displays the latest output of your crypto arbitrage script.
 * Safe, readable, and auto-formats line breaks.
 */

// Absolute path to your log file
$logFile = '/var/www/html/crypto/log.txt';

// Check if the file exists
if (!file_exists($logFile)) {
    http_response_code(404);
    echo "<h2>Log file not found.</h2>";
    exit;
}

// Try to read the file contents
$contents = @file_get_contents($logFile);
if ($contents === false) {
    http_response_code(500);
    echo "<h2>Unable to read log file.</h2>";
    exit;
}

// Optional: Convert special characters to HTML entities
$contents = htmlspecialchars($contents, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Display contents with line breaks
echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Crypto Arbitrage Log</title>
    <style>
        body { font-family: monospace; white-space: pre-wrap; padding: 20px; background: #f4f4f4; }
        h1 { color: #333; }
        .log { background: #fff; border: 1px solid #ccc; padding: 15px; }
    </style>
</head>
<body>
    <h1>Latest Crypto Arbitrage Output</h1>
    <div class='log'>{$contents}</div>
</body>
</html>";
?><?php
// Path to your log file
$log_file = '/home/ryanbayles/Desktop/Crypto/log.txt';

// Check if the file exists
if (!file_exists($log_file)) {
    echo "<h2>No log file found!</h2>";
    exit;
}

// Read the file contents
$log_contents = file_get_contents($log_file);

// Escape HTML to prevent breaking the page
$log_contents = htmlspecialchars($log_contents);

// Optional: break lines
$log_contents = nl2br($log_contents);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crypto Arbitrage Log</title>
    <style>
        body {
            font-family: monospace;
            background-color: #f0f0f0;
            padding: 20px;
        }
        pre {
            background-color: #222;
            color: #0f0;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <h1>Crypto Arbitrage Log</h1>
    <pre><?php echo $log_contents; ?></pre>
</body>
</html>
