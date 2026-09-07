<?php
// scratch/check_bind.php - Scan all PHP files for bind_param length mismatches

$files = [];
$dirsToScan = ['helpers', 'super-admin', 'api', 'database', 'admin', 'app'];
foreach ($dirsToScan as $dir) {
    $fullPath = __DIR__ . '/../' . $dir;
    if (is_dir($fullPath)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullPath));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
}
$rootFiles = glob(__DIR__ . '/../*.php');
foreach ($rootFiles as $rf) {
    if (is_file($rf)) {
        $files[] = $rf;
    }
}

$mismatches = [];

foreach ($files as $file) {
    $code = file_get_contents($file);
    $tokens = token_get_all($code);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING && $tokens[$i][1] === 'bind_param') {
            $line = $tokens[$i][2];
            // Find opening parenthesis
            $j = $i + 1;
            while ($j < $count && $tokens[$j] !== '(') $j++;
            if ($j >= $count) continue;

            // Get first param (string literal)
            $j++;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) continue;

            $typeStr = trim($tokens[$j][1], '"\'');

            // Parse arguments inside bind_param(...)
            $args = [];
            $currentArg = '';
            $depth = 1;
            for ($k = $j + 1; $k < $count; $k++) {
                $t = $tokens[$k];
                $tStr = is_array($t) ? $t[1] : $t;
                if ($tStr === '(') {
                    $depth++;
                    $currentArg .= $tStr;
                } elseif ($tStr === ')') {
                    $depth--;
                    if ($depth === 0) {
                        if (trim($currentArg) !== '') {
                            $args[] = trim($currentArg);
                        }
                        break;
                    } else {
                        $currentArg .= $tStr;
                    }
                } elseif ($tStr === ',' && $depth === 1) {
                    $args[] = trim($currentArg);
                    $currentArg = '';
                } else {
                    $currentArg .= $tStr;
                }
            }

            // $args[0] is the type string expression itself, remaining elements are bind variables
            $bindVarCount = max(0, count($args) - 1);
            if (strlen($typeStr) !== $bindVarCount) {
                $mismatches[] = [
                    'file' => $file,
                    'line' => $line,
                    'typeStr' => $typeStr,
                    'typeLen' => strlen($typeStr),
                    'varCount' => $bindVarCount,
                    'args' => $args
                ];
            }
        }
    }
}

echo "Found " . count($mismatches) . " bind_param mismatches:\n";
foreach ($mismatches as $m) {
    echo "  [MISMATCH] " . basename($m['file']) . " (Line " . $m['line'] . "): typeStr '" . $m['typeStr'] . "' (len " . $m['typeLen'] . ") vs " . $m['varCount'] . " bind vars\n";
    echo "             Path: " . $m['file'] . "\n";
}
