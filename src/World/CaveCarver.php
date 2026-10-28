<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 洞穴雕刻（简化版，不是原版 MapGenCaves 的 1:1 移植）。
 *
 * 做法：每个区块用 (seed, chunkX, chunkZ) 派生的随机数选若干起点，
 * 从起点开始随机游走并挖球形空腔，半径随步数衰减、Y 越低越粗。
 *
 * 和原版的差别：原版是椭球拉伸 + 岩浆层判断 + 峡谷单独一套，
 * 这里是圆球。观感上像洞穴，但同一 seed 不会和原版一致。
 */
final class CaveCarver
{
    private const CAVES_PER_CHUNK = 12;
    private const STEPS = 32;
    private const MIN_Y = 5;
    private const MAX_Y = 52;

    /** @var callable */
    private $carve;

    /**
     * @param callable $carve function(int $x, int $y, int $z): void —— 把该格挖空
     */
    public function __construct(callable $carve)
    {
        $this->carve = $carve;
    }

    /** 为区块 (cx,cz) 挖洞。坐标是区块内局部坐标 0..15。 */
    public function carveChunk(int $seed, int $chunkX, int $chunkZ): void
    {
        $rand = new JavaRandom($seed ^ ($chunkX * 341873128712 + $chunkZ * 132897987541));

        for ($c = 0; $c < self::CAVES_PER_CHUNK; $c++) {
            $x = $rand->nextFloat() * 16.0;
            $y = self::MIN_Y + $rand->nextFloat() * (self::MAX_Y - self::MIN_Y);
            $z = $rand->nextFloat() * 16.0;

            // 随机游走方向
            $yaw = $rand->nextFloat() * M_PI * 2.0;
            $pitch = ($rand->nextFloat() - 0.5) * 0.4;
            $radius = 1.2 + $rand->nextFloat() * 1.4;

            for ($s = 0; $s < self::STEPS; $s++) {
                $this->sphere((int) floor($x), (int) floor($y), (int) floor($z), $radius);
                $yaw += ($rand->nextFloat() - 0.5) * 0.6;
                $pitch += ($rand->nextFloat() - 0.5) * 0.12;
                if ($pitch > 0.5) {
                    $pitch = 0.5;
                } elseif ($pitch < -0.5) {
                    $pitch = -0.5;
                }
                $x += cos($yaw) * cos($pitch);
                $z += sin($yaw) * cos($pitch);
                $y += sin($pitch);
                $radius *= 0.97;

                if ($y < self::MIN_Y || $y > self::MAX_Y || $radius < 0.4) {
                    break;
                }
                // 游走出区块就停（相邻区块会各自挖，接缝能对上大致走向）
                if ($x < -4 || $x > 20 || $z < -4 || $z > 20) {
                    break;
                }
            }
        }
    }

    private function sphere(int $x, int $y, int $z, float $r): void
    {
        $ri = (int) ceil($r);
        for ($dx = -$ri; $dx <= $ri; $dx++) {
            for ($dy = -$ri; $dy <= $ri; $dy++) {
                for ($dz = -$ri; $dz <= $ri; $dz++) {
                    if ($dx * $dx + $dy * $dy + $dz * $dz > $r * $r) {
                        continue;
                    }
                    ($this->carve)($x + $dx, $y + $dy, $z + $dz);
                }
            }
        }
    }
}
