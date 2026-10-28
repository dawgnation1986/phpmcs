<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 原版 NoiseGeneratorImproved 的移植（1.8.9 / MCP 源码逐行对照）。
 *
 * 和原版一样：构造时用 java.util.Random 生成 3 个 256 范围的偏移量，
 * 再把 0..255 做一次 Fisher-Yates 洗牌并复制成 512 项。
 * 噪声值是三次样条（quintic）插值 + 16 个梯度方向的点积。
 */
final class NoiseGeneratorImproved
{
    /** 梯度表（原版 field_152381_e/f/g，三维） */
    private const GX = [1.0, -1.0, 1.0, -1.0, 1.0, -1.0, 1.0, -1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, -1.0, 0.0];
    private const GY = [1.0, 1.0, -1.0, -1.0, 0.0, 0.0, 0.0, 0.0, 1.0, -1.0, 1.0, -1.0, 1.0, -1.0, 1.0, -1.0];
    private const GZ = [0.0, 0.0, 0.0, 0.0, 1.0, 1.0, -1.0, -1.0, 1.0, 1.0, -1.0, -1.0, 0.0, 1.0, 0.0, -1.0];
    /** 二维梯度（原版 field_152384_h / 385_i） */
    private const G2X = [1.0, -1.0, 1.0, -1.0, 1.0, -1.0, 1.0, -1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, -1.0, 0.0];
    private const G2Y = [0.0, 0.0, 0.0, 0.0, 1.0, 1.0, -1.0, -1.0, 1.0, 1.0, -1.0, -1.0, 0.0, 1.0, 0.0, -1.0];

    /** @var int[] 512 项排列表 */
    private array $perm = [];
    private float $xo;
    private float $yo;
    private float $zo;

    public function __construct(JavaRandom $rand)
    {
        $this->xo = (float) ($rand->nextDouble() * 256.0);
        $this->yo = (float) ($rand->nextDouble() * 256.0);
        $this->zo = (float) ($rand->nextDouble() * 256.0);

        $p = range(0, 255);
        for ($i = 0; $i < 256; $i++) {
            $k = $rand->nextIntBound(256 - $i) + $i;
            $t = $p[$i];
            $p[$i] = $p[$k];
            $p[$k] = $t;
        }
        $this->perm = array_merge($p, $p);
    }

    private function lerp(float $t, float $a, float $b): float
    {
        return $a + $t * ($b - $a);
    }

    /** 二维梯度点积 */
    private function grad2(int $hash, float $x, float $y): float
    {
        $h = $hash & 0xF;

        return self::G2X[$h] * $x + self::G2Y[$h] * $y;
    }

    /** 三维梯度点积 */
    private function grad3(int $hash, float $x, float $y, float $z): float
    {
        $h = $hash & 0xF;

        return self::GX[$h] * $x + self::GY[$h] * $y + self::GZ[$h] * $z;
    }

    /**
     * 原版 populateNoiseArray。
     * @param float[] $noiseArray 累加到已有值上（不是覆盖）
     */
    public function populateNoiseArray(
        array &$noiseArray,
        float $xOffset, float $yOffset, float $zOffset,
        int $xSize, int $ySize, int $zSize,
        float $xScale, float $yScale, float $zScale,
        float $noiseScale
    ): void {
        $p = $this->perm;

        if ($ySize === 1) {
            // 二维：只要一层
            $idx = 0;
            $d16 = 1.0 / $noiseScale;
            for ($jx = 0; $jx < $xSize; $jx++) {
                $dx = $xOffset + $jx * $xScale + $this->xo;
                $ix = (int) $dx;
                if ($dx < (float) $ix) {
                    $ix--;
                }
                $k2 = $ix & 255;
                $dx -= (float) $ix;
                $d18 = $dx * $dx * $dx * ($dx * ($dx * 6.0 - 15.0) + 10.0);

                for ($jz = 0; $jz < $zSize; $jz++) {
                    $dz = $zOffset + $jz * $zScale + $this->zo;
                    $iz = (int) $dz;
                    if ($dz < (float) $iz) {
                        $iz--;
                    }
                    $l6 = $iz & 255;
                    $dz -= (float) $iz;
                    $d20 = $dz * $dz * $dz * ($dz * ($dz * 6.0 - 15.0) + 10.0);

                    $i5 = $p[$k2] + 0;
                    $j5 = $p[$i5] + $l6;
                    $j = $p[$k2 + 1] + 0;
                    $k5 = $p[$j] + $l6;

                    $d14 = $this->lerp($d18, $this->grad2($p[$j5], $dx, $dz), $this->grad2($p[$k5], $dx - 1.0, $dz));
                    $d15 = $this->lerp($d18, $this->grad2($p[$j5 + 1], $dx, $dz - 1.0), $this->grad2($p[$k5 + 1], $dx - 1.0, $dz - 1.0));
                    $d21 = $this->lerp($d20, $d14, $d15);

                    $noiseArray[$idx++] += $d21 * $d16;
                }
            }

            return;
        }

        // 三维
        $idx = 0;
        $d0 = 1.0 / $noiseScale;
        $kLast = -1;
        $l = 0;
        $i1 = 0;
        $j1 = 0;
        $k1 = 0;
        $l1 = 0;
        $i2 = 0;
        $d1 = $d2 = $d3 = $d4 = 0.0;

        for ($lx = 0; $lx < $xSize; $lx++) {
            $d5 = $xOffset + $lx * $xScale + $this->xo;
            $i3 = (int) $d5;
            if ($d5 < (float) $i3) {
                $i3--;
            }
            $j3 = $i3 & 255;
            $d5 -= (float) $i3;
            $d6 = $d5 * $d5 * $d5 * ($d5 * ($d5 * 6.0 - 15.0) + 10.0);

            for ($k3 = 0; $k3 < $zSize; $k3++) {
                $d7 = $zOffset + $k3 * $zScale + $this->zo;
                $l3 = (int) $d7;
                if ($d7 < (float) $l3) {
                    $l3--;
                }
                $i4 = $l3 & 255;
                $d7 -= (float) $l3;
                $d8 = $d7 * $d7 * $d7 * ($d7 * ($d7 * 6.0 - 15.0) + 10.0);

                for ($j4 = 0; $j4 < $ySize; $j4++) {
                    $d9 = $yOffset + $j4 * $yScale + $this->yo;
                    $k4 = (int) $d9;
                    if ($d9 < (float) $k4) {
                        $k4--;
                    }
                    $l4 = $k4 & 255;
                    $d9 -= (float) $k4;
                    $d10 = $d9 * $d9 * $d9 * ($d9 * ($d9 * 6.0 - 15.0) + 10.0);

                    if ($j4 === 0 || $l4 !== $kLast) {
                        $kLast = $l4;
                        $l = $p[$j3] + $l4;
                        $i1 = $p[$l] + $i4;
                        $j1 = $p[$l + 1] + $i4;
                        $k1 = $p[$j3 + 1] + $l4;
                        $l1 = $p[$k1] + $i4;
                        $i2 = $p[$k1 + 1] + $i4;

                        $d1 = $this->lerp($d6, $this->grad3($p[$i1], $d5, $d9, $d7), $this->grad3($p[$l1], $d5 - 1.0, $d9, $d7));
                        $d2 = $this->lerp($d6, $this->grad3($p[$j1], $d5, $d9 - 1.0, $d7), $this->grad3($p[$i2], $d5 - 1.0, $d9 - 1.0, $d7));
                        $d3 = $this->lerp($d6, $this->grad3($p[$i1 + 1], $d5, $d9, $d7 - 1.0), $this->grad3($p[$l1 + 1], $d5 - 1.0, $d9, $d7 - 1.0));
                        $d4 = $this->lerp($d6, $this->grad3($p[$j1 + 1], $d5, $d9 - 1.0, $d7 - 1.0), $this->grad3($p[$i2 + 1], $d5 - 1.0, $d9 - 1.0, $d7 - 1.0));
                    }

                    $d11 = $this->lerp($d10, $d1, $d2);
                    $d12 = $this->lerp($d10, $d3, $d4);
                    $d13 = $this->lerp($d8, $d11, $d12);

                    $noiseArray[$idx++] += $d13 * $d0;
                }
            }
        }
    }
}
