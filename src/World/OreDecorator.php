<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 矿石生成（简化版，不是原版 BiomeDecorator 的 1:1 移植）。
 *
 * 每个区块按固定数量撒矿脉：起点随机、沿随机方向铺 count 个方块，
 * 只替换石头。数量/高度带参考原版 BiomeDecorator 的默认值。
 */
final class OreDecorator
{
    /** [方块 id, 每区块脉数, 每脉最少, 每脉最多, 最低 y, 最高 y] */
    private const ORES = [
        [16, 20, 1, 16, 1, 128],   // 煤
        [15, 20, 1, 8, 1, 64],     // 铁
        [14, 2, 1, 8, 1, 32],      // 金
        [73, 8, 1, 8, 1, 16],      // 红石
        [56, 1, 1, 8, 1, 16],      // 钻石
        [21, 1, 1, 6, 1, 32],      // 青金石
        [13, 10, 1, 32, 1, 128],   // 沙砾脉
        [3, 10, 1, 32, 1, 128],    // 泥土脉
    ];

    /** @var callable */
    private $put;

    /**
     * @param callable $put function(int $x, int $y, int $z, int $id): void —— 只在石头处放
     */
    public function __construct(callable $put)
    {
        $this->put = $put;
    }

    public function decorate(int $seed, int $chunkX, int $chunkZ): void
    {
        $rand = new JavaRandom($seed + $chunkX * 341873128712 + $chunkZ * 132897987541 + 7);

        foreach (self::ORES as [$id, $veins, $min, $max, $yMin, $yMax]) {
            for ($v = 0; $v < $veins; $v++) {
                $x = $rand->nextIntBound(16);
                $y = $yMin + $rand->nextIntBound(max(1, $yMax - $yMin));
                $z = $rand->nextIntBound(16);
                $count = $min + $rand->nextIntBound(max(1, $max - $min + 1));

                // 沿随机方向铺一段
                $dx = 0;
                $dy = 0;
                $dz = 0;
                if ($rand->nextIntBound(2) === 0) {
                    $dx = $rand->nextIntBound(3) - 1;
                }
                if ($rand->nextIntBound(2) === 0) {
                    $dy = $rand->nextIntBound(3) - 1;
                }
                if ($rand->nextIntBound(2) === 0) {
                    $dz = $rand->nextIntBound(3) - 1;
                }

                for ($i = 0; $i < $count; $i++) {
                    ($this->put)($x, $y, $z, $id);
                    if ($rand->nextIntBound(3) === 0) {
                        $dx = $rand->nextIntBound(3) - 1;
                    }
                    if ($rand->nextIntBound(3) === 0) {
                        $dy = $rand->nextIntBound(3) - 1;
                    }
                    if ($rand->nextIntBound(3) === 0) {
                        $dz = $rand->nextIntBound(3) - 1;
                    }
                    $x += $dx;
                    $y += $dy;
                    $z += $dz;
                    if ($x < 0 || $x > 15 || $z < 0 || $z > 15 || $y < 1 || $y > 127) {
                        break;
                    }
                }
            }
        }
    }
}
