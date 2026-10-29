<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 村庄生成（简化版）。
 *
 * 选址用的是原版 MapGenVillage.canSpawnStructureAtCoords 的公式：
 *   每 32 个区块为一个格子，格子里随机偏移 0..23，落到的那一格就是村中心；
 *   再要求该处群系是 平原/沙漠/热带草原。
 *
 * 建筑是简化实现（不是原版那套递归 StructureVillagePieces）：
 * 一口水井 + 2~4 间小房子 + 砾石小路，材料按群系换。
 * 观感上是"一个村子"，但和原版同种子的村庄不会长得一样。
 */
final class VillageGenerator
{
    private const SPACING = 32;      // field_82665_g
    private const SEPARATION = 8;    // field_82666_h
    private const SALT = 10387312;

    /** 能刷村庄的群系：平原 / 沙漠 / 热带草原 */
    public const BIOMES = [1, 2, 35];

    /** 这个区块是不是村中心（原版公式） */
    public static function isVillageChunk(int $seed, int $chunkX, int $chunkZ): bool
    {
        $i = $chunkX;
        $j = $chunkZ;
        if ($chunkX < 0) {
            $chunkX -= self::SPACING - 1;
        }
        if ($chunkZ < 0) {
            $chunkZ -= self::SPACING - 1;
        }

        $k = intdiv($chunkX, self::SPACING);
        $l = intdiv($chunkZ, self::SPACING);

        $rand = new JavaRandom(0);
        $rand->setSeed(LongMath::add(
            LongMath::add(
                LongMath::mul($k, 341873128712),
                LongMath::mul($l, 132897987541)
            ),
            LongMath::add($seed, self::SALT)
        ));

        $k *= self::SPACING;
        $l *= self::SPACING;
        $k += $rand->nextIntBound(self::SPACING - self::SEPARATION);
        $l += $rand->nextIntBound(self::SPACING - self::SEPARATION);

        return $i === $k && $j === $l;
    }

    /**
     * 在一个区块里盖村庄。
     *
     * @param array    $surface 该区块 16x16 的地表高度
     * @param callable $set     function(int $x,int $y,int $z,int $id,int $meta=0)
     * @param callable $get     function(int $x,int $y,int $z): int
     */
    public static function build(
        int $cx,
        int $cz,
        int $biome,
        array $surface,
        callable $set,
        callable $get
    ): void {
        // 地坪高度取中心那几列的中位数，避免建在斜坡上塌一半
        $samples = [];
        for ($z = 6; $z <= 9; $z++) {
            for ($x = 6; $x <= 9; $x++) {
                $samples[] = $surface[$z * 16 + $x];
            }
        }
        sort($samples);
        $floorY = $samples[intdiv(count($samples), 2)];

        // 先把 16x16 整片铲平到 floorY（只铲，不填悬空）
        for ($z = 1; $z < 15; $z++) {
            for ($x = 1; $x < 15; $x++) {
                $h = $surface[$z * 16 + $x];
                if ($h > $floorY) {
                    for ($y = $floorY + 1; $y <= $h; $y++) {
                        $set($x, $y, $z, 0, 0);
                    }
                } elseif ($h < $floorY) {
                    for ($y = $h + 1; $y <= $floorY; $y++) {
                        $set($x, $y, $z, 1, 0);   // 低处垫石头
                    }
                }
                $set($x, $floorY, $z, self::pathBlock($biome), 0);
            }
        }

        // 水井：中间 4x4 的石圈 + 水
        for ($z = 6; $z <= 9; $z++) {
            for ($x = 6; $x <= 9; $x++) {
                $edge = ($x === 6 || $x === 9 || $z === 6 || $z === 9);
                if ($edge) {
                    $set($x, $floorY + 1, $z, self::wallBlock($biome), 0);
                } else {
                    $set($x, $floorY, $z, 9, 0);        // 水
                    $set($x, $floorY - 1, $z, self::wallBlock($biome), 0);
                }
            }
        }

        // 四角各盖一栋，随机跳过一两栋，房型也随机——避免整个村子一个样
        $rand = new JavaRandom(LongMath::add(LongMath::mul($cx, 341873128712), $cz));
        $spots = [[1, 1], [10, 1], [1, 10], [10, 10]];
        foreach ($spots as $i => [$hx, $hz]) {
            if ($rand->nextIntBound(4) === 0) {
                continue;   // 这栋不盖
            }
            $kind = $rand->nextIntBound(3);
            if ($kind === 0) {
                self::house($hx, $hz, $floorY, $biome, $set, $get);
            } elseif ($kind === 1) {
                self::bigHouse($hx, $hz, $floorY, $biome, $set, $get);
            } else {
                self::farm($hx, $hz, $floorY, $biome, $set, $get);
            }
        }
    }

    /** 5x5 的小屋：墙 + 平顶 + 门口 */
    private static function house(int $x0, int $z0, int $floorY, int $biome, callable $set, callable $get): void
    {
        $wall = self::wallBlock($biome);
        $roof = self::roofBlock($biome);
        $glass = 20;   // 玻璃

        for ($dz = 0; $dz < 5; $dz++) {
            for ($dx = 0; $dx < 5; $dx++) {
                $x = $x0 + $dx;
                $z = $z0 + $dz;
                if ($x < 0 || $x > 15 || $z < 0 || $z > 15) {
                    continue;
                }
                $edge = ($dx === 0 || $dx === 4 || $dz === 0 || $dz === 4);
                $set($x, $floorY, $z, self::pathBlock($biome), 0);   // 地板

                if (!$edge) {
                    continue;
                }
                // 墙 3 高，中间留窗
                for ($dy = 1; $dy <= 3; $dy++) {
                    $isWindow = ($dy === 2) && (($dx === 0 || $dx === 4) && $dz === 2 || ($dz === 0 || $dz === 4) && $dx === 2);
                    $set($x, $floorY + $dy, $z, $isWindow ? $glass : $wall, 0);
                }
                $set($x, $floorY + 4, $z, $roof, 0);                 // 平顶
            }
        }

        // 正门：朝 +z 那面中间挖空 1x2
        $doorX = $x0 + 2;
        $doorZ = $z0 + 4;
        if ($doorX >= 0 && $doorX <= 15 && $doorZ >= 0 && $doorZ <= 15) {
            $set($doorX, $floorY + 1, $doorZ, 0, 0);
            $set($doorX, $floorY + 2, $doorZ, 0, 0);
            // 门口铺一格路
            if ($doorZ + 1 <= 15) {
                $set($doorX, $floorY, $doorZ + 1, self::pathBlock($biome), 0);
            }
        }
    }

    /** 7x5 的大屋：双开间、中间一道墙、屋顶铺两层木板 */
    private static function bigHouse(int $x0, int $z0, int $floorY, int $biome, callable $set, callable $get): void
    {
        $wall = self::wallBlock($biome);
        $roof = self::roofBlock($biome);
        $glass = 20;

        for ($dz = 0; $dz < 5; $dz++) {
            for ($dx = 0; $dx < 7; $dx++) {
                $x = $x0 + $dx;
                $z = $z0 + $dz;
                if ($x < 0 || $x > 15 || $z < 0 || $z > 15) {
                    continue;
                }
                $edge = ($dx === 0 || $dx === 6 || $dz === 0 || $dz === 4);
                $set($x, $floorY, $z, self::pathBlock($biome), 0);
                if (!$edge) {
                    // 中间一道隔墙（只有下半截，能走过去）
                    if ($dx === 3 && $dz !== 2) {
                        $set($x, $floorY + 1, $z, $wall, 0);
                        $set($x, $floorY + 2, $z, $wall, 0);
                    }
                    continue;
                }
                for ($dy = 1; $dy <= 3; $dy++) {
                    $isWindow = ($dy === 2) && (($dz === 0 || $dz === 4) && ($dx === 2 || $dx === 4));
                    $set($x, $floorY + $dy, $z, $isWindow ? $glass : $wall, 0);
                }
                $set($x, $floorY + 4, $z, $roof, 0);
            }
        }

        // 两个门：南面开两处
        $set($x0 + 2, $floorY + 1, $z0 + 4, 0, 0);
        $set($x0 + 2, $floorY + 2, $z0 + 4, 0, 0);
        $set($x0 + 4, $floorY + 1, $z0 + 4, 0, 0);
        $set($x0 + 4, $floorY + 2, $z0 + 4, 0, 0);
    }

    /** 农田：木栅栏围一圈 + 中间一格水 + 耕地和小麦 */
    private static function farm(int $x0, int $z0, int $floorY, int $biome, callable $set, callable $get): void
    {
        if ($biome === 2) {
            return;   // 沙漠不种地（原版沙漠村庄有农田，但这里简化掉）
        }
        for ($dz = 0; $dz < 5; $dz++) {
            for ($dx = 0; $dx < 5; $dx++) {
                $x = $x0 + $dx;
                $z = $z0 + $dz;
                if ($x < 0 || $x > 15 || $z < 0 || $z > 15) {
                    continue;
                }
                $edge = ($dx === 0 || $dx === 4 || $dz === 0 || $dz === 4);
                if ($edge) {
                    if (!($dx === 2 && $dz === 4)) {   // 留个入口
                        $set($x, $floorY + 1, $z, 85, 0);   // 木栅栏
                    }
                    $set($x, $floorY, $z, 13, 0);
                    continue;
                }
                if ($dx === 2 && $dz === 2) {
                    $set($x, $floorY, $z, 9, 0);            // 中间水源
                    continue;
                }
                $set($x, $floorY, $z, 60, 0);               // 耕地
                $set($x, $floorY + 1, $z, 59, 7);           // 小麦（成熟）
            }
        }
    }

    private static function wallBlock(int $biome): int
    {
        return match ($biome) {
            2 => 24,     // 沙漠：砂岩
            35 => 162,   // 热带草原：深色橡木（当框架用）
            default => 4, // 平原：圆石
        };
    }

    private static function roofBlock(int $biome): int
    {
        return match ($biome) {
            2 => 24,
            35 => 5,      // 橡木木板
            default => 5,
        };
    }

    private static function pathBlock(int $biome): int
    {
        return match ($biome) {
            2 => 24,      // 沙漠：砂岩当路
            35 => 13,     // 热带草原：砾石
            default => 13,
        };
    }
}
