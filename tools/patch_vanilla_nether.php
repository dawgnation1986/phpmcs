<?php
declare(strict_types=1);

/**
 * 一次性补丁：地形密度场换回原版 ChunkProviderHell 的写法。
 *
 * 用户原话：「给了你文档和真实存档你还会怀疑吗」
 * —— 不再自己发明 fBm，照文档第 3 节：7 个 NoiseGeneratorOctaves，
 * initializeNoiseField 用 684.412 / 2053.236 那组常量，5×17×5 采样网格，
 * 密度 > 0 是地狱岩。
 */

$path = __DIR__ . '/../src/World/NetherGenerator.php';
$text = file_get_contents($path);
$EOL = str_contains($text, "\r\n") ? "\r\n" : "\n";
$text = str_replace("\r\n", "\n", $text);

// ---- 1) 构造里换成原版的 5 个 Octaves ----
$oldCtor = <<<'OLD'
    public function __construct(int $seed)
    {
        $this->rand = new JavaRandom($seed);
        // 用两个不同的种子，避免两层噪声对齐成同一个形状
        $this->coarse = new NoiseGeneratorImproved(new JavaRandom($seed));
        $this->fine = new NoiseGeneratorImproved(new JavaRandom($seed ^ 0x4E455448));
        $this->soulGen = new NoiseGeneratorImproved(new JavaRandom($seed + 101));
        $this->gravelGen = new NoiseGeneratorImproved(new JavaRandom($seed + 202));
        $this->exclGen = new NoiseGeneratorImproved(new JavaRandom($seed + 303));
        $this->caves = new NetherCaveGenerator($seed);
    }
OLD;
$newCtor = <<<'NEW'
    public function __construct(int $seed)
    {
        $this->rand = new JavaRandom($seed);

        // 原版 ChunkProviderHell 的构造顺序，七个噪声器吃同一个 Random，
        // 顺序错了同一个种子出来的地形就完全不同。
        // （4 和 5 那两个是灵魂沙/地表厚度用的，这里拆成独立的 Improved）
        $nr = new JavaRandom($seed);
        $this->netherNoise1 = new NoiseGeneratorOctaves($nr, 16);
        $this->netherNoise2 = new NoiseGeneratorOctaves($nr, 16);
        $this->netherNoise3 = new NoiseGeneratorOctaves($nr, 8);

        $this->soulGen = new NoiseGeneratorImproved(new JavaRandom($seed + 101));
        $this->gravelGen = new NoiseGeneratorImproved(new JavaRandom($seed + 202));
        $this->exclGen = new NoiseGeneratorImproved(new JavaRandom($seed + 303));
        $this->caves = new NetherCaveGenerator($seed);
    }
NEW;
if (substr_count($text, $oldCtor) !== 1) {
    fwrite(STDERR, "构造锚点不唯一\n");
    exit(1);
}
$text = str_replace($oldCtor, $newCtor, $text);

// ---- 2) 字段 ----
$oldFields = "    private NoiseGeneratorImproved \$coarse;\n    private NoiseGeneratorImproved \$fine;";
$newFields = "    /** 原版三层地形噪声：16 / 16 / 8 八度 */\n    private NoiseGeneratorOctaves \$netherNoise1;\n    private NoiseGeneratorOctaves \$netherNoise2;\n    private NoiseGeneratorOctaves \$netherNoise3;";
if (substr_count($text, $oldFields) !== 1) {
    fwrite(STDERR, "字段锚点不唯一\n");
    exit(1);
}
$text = str_replace($oldFields, $newFields, $text);

// ---- 3) initializeNoiseField 整体替换 ----
$startMark = "    private function initializeNoiseField(int \$cx, int \$cz): array\n    {";
$endMark = "\n    /**\n     * 手写 fBm";
$s = strpos($text, $startMark);
$e = strpos($text, $endMark);
if ($s === false || $e === false || $e <= $s) {
    fwrite(STDERR, "initializeNoiseField 定位失败 s=$s e=$e\n");
    exit(1);
}

$newFn = <<<'PHP'
    private function initializeNoiseField(int $cx, int $cz): array
    {
        // 原版就是 4 格粒度：5 个采样点 × 4 格 = 16 格
        $x = $cx * 4;
        $z = $cz * 4;
        $w = 5;
        $h = 17;
        $d = 5;

        // 三个八度：8 层的当混合权重，两个 16 层的做上下限
        $n1 = $this->netherNoise3->generateNoiseOctaves(
            null, $x, 0, $z, $w, $h, $d,
            684.412 / 80.0, 2053.236 / 60.0, 684.412 / 80.0
        );
        $n2 = $this->netherNoise1->generateNoiseOctaves(null, $x, 0, $z, $w, $h, $d, 684.412, 2053.236, 684.412);
        $n3 = $this->netherNoise2->generateNoiseOctaves(null, $x, 0, $z, $w, $h, $d, 684.412, 2053.236, 684.412);

        // 垂直权重：中间高、两头被三次方压到很深，天然形成天花板和地板
        $weight = [];
        for ($i = 0; $i < $h; $i++) {
            $weight[$i] = cos($i * M_PI * 6.0 / $h) * 2.0;
            $dd = (float) $i;
            if ($i > intdiv($h, 2)) {
                $dd = (float) ($h - 1 - $i);
            }
            if ($dd < 4.0) {
                $dd = 4.0 - $dd;
                $weight[$i] -= $dd * $dd * $dd * 10.0;
            }
        }

        $out = [];
        $idx = 0;
        for ($i = 0; $i < $w; $i++) {
            for ($j = 0; $j < $d; $j++) {
                for ($k = 0; $k < $h; $k++) {
                    $base = $n2[$idx] / 512.0;
                    $upper = $n3[$idx] / 512.0;
                    // 原版 MathHelper.denormalizeClamp：超出 [0,1] 就夹住，别外推
                    $mix = ($n1[$idx] / 10.0 + 1.0) / 2.0;
                    if ($mix < 0.0) {
                        $v = $base;
                    } elseif ($mix > 1.0) {
                        $v = $upper;
                    } else {
                        $v = $base + ($upper - $base) * $mix;
                    }

                    $v -= $weight[$k];

                    // 最上面 3 层硬拉到 -10，别让天花板漏出岩浆
                    if ($k > $h - 4) {
                        $t = ($k - ($h - 4)) / 3.0;
                        $v = $v * (1.0 - $t) + -10.0 * $t;
                    }

                    $out[$idx] = $v;
                    $idx++;
                }
            }
        }

        return $out;
    }
PHP;

$text = substr($text, 0, $s) . $newFn . substr($text, $e);

// ---- 4) generateTerrain 回到 4 段 × 4 格 ----
$edits = [];
$edits[] = [
    "        \$xz = 9;   // 水平采样点数（每 2 格一个）\n        \$ys = 17;  // 垂直采样点数\n\n        for (\$i = 0; \$i < 8; \$i++) {          // x 方向 8 段（每段 2 格）\n            for (\$j = 0; \$j < 8; \$j++) {      // z 方向 8 段",
    "        \$xz = 5;   // 原版：5 个采样点 × 4 格 = 16 格\n        \$ys = 17;  // 垂直 17 层 × 8 格 = 128\n\n        for (\$i = 0; \$i < 4; \$i++) {          // x 方向 4 段\n            for (\$j = 0; \$j < 4; \$j++) {      // z 方向 4 段",
];
$edits[] = [
    "                        for (\$n = 0; \$n < 2; \$n++) {        // x 细分 2 格\n                            \$d44 = \$d33;\n                            \$d46 = (\$d35 - \$d33) * 0.5;\n\n                            for (\$p = 0; \$p < 2; \$p++) {   // z 细分 2 格",
    "                        for (\$n = 0; \$n < 4; \$n++) {        // x 细分 4 格\n                            \$d44 = \$d33;\n                            \$d46 = (\$d35 - \$d33) * 0.25;\n\n                            for (\$p = 0; \$p < 4; \$p++) {   // z 细分 4 格",
];
$edits[] = [
    "                                \$bx = \$n + \$i * 2;\n                                \$by = \$m + \$k * 8;\n                                \$bz = \$p + \$j * 2;",
    "                                \$bx = \$n + \$i * 4;\n                                \$by = \$m + \$k * 8;\n                                \$bz = \$p + \$j * 4;",
];
$edits[] = [
    "                        \$d37 = (\$d18 - \$d14) * 0.5;\n                        \$d39 = (\$d20 - \$d16) * 0.5;",
    "                        \$d37 = (\$d18 - \$d14) * 0.25;\n                        \$d39 = (\$d20 - \$d16) * 0.25;",
];

foreach ($edits as $i => [$old, $new]) {
    $n = substr_count($text, $old);
    if ($n !== 1) {
        fwrite(STDERR, "generateTerrain 第 " . ($i + 1) . " 处命中 $n 次\n");
        exit(1);
    }
    $text = str_replace($old, $new, $text);
}

$text = str_replace("\n", $EOL, $text);
file_put_contents($path, $text);
echo "已换回原版密度场\n";
