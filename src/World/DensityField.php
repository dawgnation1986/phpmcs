<?php
declare(strict_types=1);

namespace PhpMc\World;

use PhpMc\World\Layer\BiomeHeights;

/**
 * 原版地形密度场（ChunkProviderGenerate.func_147423_a）的移植。
 *
 * 输出是 5×33×5 的密度网格，再插值成 16×16 的每列地表高度。
 *
 * 参数全部取原版 ChunkProviderSettings 的默认值。
 * 噪声器的构造顺序必须和原版一致 —— 它们吃同一个 Random，
 * 顺序错了同一个 seed 出来的地形就完全不同。
 */
final class DensityField
{
    private NoiseGeneratorOctaves $lowerNoise;
    private NoiseGeneratorOctaves $upperNoise;
    private NoiseGeneratorOctaves $mainNoise;
    private NoiseGeneratorOctaves $depthNoise;

    /** @var float[] 25 项抛物线权重 */
    private array $parabolic = [];

    // 原版 ChunkProviderSettings 默认值
    private const COORD_SCALE = 684.412;
    private const HEIGHT_SCALE = 684.412;
    private const UPPER_LIMIT = 512.0;
    private const LOWER_LIMIT = 512.0;
    private const BASE_SIZE = 8.5;
    private const STRETCH_Y = 12.0;
    private const MAIN_X = 80.0;
    private const MAIN_Y = 160.0;
    private const MAIN_Z = 80.0;
    private const DEPTH_X = 200.0;
    private const DEPTH_Z = 200.0;

    public function __construct(int $seed)
    {
        $rand = new JavaRandom($seed);

        // 顺序照抄原版构造函数
        $this->lowerNoise = new NoiseGeneratorOctaves($rand, 16);
        $this->upperNoise = new NoiseGeneratorOctaves($rand, 16);
        $this->mainNoise = new NoiseGeneratorOctaves($rand, 8);
        // NoiseGeneratorPerlin(rand, 4) = 4 个 Simplex，只用于地表石块噪声。
        // 高度用不到，但必须按同样的方式消耗随机数，后面的噪声才对得上。
        for ($i = 0; $i < 4; $i++) {
            $rand->nextDouble();
            $rand->nextDouble();
            $rand->nextDouble();
            for ($k = 0; $k < 256; $k++) {
                $rand->nextIntBound(256 - $k);
            }
        }
        // 原版这里还有个 noiseGen5 = Octaves(rand, 10)，地形主计算没用到，
        // 但它会消耗随机数 —— 漏掉它，后面 depth 噪声就全偏了（实测整片低 12 格）
        new NoiseGeneratorOctaves($rand, 10);
        $this->depthNoise = new NoiseGeneratorOctaves($rand, 16);

        for ($j = -2; $j <= 2; $j++) {
            for ($k = -2; $k <= 2; $k++) {
                $this->parabolic[$j + 2 + ($k + 2) * 5] = 10.0 / sqrt($j * $j + $k * $k + 0.2);
            }
        }
    }

    /**
     * 原版 func_147423_a：算出 5×33×5 的密度网格。
     *
     * @param int[] $biomes 10×10 的群系数组（按 z*10+x 排列）
     * @return float[] 5*33*5
     */
    public function noiseField(int $chunkX, int $chunkZ, array $biomes): array
    {
        $x = $chunkX * 4;
        $z = $chunkZ * 4;

        // 原版的 8 参重载等价于：yOffset=10, ySize=1, yScale=1.0
        $depth = $this->depthNoise->generateNoiseOctaves(null, $x, 10, $z, 5, 1, 5, self::DEPTH_X, 1.0, self::DEPTH_Z);
        $main = $this->mainNoise->generateNoiseOctaves(
            null, $x, 0, $z, 5, 33, 5,
            self::COORD_SCALE / self::MAIN_X, self::HEIGHT_SCALE / self::MAIN_Y, self::COORD_SCALE / self::MAIN_Z
        );
        $lower = $this->lowerNoise->generateNoiseOctaves(
            null, $x, 0, $z, 5, 33, 5,
            self::COORD_SCALE, self::HEIGHT_SCALE, self::COORD_SCALE
        );
        $upper = $this->upperNoise->generateNoiseOctaves(
            null, $x, 0, $z, 5, 33, 5,
            self::COORD_SCALE, self::HEIGHT_SCALE, self::COORD_SCALE
        );

        $out = array_fill(0, 5 * 33 * 5, 0.0);
        $i = 0;
        $j = 0;

        for ($k = 0; $k < 5; $k++) {
            for ($l = 0; $l < 5; $l++) {
                $f2 = 0.0;   // 起伏幅度加权
                $f3 = 0.0;   // 基准高度加权
                $f4 = 0.0;   // 权重和
                $center = $biomes[$k + 2 + ($l + 2) * 10];
                $centerRoot = BiomeHeights::rootHeight($center);

                for ($j1 = -2; $j1 <= 2; $j1++) {
                    for ($k1 = -2; $k1 <= 2; $k1++) {
                        $b = $biomes[$k + $j1 + 2 + ($l + $k1 + 2) * 10];
                        $f5 = BiomeHeights::rootHeight($b);
                        $f6 = BiomeHeights::variation($b);
                        $f7 = $this->parabolic[$j1 + 2 + ($k1 + 2) * 5] / ($f5 + 2.0);
                        if (BiomeHeights::rootHeight($b) > $centerRoot) {
                            $f7 /= 2.0;
                        }
                        $f2 += $f6 * $f7;
                        $f3 += $f5 * $f7;
                        $f4 += $f7;
                    }
                }

                $f2 /= $f4;
                $f3 /= $f4;
                $f2 = $f2 * 0.9 + 0.1;
                $f3 = ($f3 * 4.0 - 1.0) / 8.0;

                $d7 = $depth[$j] / 8000.0;
                $d7 = $d7 * 3.0 - 2.0;
                if ($d7 < 0.0) {
                    $d7 /= 2.0;
                    if ($d7 < -1.0) {
                        $d7 = -1.0;
                    }
                    $d7 /= 1.4;
                    $d7 /= 2.0;
                } else {
                    if ($d7 > 1.0) {
                        $d7 = 1.0;
                    }
                    $d7 /= 8.0;
                }

                $j++;
                $d8 = $f3 + $d7 * 0.2;
                $d8 = $d8 * self::BASE_SIZE / 8.0;
                $d0 = self::BASE_SIZE + $d8 * 4.0;

                for ($l1 = 0; $l1 < 33; $l1++) {
                    $d1 = ($l1 - $d0) * self::STRETCH_Y * 128.0 / 256.0 / $f2;
                    if ($d1 < 0.0) {
                        $d1 *= 4.0;
                    }
                    $d2 = $lower[$i] / self::LOWER_LIMIT;
                    $d3 = $upper[$i] / self::UPPER_LIMIT;
                    $d4 = ($main[$i] / 10.0 + 1.0) / 2.0;
                    $d5 = $d2 + ($d3 - $d2) * $d4 - $d1;   // denormalizeClamp

                    if ($l1 > 29) {
                        $d6 = ($l1 - 29) / 3.0;
                        $d5 = $d5 * (1.0 - $d6) + -10.0 * $d6;
                    }

                    $out[$i] = $d5;
                    $i++;
                }
            }
        }

        return $out;
    }

    /**
     * 从密度网格插值出 16×16 的每列地表高度。
     * 密度 > 0 视为石头，找最高的那层。
     *
     * @param float[] $field
     * @return int[] 256 个高度（按 z*16+x）
     */
    public function columnHeights(array $field): array
    {
        $heights = array_fill(0, 256, 0);

        for ($xx = 0; $xx < 16; $xx++) {
            for ($zz = 0; $zz < 16; $zz++) {
                $fx = $xx / 4.0;
                $fz = $zz / 4.0;
                $x0 = (int) floor($fx);
                $z0 = (int) floor($fz);
                $tx = $fx - $x0;
                $tz = $fz - $z0;

                $vals = [];
                for ($y = 0; $y < 33; $y++) {
                    $v00 = $field[($x0 * 5 + $z0) * 33 + $y];
                    $v10 = $field[(($x0 + 1) * 5 + $z0) * 33 + $y];
                    $v01 = $field[($x0 * 5 + $z0 + 1) * 33 + $y];
                    $v11 = $field[(($x0 + 1) * 5 + $z0 + 1) * 33 + $y];
                    $v0 = $v00 + ($v10 - $v00) * $tx;
                    $v1 = $v01 + ($v11 - $v01) * $tx;
                    $vals[$y] = $v0 + ($v1 - $v0) * $tz;
                }

                // 找最后一个正密度格，再和下一格之间插值出零点
                $top = -1;
                for ($y = 32; $y >= 0; $y--) {
                    if ($vals[$y] > 0.0) {
                        $top = $y;
                        break;
                    }
                }

                if ($top < 0) {
                    $heights[$zz * 16 + $xx] = 0;
                    continue;
                }
                if ($top >= 32) {
                    $heights[$zz * 16 + $xx] = 256;
                    continue;
                }

                $a = $vals[$top];
                $b = $vals[$top + 1];
                $frac = ($a - $b) > 0.0 ? $a / ($a - $b) : 0.0;
                // 第 y 格代表 y*8，往上还有 8 格空间
                $heights[$zz * 16 + $xx] = (int) round(($top + $frac) * 8.0);
            }
        }

        return $heights;
    }
}
