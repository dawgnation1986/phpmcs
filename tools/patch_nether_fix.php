<?php
declare(strict_types=1);

/**
 * 一次性补丁：修 NetherGenerator 的装饰与岩浆海。
 *
 * 实测第一版的问题：
 *   - 岩浆 0 格：地板普遍在 y=35 以上，空腔循环从 floor 起步，永远进不了 y<=30
 *   - 萤石 0 格：fbm 值域只有 ±0.5 左右，阈值 0.40 基本打不中
 *   - 灵魂沙 11 格：频率太低，一个区块内几乎是常数，整块要么全是要么全不是
 *   - 洞穴是「一层大空腔」，缺柱子和起伏
 */

$path = __DIR__ . '/../src/World/NetherGenerator.php';
$text = file_get_contents($path);
if ($text === false) {
    fwrite(STDERR, "读不到 $path\n");
    exit(1);
}
$EOL = str_contains($text, "\r\n") ? "\r\n" : "\n";
$text = str_replace("\r\n", "\n", $text);

$edits = [];

// 1) 地板高度范围拉宽，让低洼处真的沉到岩浆面以下
$edits[] = [
    "        \$floor = 33.0 + \$f * 15.0 + \$fd * 3.5;",
    "        \$floor = 34.0 + \$f * 21.0 + \$fd * 4.0;",
];

// 2) 岩板别那么容易整段消失
$edits[] = [
    "        if (\$p1 < -0.42) {",
    "        if (\$p1 < -0.55) {",
];
$edits[] = [
    "        if (\$p2 < -0.40) {",
    "        if (\$p2 < -0.52) {",
];

// 3) 加柱子：从地板直通天花板的地狱岩柱，破掉「一层大空腔」的平铺感
$edits[] = [
    <<<'OLD'
                // ---- 中间空腔：只有岩板才填 ----
                for ($y = $floor; $y <= $ceil; $y++) {
                    $solid = ($s1t > 0 && $y >= $s1 && $y < $s1 + $s1t)
                        || ($s2t > 0 && $y >= $s2 && $y < $s2 + $s2t);
OLD,
    <<<'NEW'
                // 柱子：低频噪声过阈值的地方整列实心，洞穴里就有了支撑感
                $pillar = $this->fbm($wx * 0.052, $wz * 0.052, 2, 239) > 0.30;

                // ---- 中间空腔：只有岩板/柱子才填 ----
                for ($y = $floor; $y <= $ceil; $y++) {
                    $solid = $pillar
                        || ($s1t > 0 && $y >= $s1 && $y < $s1 + $s1t)
                        || ($s2t > 0 && $y >= $s2 && $y < $s2 + $s2t);
NEW,
];

// 4) 灵魂沙：阈值下调 + 叠一层中频，避免整块区块一个值
$edits[] = [
    <<<'OLD'
                    $patch = $this->fbm($wx * 0.045, $wz * 0.045, 2, 151);
                    if ($patch > 0.18) {
OLD,
    <<<'NEW'
                    $patch = $this->fbm($wx * 0.045, $wz * 0.045, 2, 151)
                        + 0.30 * $this->perlin2($wx * 0.16, $wz * 0.16, 157);
                    if ($patch > 0.14) {
NEW,
];

// 5) 萤石：阈值按 fbm 的真实值域定，别再用 0.40
$edits[] = [
    <<<'OLD'
                $glow = $this->fbm($wx * 0.09, $wz * 0.09, 2, 191);
                if ($glow > 0.40 && $get($x, $ceil, $z) === 0) {
OLD,
    <<<'NEW'
                $glow = $this->fbm($wx * 0.085, $wz * 0.085, 2, 191)
                    + 0.25 * $this->perlin2($wx * 0.21, $wz * 0.21, 197);
                if ($glow > 0.16 && $get($x, $ceil, $z) === 0) {
NEW,
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
echo "NetherGenerator.php 已更新，" . count($edits) . " 处\n";
