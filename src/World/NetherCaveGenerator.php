<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 原版 MapGenBase + MapGenCavesHell 的移植 —— 下界的弯曲隧道洞穴。
 *
 * 为什么必须有这个：
 *   基础密度场只决定「哪里是实心、哪里是空的」，出来的是一块一块的
 *   大空腔 + 大岩体，边缘都是 4 格粒度的直角。原版下界那种「隧道穿来穿去」
 *   的观感，几乎全部来自洞穴雕刻这一步。之前跳过这步，地形怎么看都像
 *   「地下洞穴」而不是「下界」。
 *
 * 流程（照 MCP 1.8.9）：
 *   MapGenBase.generate：遍历自己周围 8 格区块内的 17×17 个「起源区块」，
 *   每个起源用 (originX*j ^ originZ*k ^ seed) 重置随机数，跑 recursiveGenerate
 *   MapGenCavesHell.recursiveGenerate：决定这个起源要挖几条隧道
 *   addTunnel：沿 yaw/pitch 推进，半径按 sin 曲线起伏，途中可能分叉
 */
final class NetherCaveGenerator
{
    /** MapGenBase.range：要检查周围多少格区块的起源 */
    private const RANGE = 8;

    private const NETHERRACK = 87;
    private const DIRT = 3;
    private const GRASS = 2;

    public function __construct(private int $seed)
    {
    }

    /**
     * 在已经生成好地形的 blocks 上挖洞穴。原地修改。
     *
     * @param array<int, array<int, int>> $blocks [y][z*16+x]
     */
    public function generate(int $cx, int $cz, array &$blocks): void
    {
        $rand = new JavaRandom($this->seed);
        $j = $rand->nextLong();
        $k = $rand->nextLong();

        for ($ox = $cx - self::RANGE; $ox <= $cx + self::RANGE; $ox++) {
            for ($oz = $cz - self::RANGE; $oz <= $cz + self::RANGE; $oz++) {
                $seed = LongMath::mul($ox, $j) ^ LongMath::mul($oz, $k) ^ $this->seed;
                $rand->setSeed($seed);
                $this->recursiveGenerate($ox, $oz, $cx, $cz, $blocks, $rand);
            }
        }
    }

    /**
     * 一个起源区块要挖几条隧道。
     *
     * @param array<int, array<int, int>> $blocks
     */
    private function recursiveGenerate(
        int $originCx,
        int $originCz,
        int $targetCx,
        int $targetCz,
        array &$blocks,
        JavaRandom $rand
    ): void {
        // 原版就是三层嵌套的 nextInt：nextInt(nextInt(nextInt(10)+1)+1)
        $i = $rand->nextIntBound($rand->nextIntBound($rand->nextIntBound(10) + 1) + 1);
        if ($rand->nextIntBound(5) !== 0) {
            $i = 0;
        }

        for ($n = 0; $n < $i; $n++) {
            $x = (float) ($originCx * 16 + $rand->nextIntBound(16));
            $y = (float) $rand->nextIntBound(128);
            $z = (float) ($originCz * 16 + $rand->nextIntBound(16));
            $branches = 1;

            // 1/4 概率先挖一间「大厅」
            if ($rand->nextIntBound(4) === 0) {
                $radius = 1.0 + $rand->nextFloat() * 6.0;
                $this->addTunnel(
                    $rand->nextLong(), $targetCx, $targetCz, $blocks,
                    $x, $y, $z, $radius, 0.0, 0.0, -1, -1, 0.5, $rand
                );
                $branches += $rand->nextIntBound(4);
            }

            for ($l = 0; $l < $branches; $l++) {
                $yaw = $rand->nextFloat() * M_PI * 2.0;
                $pitch = ($rand->nextFloat() - 0.5) * 2.0 / 8.0;
                $radius = $rand->nextFloat() * 2.0 + $rand->nextFloat();

                $this->addTunnel(
                    $rand->nextLong(), $targetCx, $targetCz, $blocks,
                    $x, $y, $z, $radius * 2.0, $yaw, $pitch, 0, 0, 0.5, $rand
                );
            }
        }
    }

    /**
     * 一条隧道的推进与雕刻。
     *
     * @param array<int, array<int, int>> $blocks
     */
    private function addTunnel(
        int $tunnelSeed,
        int $targetCx,
        int $targetCz,
        array &$blocks,
        float $x,
        float $y,
        float $z,
        float $radius,
        float $yaw,
        float $pitch,
        int $step,
        int $total,
        float $thick,
        JavaRandom $outerRand
    ): void {
        $centerX = (float) ($targetCx * 16 + 8);
        $centerZ = (float) ($targetCz * 16 + 8);
        $driftYaw = 0.0;
        $driftPitch = 0.0;

        $rnd = new JavaRandom($tunnelSeed);

        if ($total <= 0) {
            $i = self::RANGE * 16 - 16;
            $total = $i - $rnd->nextIntBound(intdiv($i, 4));
        }

        $isRoom = false;
        if ($step === -1) {
            $step = intdiv($total, 2);
            $isRoom = true;
        }

        $forkAt = $rnd->nextIntBound(intdiv($total, 2)) + intdiv($total, 4);
        $flatten = $rnd->nextIntBound(6) === 0;

        for (; $step < $total; $step++) {
            // 半径按正弦起伏，两头细中间粗
            $r2 = 1.5 + sin($step * M_PI / $total) * $radius;
            $r3 = $r2 * $thick;

            $cosPitch = cos($pitch);
            $sinPitch = sin($pitch);

            $x += cos($yaw) * $cosPitch;
            $y += $sinPitch;
            $z += sin($yaw) * $cosPitch;

            $pitch *= $flatten ? 0.92 : 0.7;
            $pitch += $driftPitch * 0.1;
            $yaw += $driftYaw * 0.1;
            $driftPitch *= 0.9;
            $driftYaw *= 0.75;
            $driftPitch += ($outerRand->nextFloat() - $outerRand->nextFloat()) * $outerRand->nextFloat() * 2.0;
            $driftYaw += ($outerRand->nextFloat() - $outerRand->nextFloat()) * $outerRand->nextFloat() * 4.0;

            // 中途分叉成两条
            if (!$isRoom && $step === $forkAt && $radius > 1.0) {
                $this->addTunnel(
                    $outerRand->nextLong(), $targetCx, $targetCz, $blocks,
                    $x, $y, $z,
                    $outerRand->nextFloat() * 0.5 + 0.5,
                    $yaw - (M_PI / 2.0),
                    $pitch / 3.0,
                    $step, $total, 1.0, $outerRand
                );
                $this->addTunnel(
                    $outerRand->nextLong(), $targetCx, $targetCz, $blocks,
                    $x, $y, $z,
                    $outerRand->nextFloat() * 0.5 + 0.5,
                    $yaw + (M_PI / 2.0),
                    $pitch / 3.0,
                    $step, $total, 1.0, $outerRand
                );

                return;
            }

            // 3/4 概率跳过这一格，让隧道不连续、更自然
            if (!$isRoom && $outerRand->nextIntBound(4) === 0) {
                continue;
            }

            $dx = $x - $centerX;
            $dz = $z - $centerZ;
            $left = (float) ($total - $step);
            $reach = $radius + 2.0 + 16.0;

            if ($dx * $dx + $dz * $dz - $left * $left > $reach * $reach) {
                return;
            }

            if ($x < $centerX - 16.0 - $r2 * 2.0
                || $z < $centerZ - 16.0 - $r2 * 2.0
                || $x > $centerX + 16.0 + $r2 * 2.0
                || $z > $centerZ + 16.0 + $r2 * 2.0) {
                continue;
            }

            // ---- 包围盒裁到当前区块 ----
            $x0 = (int) floor($x - $r2) - $targetCx * 16 - 1;
            $x1 = (int) floor($x + $r2) - $targetCx * 16 + 1;
            $y0 = (int) floor($y - $r3) - 1;
            $y1 = (int) floor($y + $r3) + 1;
            $z0 = (int) floor($z - $r2) - $targetCz * 16 - 1;
            $z1 = (int) floor($z + $r2) - $targetCz * 16 + 1;

            if ($x0 < 0) {
                $x0 = 0;
            }
            if ($x1 > 16) {
                $x1 = 16;
            }
            if ($y0 < 1) {
                $y0 = 1;
            }
            if ($y1 > 120) {
                $y1 = 120;
            }
            if ($z0 < 0) {
                $z0 = 0;
            }
            if ($z1 > 16) {
                $z1 = 16;
            }

            // 包围盒里有岩浆就整段跳过 —— 不然会把岩浆海挖成空腔。
            // 原版只扫外壳（内部直接跳到 k2），不逐个格子查。
            $hasLava = false;
            for ($bx = $x0; !$hasLava && $bx < $x1; $bx++) {
                for ($bz = $z0; !$hasLava && $bz < $z1; $bz++) {
                    for ($by = $y1; !$hasLava && $by >= $y0 - 1; $by--) {
                        if ($by >= 0 && $by < 128) {
                            $id = $blocks[$by][($bz << 4) | $bx] ?? 0;
                            if ($id === 10 || $id === 11) {
                                $hasLava = true;
                            }
                            if ($by !== $y0 - 1 && $bx !== $x0 && $bx !== $x1 - 1
                                && $bz !== $z0 && $bz !== $z1 - 1) {
                                $by = $y0;
                            }
                        }
                    }
                }
            }

            if ($hasLava) {
                continue;
            }

            for ($bx = $x0; $bx < $x1; $bx++) {
                $fx = ($bx + $targetCx * 16 + 0.5 - $x) / $r2;

                for ($bz = $z0; $bz < $z1; $bz++) {
                    $fz = ($bz + $targetCz * 16 + 0.5 - $z) / $r2;

                    for ($by = $y1; $by > $y0; $by--) {
                        $fy = ($by - 1 + 0.5 - $y) / $r3;

                        if ($fy > -0.7 && $fx * $fx + $fy * $fy + $fz * $fz < 1.0) {
                            // 注意：判定用 by-1，写入用 by —— 原版就是这么错开一格的
                            $id = $blocks[$by][($bz << 4) | $bx] ?? 0;
                            if ($id === self::NETHERRACK || $id === self::DIRT || $id === self::GRASS) {
                                $blocks[$by][($bz << 4) | $bx] = 0;
                            }
                        }
                    }
                }
            }

            if ($isRoom) {
                break;
            }
        }
    }
}
