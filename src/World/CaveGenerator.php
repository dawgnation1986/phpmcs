<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 原版 MapGenBase + MapGenCaves 的移植（1.8.9，逐行对照 MCP 源码）。
 *
 * 关键点：
 *  - 每个区块会被周围 17x17 个「区块坐标」各贡献一次洞穴，
 *    所以洞穴能跨区块连起来。每个贡献用 (worldSeed, 邻居区块坐标) 重新播种。
 *  - 隧道是一条条递归的「蠕虫」，用椭球挖空；碰到水就整个放弃，
 *    地表是草/菌丝的话会顺手把下面换成对应群系的表层方块。
 */
final class CaveGenerator
{
    private const RANGE = 8;

    private JavaRandom $rand;
    /** @var callable */
    private $get;
    /** @var callable */
    private $set;

    /** 目标区块的世界坐标范围 */
    private int $minX = 0;
    private int $minZ = 0;

    /**
     * @param callable $get function(int $wx,int $wy,int $wz): int  （区块外返回 0）
     * @param callable $set function(int $wx,int $wy,int $wz,int $id): void
     */
    public function __construct(int $worldSeed, callable $get, callable $set)
    {
        $this->rand = new JavaRandom($worldSeed);
        $this->get = $get;
        $this->set = $set;
    }

    public function generate(int $worldSeed, int $chunkX, int $chunkZ): void
    {
        $this->minX = $chunkX * 16;
        $this->minZ = $chunkZ * 16;

        // 原版 MapGenBase.generate
        $this->rand->setSeed($worldSeed);
        $j = $this->rand->nextLong();
        $k = $this->rand->nextLong();

        for ($l = $chunkX - self::RANGE; $l <= $chunkX + self::RANGE; $l++) {
            for ($i1 = $chunkZ - self::RANGE; $i1 <= $chunkZ + self::RANGE; $i1++) {
                $j1 = LongMath::mul($l, $j);
                $k1 = LongMath::mul($i1, $k);
                $this->rand->setSeed($j1 ^ $k1 ^ $worldSeed);
                $this->recursiveGenerate($l, $i1);
            }
        }
    }

    private function recursiveGenerate(int $chunkX, int $chunkZ): void
    {
        $i = $this->rand->nextIntBound($this->rand->nextIntBound($this->rand->nextIntBound(15) + 1) + 1);
        if ($this->rand->nextIntBound(7) !== 0) {
            $i = 0;
        }

        for ($j = 0; $j < $i; $j++) {
            $d0 = $chunkX * 16 + $this->rand->nextIntBound(16);
            $d1 = $this->rand->nextIntBound($this->rand->nextIntBound(120) + 8);
            $d2 = $chunkZ * 16 + $this->rand->nextIntBound(16);

            $k = 1;
            if ($this->rand->nextIntBound(4) === 0) {
                $this->addTunnel($this->rand->nextLong(), $d0, $d1, $d2,
                    1.0 + $this->rand->nextFloat() * 6.0, 0.0, 0.0, -1, -1, 0.5);
                $k += $this->rand->nextIntBound(4);
            }

            for ($l = 0; $l < $k; $l++) {
                $f = $this->rand->nextFloat() * M_PI * 2.0;
                $f1 = ($this->rand->nextFloat() - 0.5) * 2.0 / 8.0;
                $f2 = $this->rand->nextFloat() * 2.0 + $this->rand->nextFloat();
                if ($this->rand->nextIntBound(10) === 0) {
                    $f2 *= $this->rand->nextFloat() * $this->rand->nextFloat() * 3.0 + 1.0;
                }
                $this->addTunnel($this->rand->nextLong(), $d0, $d1, $d2, $f2, $f, $f1, 0, 0, 1.0);
            }
        }
    }

    /** 原版 func_180702_a：一条隧道 */
    private function addTunnel(
        int $seed,
        float $x, float $y, float $z,
        float $thickness, float $yaw, float $pitch,
        int $step, int $totalSteps, float $heightScale
    ): void {
        $d0 = $this->minX + 8;
        $d1 = $this->minZ + 8;
        $f = 0.0;
        $f1 = 0.0;
        $random = new JavaRandom($seed);

        if ($totalSteps <= 0) {
            $i = self::RANGE * 16 - 16;
            $totalSteps = $i - $random->nextIntBound(intdiv($i, 4));
        }

        $isRing = false;
        if ($step === -1) {
            $step = intdiv($totalSteps, 2);
            $isRing = true;
        }

        $j = $random->nextIntBound(intdiv($totalSteps, 2)) + intdiv($totalSteps, 4);
        $isSpherical = $random->nextIntBound(6) === 0;

        for (; $step < $totalSteps; $step++) {
            $d2 = 1.5 + sin($step * M_PI / $totalSteps) * $thickness * 1.0;
            $d3 = $d2 * $heightScale;
            $f2 = cos($pitch);
            $f3 = sin($pitch);
            $x += cos($yaw) * $f2;
            $y += $f3;
            $z += sin($yaw) * $f2;

            $pitch = $isSpherical ? $pitch * 0.92 : $pitch * 0.7;
            $pitch += $f1 * 0.1;
            $yaw += $f * 0.1;
            $f1 *= 0.9;
            $f *= 0.75;
            $f1 += ($random->nextFloat() - $random->nextFloat()) * $random->nextFloat() * 2.0;
            $f += ($random->nextFloat() - $random->nextFloat()) * $random->nextFloat() * 4.0;

            if (!$isRing && $step === $j && $thickness > 1.0 && $totalSteps > 0) {
                // 分叉
                $this->addTunnel($random->nextLong(), $x, $y, $z, $random->nextFloat() * 0.5 + 0.5,
                    $yaw - M_PI / 2, $pitch / 3.0, $step, $totalSteps, 1.0);
                $this->addTunnel($random->nextLong(), $x, $y, $z, $random->nextFloat() * 0.5 + 0.5,
                    $yaw + M_PI / 2, $pitch / 3.0, $step, $totalSteps, 1.0);

                return;
            }

            if (!$isRing && $random->nextIntBound(4) === 0) {
                continue;
            }

            $d4 = $x - $d0;
            $d5 = $z - $d1;
            $d6 = $totalSteps - $step;
            $d7 = $thickness + 2.0 + 16.0;
            if ($d4 * $d4 + $d5 * $d5 - $d6 * $d6 > $d7 * $d7) {
                return;
            }
            if ($x < $d0 - 16.0 - $d2 * 2.0 || $z < $d1 - 16.0 - $d2 * 2.0
                || $x > $d0 + 16.0 + $d2 * 2.0 || $z > $d1 + 16.0 + $d2 * 2.0) {
                continue;
            }

            $k2 = (int) floor($x - $d2) - $this->minX - 1;
            $k = (int) floor($x + $d2) - $this->minX + 1;
            $l2 = (int) floor($y - $d3) - 1;
            $l = (int) floor($y + $d3) + 1;
            $i3 = (int) floor($z - $d2) - $this->minZ - 1;
            $i1 = (int) floor($z + $d2) - $this->minZ + 1;

            $k2 = max(0, $k2);
            $k = min(16, $k);
            $l2 = max(1, $l2);
            $l = min(248, $l);
            $i3 = max(0, $i3);
            $i1 = min(16, $i1);

            // 碰到水就整条不要
            $hitWater = false;
            for ($j1 = $k2; !$hitWater && $j1 < $k; $j1++) {
                for ($k1 = $i3; !$hitWater && $k1 < $i1; $k1++) {
                    for ($l1 = $l + 1; !$hitWater && $l1 >= $l2 - 1; $l1--) {
                        if ($l1 >= 0 && $l1 < 256) {
                            $id = ($this->get)($j1 + $this->minX, $l1, $k1 + $this->minZ);
                            if ($id === 8 || $id === 9) {
                                $hitWater = true;
                            }
                            if ($l1 !== $l2 - 1 && $j1 !== $k2 && $j1 !== $k - 1
                                && $k1 !== $i3 && $k1 !== $i1 - 1) {
                                $l1 = $l2;
                            }
                        }
                    }
                }
            }

            if ($hitWater) {
                continue;
            }

            for ($j3 = $k2; $j3 < $k; $j3++) {
                $d10 = (($j3 + $this->minX) + 0.5 - $x) / $d2;
                for ($i2 = $i3; $i2 < $i1; $i2++) {
                    $d8 = (($i2 + $this->minZ) + 0.5 - $z) / $d2;
                    if ($d10 * $d10 + $d8 * $d8 >= 1.0) {
                        continue;
                    }
                    for ($j2 = $l; $j2 > $l2; $j2--) {
                        $d9 = (($j2 - 1) + 0.5 - $y) / $d3;
                        if ($d9 <= -0.7 || $d10 * $d10 + $d9 * $d9 + $d8 * $d8 >= 1.0) {
                            continue;
                        }
                        $here = ($this->get)($j3 + $this->minX, $j2, $i2 + $this->minZ);
                        $above = ($this->get)($j3 + $this->minX, $j2 + 1, $i2 + $this->minZ);

                        if ($here === 2 || $here === 110) {
                            ($this->set)($j3 + $this->minX, $j2, $i2 + $this->minZ, 3);
                        }

                        if (!self::canCarve($here, $above)) {
                            continue;
                        }
                        if ($j2 - 1 < 10) {
                            ($this->set)($j3 + $this->minX, $j2, $i2 + $this->minZ, 11);   // 岩浆
                        } else {
                            ($this->set)($j3 + $this->minX, $j2, $i2 + $this->minZ, 0);
                            if ($above === 12) {
                                ($this->set)($j3 + $this->minX, $j2 + 1, $i2 + $this->minZ, 24);  // 沙上盖砂岩
                            }
                        }
                    }
                }
                if ($isRing) {
                    break;
                }
            }
        }
    }

    /** 原版 func_175793_a：这些方块可以被挖掉 */
    private static function canCarve(int $here, int $above): bool
    {
        if ($here === 1 || $here === 3 || $here === 2 || $here === 159 || $here === 24 || $here === 110 || $here === 78) {
            return true;
        }
        if ($here === 12 || $here === 13) {
            return $above !== 8 && $above !== 9;   // 沙/沙砾遇到水就不挖
        }

        return false;
    }
}
