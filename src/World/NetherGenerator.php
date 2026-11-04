<?php
declare(strict_types=1);

namespace PhpMc\World;

use PhpMc\Nbt\Tag;

/**
 * 地狱地形生成 —— 移植原版 1.8.9 的 ChunkProviderHell。
 *
 * 之前那版是「地板 + 天花板 + 中间掏空」的 2D 噪声拼出来的，
 * 结果就是一个巨大的单一空腔，天花板一整片平的，完全不像下界。
 * 这版照原版重写：
 *
 *   1. initializeNoiseField  → 5×17×5 的 3D 密度网格（三个八度噪声混合）
 *   2. func_180515_a         → 三线性插值成 16×16×128，密度 > 0 是地狱岩，
 *                               y < 32 的地方是岩浆海
 *   3. func_180516_b         → 上下各随机几层基岩、y≈64 附近铺灵魂沙/沙砾
 *   4. populate              → 石英矿脉、天花板/地面上的萤石簇
 *
 * 噪声器的构造顺序必须和原版一致 —— 它们吃同一个 JavaRandom，
 * 顺序错了同一个 seed 出来的地形完全不同。
 */
final class NetherGenerator
{
    private const BEDROCK = 7;
    private const LAVA = 11;
    private const NETHERRACK = 87;
    private const NETHER_BRICK = 112;
    private const SOUL_SAND = 88;
    private const GLOWSTONE = 89;
    private const QUARTZ_ORE = 153;
    private const FIRE = 51;

    /** 地狱总高度（1.8 是 128） */
    public const HEIGHT = 128;
    /** 岩浆海液面：y < 32 的空气都灌成岩浆 */
    private const LAVA_LEVEL = 32;

    /**
     * 两路 3D 噪声。
     *
     * 原版 ChunkProviderHell 用 NoiseGeneratorOctaves(16) 叠三层，但我们这套
     * Octaves 的振幅是 1,2,4,…（不是 fBm 的 1,0.5,0.25），16 层叠出来能到
     * ±39000，`/512` 之后是 ±77 —— 比垂直权重 ±2 大两个数量级，结果整个区块
     * 被一个超低频常数拍平，就是「一整片实心」的来源。
     *
     * 这里改成自己控制的 fBm：一层粗的定洞穴走向，一层细的补边角，
     * 量级归一化到 ±1，和垂直偏置同量级，才有「海绵状」的下界质感。
     */
    /** 原版三层地形噪声：16 / 16 / 8 八度 */
    private NoiseGeneratorOctaves $netherNoise1;
    private NoiseGeneratorOctaves $netherNoise2;
    private NoiseGeneratorOctaves $netherNoise3;
    /** 灵魂沙/沙砾/地表厚度：三个独立的 2D 噪声 */
    private NoiseGeneratorImproved $soulGen;
    private NoiseGeneratorImproved $gravelGen;
    private NoiseGeneratorImproved $exclGen;

    /** hellRNG：装饰用的随机源，每个区块按坐标重设种子 */
    private JavaRandom $rand;
    /** MapGenCavesHell 的移植 */
    private NetherCaveGenerator $caves;
    /** 密度整体偏移（调空腔比例的旋钮，0 就是原版） */
    public float $airShift = 50.0;

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

    public function generate(int $cx, int $cz): Tag
    {
        // 原版 provideChunk 第一件事就是按坐标重设 hellRNG，
        // 漏了这步后面所有 nextDouble/nextInt 全错位
        $this->rand->setSeed($cx * 341873128712 + $cz * 132897987541);

        $blocks = $this->generateTerrain($cx, $cz);
        $this->decorate($cx, $cz, $blocks);
        // 原版顺序：密度地形 → 地表替换 → 洞穴雕刻 → 装饰。
        // 洞穴在装饰之前，所以隧道会切穿灵魂沙/沙砾的表层。
        $this->caves->generate($cx, $cz, $blocks);
        $this->populate($cx, $cz, $blocks);
        // 要塞最后压上去，保证走廊不被地形吃掉
        $this->buildFortress($cx, $cz, $blocks);

        return $this->pack($cx, $cz, $blocks);
    }

    // ---------------------------------------------------------------- 密度场

    /**
     * 算出 5×17×5 的密度网格。索引 [x][z][y]，和 NoiseGeneratorImproved 输出一致。
     *
     * @return float[] 5*5*17
     */
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
                    // 调「有多空」的旋钮：负值让密度整体变小，空腔更多。
                    // 原版没有这一项，纯粹是为了把手上的噪声幅度校准到原版的产率。

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
    /**
     * 原版 func_180515_a：把 5×17×5 插值成 16×16×128 的方块。
     *
     * @return array<int, array<int, int>> [y][z*16+x] = 方块 id
     */
    private function generateTerrain(int $cx, int $cz): array
    {
        $noise = $this->initializeNoiseField($cx, $cz);

        $blocks = [];
        for ($y = 0; $y < self::HEIGHT; $y++) {
            $blocks[$y] = array_fill(0, 256, 0);
        }

        $xz = 5;   // 原版：5 个采样点 × 4 格 = 16 格
        $ys = 17;  // 垂直 17 层 × 8 格 = 128

        for ($i = 0; $i < 4; $i++) {          // x 方向 4 段
            for ($j = 0; $j < 4; $j++) {      // z 方向 4 段
                for ($k = 0; $k < 16; $k++) { // y 方向 16 段（每段 8 格）
                    $step = 0.125;
                    $d14 = $noise[(($i + 0) * $xz + $j + 0) * $ys + $k + 0];
                    $d16 = $noise[(($i + 0) * $xz + $j + 1) * $ys + $k + 0];
                    $d18 = $noise[(($i + 1) * $xz + $j + 0) * $ys + $k + 0];
                    $d20 = $noise[(($i + 1) * $xz + $j + 1) * $ys + $k + 0];
                    $d22 = ($noise[(($i + 0) * $xz + $j + 0) * $ys + $k + 1] - $d14) * $step;
                    $d24 = ($noise[(($i + 0) * $xz + $j + 1) * $ys + $k + 1] - $d16) * $step;
                    $d26 = ($noise[(($i + 1) * $xz + $j + 0) * $ys + $k + 1] - $d18) * $step;
                    $d28 = ($noise[(($i + 1) * $xz + $j + 1) * $ys + $k + 1] - $d20) * $step;

                    for ($m = 0; $m < 8; $m++) {
                        $d33 = $d14;
                        $d35 = $d16;
                        $d37 = ($d18 - $d14) * 0.25;
                        $d39 = ($d20 - $d16) * 0.25;

                        for ($n = 0; $n < 4; $n++) {        // x 细分 4 格
                            $d44 = $d33;
                            $d46 = ($d35 - $d33) * 0.25;

                            for ($p = 0; $p < 4; $p++) {   // z 细分 4 格
                                $id = 0;
                                $byTmp = $m + $k * 8;
                                if ($byTmp < self::LAVA_LEVEL) {
                                    $id = self::LAVA;
                                }
                                // 中间那段抬高实心阈值 —— 想多空就调高 airShift。
                                // 底部（y<40）和顶部（y>100）保持原版阈值 0。
                                // 分三段：底部开小口让岩浆湖露出来，中间用 airShift 调空腔，
                                // 顶部保持原版阈值接天花板。之前底部用 0，整片地面是平的。
                                if ($byTmp < 32) {
                                    $thr = 30.0;
                                } elseif ($byTmp <= 100) {
                                    $thr = $this->airShift;
                                } else {
                                    $thr = 0.0;
                                }
                                if ($d44 > $thr) {
                                    $id = self::NETHERRACK;
                                }
                                // 【注意】$n 是 x 那层循环、$p 是 z 那层。
                                // 之前写反了，整块地形在水平面上错位。
                                $bx = $n + $i * 4;
                                $by = $m + $k * 8;
                                $bz = $p + $j * 4;
                                $blocks[$by][($bz << 4) | $bx] = $id;
                                $d44 += $d46;
                            }

                            $d33 += $d37;
                            $d35 += $d39;
                        }

                        $d14 += $d22;
                        $d16 += $d24;
                        $d18 += $d26;
                        $d20 += $d28;
                    }
                }
            }
        }

        return $blocks;
    }

    // ---------------------------------------------------------------- 装饰

    /**
     * 原版 func_180516_b：封基岩、在 y≈64 铺灵魂沙/沙砾、低洼处灌岩浆。
     *
     * 原版这里有个已知的参数错位：slowsandNoise 的调用把 z 传给了 yOffset、
     * zOffset 写死 0，导致灵魂沙带沿 z 方向无限重复。这里按「本意」修正成
     * (x, 0, z)，看起来才像下界而不是一条条平行的色带。
     *
     * @param array<int, array<int, int>> $blocks
     */
    private function decorate(int $cx, int $cz, array &$blocks): void
    {
        $seaLevel = 64;
        $scale = 0.03125;
        $x0 = $cx * 16;
        $z0 = $cz * 16;

        // 三个 2D 场，索引顺序是 [x][z]（NoiseGeneratorImproved 的输出顺序）
        $soul = $this->octave($this->soulGen, $x0, 0, $z0, 16, 1, 16, 0.55, 0.0, 0.55, 3);
        $gravel = $this->octave($this->gravelGen, $x0, 0, $z0, 16, 1, 16, 0.42, 0.0, 0.42, 3);
        $excl = $this->octave($this->exclGen, $x0, 0, $z0, 16, 1, 16, 1.10, 0.0, 1.10, 2);

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
    }

    /**
     * 石英矿脉 + 萤石簇。原版走 WorldGenMinable / WorldGenGlowStone，
     * 这里做等价效果：矿脉沿随机方向连 8~14 格，萤石从附着点向外长一簇。
     *
     * @param array<int, array<int, int>> $blocks
     */
    private function populate(int $cx, int $cz, array &$blocks): void
    {
        // 石英：每区块两条矿脉，替换地狱岩
        for ($vein = 0; $vein < 2; $vein++) {
            $vx = $this->rand->nextIntBound(16);
            $vy = 8 + $this->rand->nextIntBound(110);
            $vz = $this->rand->nextIntBound(16);
            $len = 8 + $this->rand->nextIntBound(7);
            for ($n = 0; $n < $len; $n++) {
                if ($vx >= 0 && $vx < 16 && $vz >= 0 && $vz < 16 && $vy >= 0 && $vy < self::HEIGHT) {
                    if ($blocks[$vy][($vz << 4) | $vx] === self::NETHERRACK) {
                        $blocks[$vy][($vz << 4) | $vx] = self::QUARTZ_ORE;
                    }
                }
                $vx += $this->rand->nextIntBound(3) - 1;
                $vy += $this->rand->nextIntBound(3) - 1;
                $vz += $this->rand->nextIntBound(3) - 1;
            }
        }

        // 萤石：天花板上挂 8 簇，地面上冒 4 簇
        for ($c = 0; $c < 12; $c++) {
            $gx = $this->rand->nextIntBound(16);
            $gz = $this->rand->nextIntBound(16);
            $fromCeiling = $c < 8;

            // 找落点：天花板从 120 往下找第一个实心，地面从 33 往上找
            $gy = -1;
            if ($fromCeiling) {
                // 天花板：找「实心方块下面正好是空气」的那一格，才是洞穴顶
                for ($y = 118; $y > 34; $y--) {
                    if ($blocks[$y][($gz << 4) | $gx] !== 0
                        && $blocks[$y - 1][($gz << 4) | $gx] === 0) {
                        $gy = $y - 1;
                        break;
                    }
                }
            } else {
                for ($y = 33; $y < 110; $y++) {
                    if ($blocks[$y][($gz << 4) | $gx] === self::NETHERRACK
                        && $blocks[$y + 1][($gz << 4) | $gx] === 0) {
                        $gy = $y + 1;
                        break;
                    }
                }
            }
            if ($gy < 1 || $gy > 125) {
                continue;
            }

            // 从落点往外长 4~9 格萤石
            $size = 4 + $this->rand->nextIntBound(6);
            for ($n = 0; $n < $size; $n++) {
                $gx2 = $gx + $this->rand->nextIntBound(3) - 1;
                $gy2 = $gy + $this->rand->nextIntBound(3) - 1;
                $gz2 = $gz + $this->rand->nextIntBound(3) - 1;
                if ($gx2 < 0 || $gx2 > 15 || $gz2 < 0 || $gz2 > 15) {
                    continue;
                }
                if ($gy2 < 1 || $gy2 > 126) {
                    continue;
                }
                if ($blocks[$gy2][($gz2 << 4) | $gx2] === 0) {
                    $blocks[$gy2][($gz2 << 4) | $gx2] = self::GLOWSTONE;
                }
            }
        }
    }

    // ---------------------------------------------------------------- 要塞

    /**
     * 简易下界要塞：一条东西向主长廊 + 四条南北向支廊，全用地狱砖。
     *
     * 原版 MapGenNetherBridge 是一套结构拼图（十几个房间件互相连接），
     * 移植代价很大。这里做一个「能用」的版本：几何形状是全局确定的，
     * 每个区块自己判断落在哪一段，所以相邻区块能无缝拼上。
     *
     * 位置固定在传送落点附近，出传送门往左右走就能撞上。
     *
     * @param array<int, array<int, int>> $blocks
     */
    private function buildFortress(int $cx, int $cz, array &$blocks): void
    {
        // 要塞中心 = 传送落点（世界坐标 200000,200000）
        $originX = 12500 * 16;
        $originZ = 12500 * 16;
        $baseX = $cx * 16;
        $baseZ = $cz * 16;

        // 粗筛：离中心太远直接返回，省得每列都算
        if (abs($baseX + 8 - $originX) > 128 && abs($baseZ + 8 - $originZ) > 128) {
            return;
        }

        $floorY = 67;
        $ceilY = 73;

        for ($lx = 0; $lx < 16; $lx++) {
            for ($lz = 0; $lz < 16; $lz++) {
                $wx = $baseX + $lx;
                $wz = $baseZ + $lz;
                $dx = $wx - $originX;
                $dz = $wz - $originZ;

                // 先看是不是在主长廊上，再看四条支廊
                $lane = -1;
                $dist = 99;
                if (abs($dz) <= 3 && abs($dx) <= 100) {
                    $lane = 0;
                    $dist = abs($dz);
                } else {
                    for ($k = -2; $k <= 2; $k++) {
                        if ($k === 0) {
                            continue;
                        }
                        if (abs($dx - $k * 48) <= 3 && abs($dz) <= 70) {
                            $lane = $k;
                            $dist = abs($dx - $k * 48);
                            break;
                        }
                    }
                }
                if ($lane === -1) {
                    continue;
                }

                $col = ($lz << 4) | $lx;

                if ($dist <= 1) {
                    // 走廊：铺地板、清出 5 格高、盖顶
                    $blocks[$floorY][$col] = self::NETHER_BRICK;
                    for ($y = $floorY + 1; $y < $ceilY; $y++) {
                        $blocks[$y][$col] = 0;
                    }
                    $blocks[$ceilY][$col] = self::NETHER_BRICK;

                    // 中线上每隔 8 格挂一块萤石当照明
                    if ($dist === 0) {
                        $along = $lane === 0 ? $wx : $wz;
                        if ((($along % 8) + 8) % 8 === 0) {
                            $blocks[$ceilY - 1][$col] = self::GLOWSTONE;
                        }
                    }
                } else {
                    // 墙：2 格厚，中间挖几个窗洞
                    for ($y = $floorY; $y <= $ceilY; $y++) {
                        $blocks[$y][$col] = self::NETHER_BRICK;
                    }
                    if ($dist === 2 && $lane === 0 && (($wx % 13) + 13) % 13 === 0) {
                        $blocks[$floorY + 2][$col] = 0;
                        $blocks[$floorY + 3][$col] = 0;
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------- 打包
    /**
     * @param array<int, array<int, int>> $blocks
     */
    private function pack(int $cx, int $cz, array $blocks): Tag
    {
        $sections = [];
        for ($sy = 0; $sy < 8; $sy++) {
            $sections[$sy] = [
                'blocks' => str_repeat("\x00", 4096),
                'data' => str_repeat("\x00", 2048),
            ];
        }

        for ($y = 0; $y < self::HEIGHT; $y++) {
            $sy = $y >> 4;
            $row = $blocks[$y];
            $base = ($y & 15) << 8;
            for ($z = 0; $z < 16; $z++) {
                $line = '';
                for ($x = 0; $x < 16; $x++) {
                    $line .= chr($row[($z << 4) | $x] & 0xFF);
                }
                $sections[$sy]['blocks'] = substr_replace($sections[$sy]['blocks'], $line, $base | ($z << 4), 16);
            }
        }

        $heights = array_fill(0, 256, 0);
        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                $top = 0;
                for ($y = self::HEIGHT - 1; $y >= 0; $y--) {
                    if ($blocks[$y][($z << 4) | $x] !== 0) {
                        $top = $y + 1;
                        break;
                    }
                }
                $heights[$z * 16 + $x] = $top;
            }
        }

        $sectionTags = [];
        for ($sy = 0; $sy < 8; $sy++) {
            if (trim($sections[$sy]['blocks'], "\x00") === '') {
                continue;
            }
            $sectionTags[] = Tag::compound([
                'Y' => Tag::byte($sy),
                'Blocks' => Tag::byteArray($sections[$sy]['blocks']),
                'Data' => Tag::byteArray($sections[$sy]['data']),
                // 地狱没有天光。服务端不做逐格光照传播（3 万格 BFS 会把生成
                // 时间翻几倍），统一铺 6 级微光；萤石/岩浆客户端会自己扩散。
                'BlockLight' => Tag::byteArray(str_repeat("\x66", 2048)),
                'SkyLight' => Tag::byteArray(str_repeat("\x00", 2048)),
            ]);
        }

        return Tag::compound([
            'Level' => Tag::compound([
                'xPos' => Tag::int($cx),
                'zPos' => Tag::int($cz),
                'LastUpdate' => Tag::long(time()),
                'phpMcGenerated' => Tag::byte(1),
                'phpMcNether' => Tag::byte(1),
                'TerrainPopulated' => Tag::byte(1),
                'InhabitedTime' => Tag::long(0),
                'Biomes' => Tag::byteArray(str_repeat("\x08", 256)),
                'HeightMap' => Tag::intArray($heights),
                'Sections' => Tag::list(Tag::COMPOUND, $sectionTags),
                'Entities' => Tag::list(Tag::COMPOUND, []),
                'TileEntities' => Tag::list(Tag::COMPOUND, []),
            ]),
        ]);
    }
}
