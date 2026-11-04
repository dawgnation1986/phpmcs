<?php
declare(strict_types=1);

/**
 * 一次性补丁：把 NetherGenerator 的密度场换成自控 fBm，并去掉对
 * NoiseGeneratorOctaves 的依赖（那玩意叠 16 层振幅到 ±3.9 万，地形被拍平）。
 */

$path = __DIR__ . '/../src/World/NetherGenerator.php';
$text = file_get_contents($path);
if ($text === false) {
    fwrite(STDERR, "读不到 $path\n");
    exit(1);
}
$EOL = str_contains($text, "\r\n") ? "\r\n" : "\n";
$text = str_replace("\r\n", "\n", $text);

// ---- 1) 加三个装饰用的噪声器 ----
$old = "    private NoiseGeneratorImproved \$coarse;\n    private NoiseGeneratorImproved \$fine;";
$new = "    private NoiseGeneratorImproved \$coarse;\n    private NoiseGeneratorImproved \$fine;\n    /** 灵魂沙/沙砾/地表厚度：三个独立的 2D 噪声 */\n    private NoiseGeneratorImproved \$soulGen;\n    private NoiseGeneratorImproved \$gravelGen;\n    private NoiseGeneratorImproved \$exclGen;";
if (substr_count($text, $old) !== 1) {
    fwrite(STDERR, "字段锚点不唯一\n");
    exit(1);
}
$text = str_replace($old, $new, $text);

$old = "        \$this->coarse = new NoiseGeneratorImproved(new JavaRandom(\$seed));\n        \$this->fine = new NoiseGeneratorImproved(new JavaRandom(\$seed ^ 0x4E455448));";
$new = "        \$this->coarse = new NoiseGeneratorImproved(new JavaRandom(\$seed));\n        \$this->fine = new NoiseGeneratorImproved(new JavaRandom(\$seed ^ 0x4E455448));\n        \$this->soulGen = new NoiseGeneratorImproved(new JavaRandom(\$seed + 101));\n        \$this->gravelGen = new NoiseGeneratorImproved(new JavaRandom(\$seed + 202));\n        \$this->exclGen = new NoiseGeneratorImproved(new JavaRandom(\$seed + 303));";
if (substr_count($text, $old) !== 1) {
    fwrite(STDERR, "构造锚点不唯一\n");
    exit(1);
}
$text = str_replace($old, $new, $text);

// ---- 2) 整体替换 initializeNoiseField ----
$startMark = "    /**\n     * 原版 initializeNoiseField：算出 5×17×5 的密度网格。";
$endMark = "\n    /**\n     * 原版 func_180515_a：把 5×17×5 插值成 16×16×128 的方块。";
$s = strpos($text, $startMark);
$e = strpos($text, $endMark);
if ($s === false || $e === false || $e <= $s) {
    fwrite(STDERR, "initializeNoiseField 定位失败\n");
    exit(1);
}

$newFn = <<<'PHP'
    /**
     * 算出 5×17×5 的密度网格。索引 [x][z][y]，和 NoiseGeneratorImproved 输出一致。
     *
     * @return float[] 5*5*17
     */
    private function initializeNoiseField(int $cx, int $cz): array
    {
        $x = $cx * 4;
        $z = $cz * 4;
        $w = 5;
        $h = 17;
        $d = 5;

        // 粗层定洞穴走向，细层补边角
        $big = $this->octave($this->coarse, $x, 0, $z, $w, $h, $d, 0.62, 1.05, 0.62, 4);
        $small = $this->octave($this->fine, $x, 0, $z, $w, $h, $d, 1.55, 2.20, 1.55, 3);

        $out = [];
        $idx = 0;
        for ($i = 0; $i < $w; $i++) {
            for ($j = 0; $j < $d; $j++) {
                for ($k = 0; $k < $h; $k++) {
                    $y = $k * 8;
                    $n = $big[$idx] + $small[$idx] * 0.42;

                    // 垂直偏置：
                    //   y<32    岩浆海，宁可留空，空出来的格子最后灌岩浆
                    //   32..40  过渡带，从岩浆湖爬上岸
                    //   40..100 主洞穴带，正偏置一点点，实心略多于空
                    //   >100    往上越来越实，最后接天花板
                    if ($y < 32) {
                        $bias = -0.34;
                    } elseif ($y < 40) {
                        $bias = -0.34 + ($y - 32) * 0.05;
                    } elseif ($y <= 100) {
                        $bias = 0.06;
                    } else {
                        $bias = 0.06 - ($y - 100) / 15.0;
                    }

                    $out[$idx] = $n + $bias;
                    $idx++;
                }
            }
        }

        return $out;
    }

    /**
     * 手写 fBm：频率每层翻倍、振幅每层减半，最后归一化到 ±1。
     *
     * 不能用 NoiseGeneratorOctaves —— 它的振幅是 1,2,4…（递增），
     * 叠 16 层能到 ±3.9 万，除以 512 之后还是 ±77，比任何结构项都大两个
     * 数量级，整个区块被一个超低频常数拍平，就是「一整片实心」的来源。
     *
     * @return float[] $w*$h*$d，索引 [x][z][y]
     */
    private function octave(
        NoiseGeneratorImproved $gen,
        int $x, int $y, int $z,
        int $w, int $h, int $d,
        float $sx, float $sy, float $sz,
        int $octaves
    ): array {
        $n = $w * $h * $d;
        $out = array_fill(0, $n, 0.0);
        $amp = 1.0;
        $freq = 1.0;
        $norm = 0.0;

        for ($o = 0; $o < $octaves; $o++) {
            $tmp = array_fill(0, $n, 0.0);
            $gen->populateNoiseArray(
                $tmp,
                (float) ($x * $freq),
                (float) ($y * $freq),
                (float) ($z * $freq),
                $w, $h, $d,
                $sx * $freq, $sy * $freq, $sz * $freq,
                1.0
            );
            for ($i = 0; $i < $n; $i++) {
                $out[$i] += $tmp[$i] * $amp;
            }
            $norm += $amp;
            $amp *= 0.5;
            $freq *= 2.0;
        }

        for ($i = 0; $i < $n; $i++) {
            $out[$i] /= $norm;
        }

        return $out;
    }
PHP;

$text = substr($text, 0, $s) . $newFn . substr($text, $e);

// ---- 3) decorate 里的噪声获取 ----
$old = <<<'OLD'
        $slowsand = $this->slowsandNoise->generateNoiseOctaves(
            null, $x0, 0, $z0, 16, 16, 1, $scale, $scale, 1.0
        );
        $gravel = $this->slowsandNoise->generateNoiseOctaves(
            null, $x0, 109, $z0, 16, 1, 16, $scale, 1.0, $scale
        );
        $excl = $this->exclusivityNoise->generateNoiseOctaves(
            null, $x0, 0, $z0, 16, 16, 1, $scale * 2.0, $scale * 2.0, $scale * 2.0
        );
OLD;
$new = <<<'NEW'
        // 三个 2D 场，索引顺序是 [x][z]（NoiseGeneratorImproved 的输出顺序）
        $soul = $this->octave($this->soulGen, $x0, 0, $z0, 16, 1, 16, 0.55, 0.0, 0.55, 3);
        $gravel = $this->octave($this->gravelGen, $x0, 0, $z0, 16, 1, 16, 0.42, 0.0, 0.42, 3);
        $excl = $this->octave($this->exclGen, $x0, 0, $z0, 16, 1, 16, 1.10, 0.0, 1.10, 2);
NEW;
if (substr_count($text, str_replace("\n", "\n", $old)) !== 1) {
    fwrite(STDERR, "decorate 噪声锚点不唯一\n");
    exit(1);
}
$text = str_replace($old, $new, $text);

// ---- 4) decorate 里的索引 ----
$old = "                \$idx2d = \$bx + \$bz * 16;\n                \$hasSoul = \$slowsand[\$idx2d] + \$this->rand->nextDouble() * 0.2 > 0.0;";
$new = "                \$idx2d = \$bx * 16 + \$bz;\n                \$hasSoul = \$soul[\$idx2d] + \$this->rand->nextDouble() * 0.2 > 0.0;";
if (substr_count($text, $old) !== 1) {
    fwrite(STDERR, "idx2d 锚点不唯一\n");
    exit(1);
}
$text = str_replace($old, $new, $text);

$text = str_replace("\n", $EOL, $text);
file_put_contents($path, $text);
echo "NetherGenerator 密度场已换成自控 fBm\n";
