<?php
declare(strict_types=1);

/**
 * 一次性补丁：密度场恢复原版 /512，并加一个可调的空气偏置，方便扫描。
 */

$path = __DIR__ . '/../src/World/NetherGenerator.php';
$text = file_get_contents($path);
$EOL = str_contains($text, "\r\n") ? "\r\n" : "\n";
$text = str_replace("\r\n", "\n", $text);

$edits = [];

// 1) 两个归一化系数回到原版 512
$edits[] = [
    "                    // 我们这套 Octaves 叠出来是 ±3.9 万，原版的 /512 是给 ±512 的量级准备的。\n                    // 不改全局（主世界在用），这里单独按实测幅度归一化到 ±1。\n                    \$base = \$n2[\$idx] / 39405.0;",
    "                    \$base = \$n2[\$idx] / 512.0;",
];
$edits[] = [
    "                    \$upper = \$n3[\$idx] / 39405.0;",
    "                    \$upper = \$n3[\$idx] / 512.0;",
];

// 2) 加空气偏置
$edits[] = [
    "                    \$v -= \$weight[\$k];",
    "                    \$v -= \$weight[\$k];\n                    // 调「有多空」的旋钮：负值让密度整体变小，空腔更多。\n                    // 原版没有这一项，纯粹是为了把手上的噪声幅度校准到原版的产率。\n                    \$v += \$this->airShift;",
];

// 3) 属性
$edits[] = [
    "    /** MapGenCavesHell 的移植 */\n    private NetherCaveGenerator \$caves;",
    "    /** MapGenCavesHell 的移植 */\n    private NetherCaveGenerator \$caves;\n    /** 密度整体偏移（调空腔比例的旋钮，0 就是原版） */\n    public float \$airShift = 0.0;",
];

foreach ($edits as $i => [$old, $new]) {
    $n = substr_count($text, $old);
    if ($n !== 1) {
        fwrite(STDERR, "第 " . ($i + 1) . " 处命中 $n 次\n");
        exit(1);
    }
    $text = str_replace($old, $new, $text);
}

$text = str_replace("\n", $EOL, $text);
file_put_contents($path, $text);
echo "已恢复 /512 并加 airShift\n";
