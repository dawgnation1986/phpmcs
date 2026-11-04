<?php
declare(strict_types=1);

/**
 * 一次性补丁：
 *  1. 修插值里 x/z 细分循环用反的 bug（$bx 拿了 z 循环变量）
 *  2. 水平采样从 4 格粒度提到 2 格粒度（5×17×5 → 9×17×9）
 *
 * 原版 func_180515_a 里 var41 是 x 细分、var48 是 z 细分，
 * 之前写成 $bx = $p + ... 而 $p 是 z 那层，整块地形在水平面上错位。
 */

$path = __DIR__ . '/../src/World/NetherGenerator.php';
$text = file_get_contents($path);
$EOL = str_contains($text, "\r\n") ? "\r\n" : "\n";
$text = str_replace("\r\n", "\n", $text);

$edits = [];

// ---- 1) initializeNoiseField：水平 9 个采样点 ----
$edits[] = [
    "        \$x = \$cx * 4;\n        \$z = \$cz * 4;\n        \$w = 5;\n        \$h = 17;\n        \$d = 5;",
    "        // 水平 9 个采样点 = 每 2 格一个，比原版的 4 格更细腻，\n        // 否则挖出来的通道边缘全是 4 格台阶，看着像碎块。\n        \$x = \$cx * 8;\n        \$z = \$cz * 8;\n        \$w = 9;\n        \$h = 17;\n        \$d = 9;",
];

// ---- 2) generateTerrain：8 段 × 2 格，并且修 x/z 对调 ----
$edits[] = [
    "        \$xz = 5;   // 水平采样点数\n        \$ys = 17;  // 垂直采样点数\n\n        for (\$i = 0; \$i < 4; \$i++) {          // x 方向 4 段\n            for (\$j = 0; \$j < 4; \$j++) {      // z 方向 4 段",
    "        \$xz = 9;   // 水平采样点数（每 2 格一个）\n        \$ys = 17;  // 垂直采样点数\n\n        for (\$i = 0; \$i < 8; \$i++) {          // x 方向 8 段（每段 2 格）\n            for (\$j = 0; \$j < 8; \$j++) {      // z 方向 8 段",
];

$edits[] = [
    "                        for (\$n = 0; \$n < 4; \$n++) {\n                            \$d44 = \$d33;\n                            \$d46 = (\$d35 - \$d33) * 0.25;\n\n                            for (\$p = 0; \$p < 4; \$p++) {",
    "                        for (\$n = 0; \$n < 2; \$n++) {        // x 细分 2 格\n                            \$d44 = \$d33;\n                            \$d46 = (\$d35 - \$d33) * 0.5;\n\n                            for (\$p = 0; \$p < 2; \$p++) {   // z 细分 2 格",
];

$edits[] = [
    "                                \$bx = \$p + \$i * 4;\n                                \$by = \$m + \$k * 8;\n                                \$bz = \$n + \$j * 4;",
    "                                // 【注意】\$n 是 x 那层循环、\$p 是 z 那层。\n                                // 之前写反了，整块地形在水平面上错位。\n                                \$bx = \$n + \$i * 2;\n                                \$by = \$m + \$k * 8;\n                                \$bz = \$p + \$j * 2;",
];

$edits[] = [
    "                        \$d37 = (\$d18 - \$d14) * 0.25;\n                        \$d39 = (\$d20 - \$d16) * 0.25;",
    "                        \$d37 = (\$d18 - \$d14) * 0.5;\n                        \$d39 = (\$d20 - \$d16) * 0.5;",
];

foreach ($edits as $i => [$old, $new]) {
    $n = substr_count($text, $old);
    if ($n !== 1) {
        fwrite(STDERR, "第 " . ($i + 1) . " 处命中 $n 次，中止\n");
        exit(1);
    }
    $text = str_replace($old, $new, $text);
}

$text = str_replace("\n", $EOL, $text);
file_put_contents($path, $text);
echo "插值修复 + 2 格粒度，共 " . count($edits) . " 处\n";
