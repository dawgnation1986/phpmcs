<?php
declare(strict_types=1);

/**
 * 一次性补丁：重写 decorate 的主循环。
 *
 * 原来逐层扫描 128 层 × 256 列，每层两次 nextIntBound，实测 9~15ms，
 * 占了整个区块生成的一半以上 —— 但其中几十层是空腔，除了浪费随机数
 * 什么都不做。这里改成：
 *   1. 顶 7 层 / 底 5 层单独封基岩
 *   2. 中间的装饰只在「岩层顶面」（上方是空气）处理，往下填 $thick 层
 */

$path = __DIR__ . '/../src/World/NetherGenerator.php';
$text = file_get_contents($path);
$EOL = str_contains($text, "\r\n") ? "\r\n" : "\n";
$text = str_replace("\r\n", "\n", $text);

$startMark = "        for (\$bx = 0; \$bx < 16; \$bx++) {\n            for (\$bz = 0; \$bz < 16; \$bz++) {";
$endMark = "\n    }\n\n    /**\n     * 石英矿脉 + 萤石簇";

$s = strpos($text, $startMark);
$e = strpos($text, $endMark);
if ($s === false || $e === false || $e <= $s) {
    fwrite(STDERR, "定位失败 s=$s e=$e\n");
    exit(1);
}

$newBody = <<<'PHP'
        // ---- 1) 顶部 7 层 / 底部 5 层封基岩 ----
        // 原版是 if (y < 127 - rand(5) && y > rand(5))，Java 的 && 短路：
        // 第一个不成立时不调第二个 nextInt。这里照抄。
        for ($bx = 0; $bx < 16; $bx++) {
            for ($bz = 0; $bz < 16; $bz++) {
                $col = ($bz << 4) | $bx;

                for ($y = 127; $y >= 121; $y--) {
                    $a = $y < 127 - $this->rand->nextIntBound(5);
                    $inRange = false;
                    if ($a) {
                        $inRange = $y > $this->rand->nextIntBound(5);
                    }
                    if (!$inRange) {
                        $blocks[$y][$col] = self::BEDROCK;
                    }
                }

                for ($y = 4; $y >= 0; $y--) {
                    $a = $y < 127 - $this->rand->nextIntBound(5);
                    $inRange = false;
                    if ($a) {
                        $inRange = $y > $this->rand->nextIntBound(5);
                    }
                    if (!$inRange) {
                        $blocks[$y][$col] = self::BEDROCK;
                    }
                }
            }
        }

        // ---- 2) 表面装饰：只在岩层顶面动 ----
        for ($bx = 0; $bx < 16; $bx++) {
            for ($bz = 0; $bz < 16; $bz++) {
                $col = ($bz << 4) | $bx;
                $idx2d = $bx * 16 + $bz;
                $hasSoul = $soul[$idx2d] + $this->rand->nextDouble() * 0.2 > 0.0;
                $hasGravel = $gravel[$idx2d] + $this->rand->nextDouble() * 0.2 > 0.0;
                $thick = (int) ($excl[$idx2d] / 3.0 + 3.0 + $this->rand->nextDouble() * 0.25);

                for ($y = 120; $y >= 5; $y--) {
                    if ($blocks[$y][$col] !== self::NETHERRACK) {
                        continue;
                    }
                    // 上方不是空气 → 不是这一段的顶面，跳过
                    if ($blocks[$y + 1][$col] !== 0) {
                        continue;
                    }

                    $top = self::NETHERRACK;
                    $fill = self::NETHERRACK;

                    if ($y >= $seaLevel - 4 && $y <= $seaLevel + 1) {
                        if ($hasGravel) {
                            $top = 13;   // 沙砾
                        }
                        if ($hasSoul) {
                            $top = self::SOUL_SAND;
                            $fill = self::SOUL_SAND;
                        }
                    }

                    if ($y < $seaLevel) {
                        $blocks[$y][$col] = $top;
                    } else {
                        $blocks[$y][$col] = ($y >= $seaLevel - 1) ? $top : $fill;
                    }

                    // 往岩层里再压 $thick-1 层，做出「表层材料有厚度」的感觉
                    for ($k = 1; $k < $thick; $k++) {
                        $yy = $y - $k;
                        if ($yy < 5 || $blocks[$yy][$col] !== self::NETHERRACK) {
                            break;
                        }
                        $blocks[$yy][$col] = $fill;
                    }
                }
            }
        }
PHP;

$text = substr($text, 0, $s) . $newBody . substr($text, $e);
$text = str_replace("\n", $EOL, $text);
file_put_contents($path, $text);
echo "decorate 主循环已重写\n";
