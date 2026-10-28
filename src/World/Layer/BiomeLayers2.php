<?php
declare(strict_types=1);

namespace PhpMc\World\Layer;

/** 群系 id 常量（原版 BiomeGenBase 的 biomeID） */
final class BiomeId
{
    public const OCEAN = 0;
    public const PLAINS = 1;
    public const DESERT = 2;
    public const EXTREME_HILLS = 3;
    public const FOREST = 4;
    public const TAIGA = 5;
    public const SWAMPLAND = 6;
    public const HELL = 8;
    public const SKY = 9;
    public const FROZEN_OCEAN = 10;
    public const ICE_PLAINS = 12;
    public const ICE_MOUNTAINS = 13;
    public const DESERT_HILLS = 17;
    public const FOREST_HILLS = 18;
    public const TAIGA_HILLS = 19;
    public const JUNGLE_HILLS = 22;
    public const BIRCH_FOREST_HILLS = 28;
    public const COLD_TAIGA_HILLS = 31;
    public const MEGA_TAIGA_HILLS = 33;
    public const SAVANNA_PLATEAU = 36;
    public const COLD_BEACH = 26;
    public const BEACH = 16;
    public const STONE_BEACH = 25;
    public const MUSHROOM_ISLAND = 14;
    public const MUSHROOM_ISLAND_SHORE = 15;
    public const RIVER = 7;
    public const FROZEN_RIVER = 11;
    public const EXTREME_HILLS_EDGE = 20;
    public const JUNGLE = 21;
    public const JUNGLE_EDGE = 23;
    public const DEEP_OCEAN = 24;
    public const BIRCH_FOREST = 27;
    public const ROOFED_FOREST = 29;
    public const COLD_TAIGA = 30;
    public const MEGA_TAIGA = 32;
    public const EXTREME_HILLS_PLUS = 34;
    public const SAVANNA = 35;
    public const MESA = 37;
    public const MESA_PLATEAU = 38;
    public const MESA_PLATEAU_F = 39;
}

/** 冷暖/寒热边界：把相邻的两类气候之间插一条过渡带 */
final class GenLayerEdge extends GenLayer
{
    public const COOL_WARM = 0;
    public const HEAT_ICE = 1;
    public const SPECIAL = 2;

    private int $mode;

    public function __construct(int $seed, GenLayer $parent, int $mode)
    {
        parent::__construct($seed);
        $this->parent = $parent;
        $this->mode = $mode;
    }

    public function getInts(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array
    {
        if ($this->mode === self::HEAT_ICE) {
            return $this->heatIce($areaX, $areaY, $areaWidth, $areaHeight);
        }
        if ($this->mode === self::SPECIAL) {
            return $this->special($areaX, $areaY, $areaWidth, $areaHeight);
        }

        return $this->coolWarm($areaX, $areaY, $areaWidth, $areaHeight);
    }

    private function coolWarm(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array
    {
        $k = $areaWidth + 2;
        $in = $this->parent->getInts($areaX - 1, $areaY - 1, $k, $areaHeight + 2);
        $out = IntCache::get($areaWidth * $areaHeight);

        for ($i1 = 0; $i1 < $areaHeight; $i1++) {
            for ($j1 = 0; $j1 < $areaWidth; $j1++) {
                $this->initChunkSeed($j1 + $areaX, $i1 + $areaY);
                $k1 = $in[$j1 + 1 + ($i1 + 1) * $k];
                if ($k1 === 1) {
                    $l1 = $in[$j1 + 1 + $i1 * $k];
                    $i2 = $in[$j1 + 2 + ($i1 + 1) * $k];
                    $j2 = $in[$j1 + ($i1 + 1) * $k];
                    $k2 = $in[$j1 + 1 + ($i1 + 2) * $k];
                    if ($l1 === 3 || $i2 === 3 || $j2 === 3 || $k2 === 3
                        || $l1 === 4 || $i2 === 4 || $j2 === 4 || $k2 === 4) {
                        $k1 = 2;
                    }
                }
                $out[$j1 + $i1 * $areaWidth] = $k1;
            }
        }

        return $out;
    }

    private function heatIce(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array
    {
        $k = $areaWidth + 2;
        $in = $this->parent->getInts($areaX - 1, $areaY - 1, $k, $areaHeight + 2);
        $out = IntCache::get($areaWidth * $areaHeight);

        for ($i1 = 0; $i1 < $areaHeight; $i1++) {
            for ($j1 = 0; $j1 < $areaWidth; $j1++) {
                $k1 = $in[$j1 + 1 + ($i1 + 1) * $k];
                if ($k1 === 4) {
                    $l1 = $in[$j1 + 1 + $i1 * $k];
                    $i2 = $in[$j1 + 2 + ($i1 + 1) * $k];
                    $j2 = $in[$j1 + ($i1 + 1) * $k];
                    $k2 = $in[$j1 + 1 + ($i1 + 2) * $k];
                    if ($l1 === 2 || $i2 === 2 || $j2 === 2 || $k2 === 2
                        || $l1 === 1 || $i2 === 1 || $j2 === 1 || $k2 === 1) {
                        $k1 = 3;
                    }
                }
                $out[$j1 + $i1 * $areaWidth] = $k1;
            }
        }

        return $out;
    }

    private function special(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array
    {
        $in = $this->parent->getInts($areaX, $areaY, $areaWidth, $areaHeight);
        $out = IntCache::get($areaWidth * $areaHeight);

        for ($i = 0; $i < $areaHeight; $i++) {
            for ($j = 0; $j < $areaWidth; $j++) {
                $this->initChunkSeed($j + $areaX, $i + $areaY);
                $v = $in[$j + $i * $areaWidth];
                if ($v !== 0 && $this->nextInt(13) === 0) {
                    $v |= ((1 + $this->nextInt(15)) << 8) & 3840;
                }
                $out[$j + $i * $areaWidth] = $v;
            }
        }

        return $out;
    }
}

/** 蘑菇岛：全是海的 5 邻域里有 1% 概率变蘑菇岛 */
final class GenLayerAddMushroomIsland extends GenLayer
{
    public function __construct(int $seed, GenLayer $parent)
    {
        parent::__construct($seed);
        $this->parent = $parent;
    }

    public function getInts(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array
    {
        $k = $areaWidth + 2;
        $in = $this->parent->getInts($areaX - 1, $areaY - 1, $k, $areaHeight + 2);
        $out = IntCache::get($areaWidth * $areaHeight);

        for ($i1 = 0; $i1 < $areaHeight; $i1++) {
            for ($j1 = 0; $j1 < $areaWidth; $j1++) {
                $k1 = $in[$j1 + $i1 * $k];
                $l1 = $in[$j1 + 2 + $i1 * $k];
                $i2 = $in[$j1 + ($i1 + 2) * $k];
                $j2 = $in[$j1 + 2 + ($i1 + 2) * $k];
                $k2 = $in[$j1 + 1 + ($i1 + 1) * $k];
                $this->initChunkSeed($j1 + $areaX, $i1 + $areaY);
                if ($k2 === 0 && $k1 === 0 && $l1 === 0 && $i2 === 0 && $j2 === 0 && $this->nextInt(100) === 0) {
                    $out[$j1 + $i1 * $areaWidth] = BiomeId::MUSHROOM_ISLAND;
                } else {
                    $out[$j1 + $i1 * $areaWidth] = $k2;
                }
            }
        }

        return $out;
    }
}

/** 深海：四周都是海的地方变成深海 */
final class GenLayerDeepOcean extends GenLayer
{
    public function __construct(int $seed, GenLayer $parent)
    {
        parent::__construct($seed);
        $this->parent = $parent;
    }

    public function getInts(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array
    {
        $k = $areaWidth + 2;
        $in = $this->parent->getInts($areaX - 1, $areaY - 1, $k, $areaHeight + 2);
        $out = IntCache::get($areaWidth * $areaHeight);

        for ($i1 = 0; $i1 < $areaHeight; $i1++) {
            for ($j1 = 0; $j1 < $areaWidth; $j1++) {
                $k1 = $in[$j1 + 1 + $i1 * $k];
                $l1 = $in[$j1 + 2 + ($i1 + 1) * $k];
                $i2 = $in[$j1 + ($i1 + 1) * $k];
                $j2 = $in[$j1 + 1 + ($i1 + 2) * $k];
                $k2 = $in[$j1 + 1 + ($i1 + 1) * $k];
                $l2 = 0;
                if ($k1 === 0) {
                    $l2++;
                }
                if ($l1 === 0) {
                    $l2++;
                }
                if ($i2 === 0) {
                    $l2++;
                }
                if ($j2 === 0) {
                    $l2++;
                }
                if ($k2 === 0 && $l2 >= 4) {
                    $k2 = BiomeId::DEEP_OCEAN;
                }
                $out[$j1 + $i1 * $areaWidth] = $k2;
            }
        }

        return $out;
    }
}

/** 按温度/湿度（层里的 1~4）挑具体群系 */
final class GenLayerBiome extends GenLayer
{
    private const WARM = [
        [BiomeId::DESERT, BiomeId::DESERT, BiomeId::DESERT, BiomeId::SAVANNA, BiomeId::SAVANNA, BiomeId::PLAINS],
        [BiomeId::FOREST, BiomeId::ROOFED_FOREST, BiomeId::EXTREME_HILLS, BiomeId::PLAINS, BiomeId::BIRCH_FOREST, BiomeId::SWAMPLAND],
        [BiomeId::FOREST, BiomeId::EXTREME_HILLS, BiomeId::TAIGA, BiomeId::PLAINS],
        [BiomeId::ICE_PLAINS, BiomeId::ICE_PLAINS, BiomeId::ICE_PLAINS, BiomeId::COLD_TAIGA],
    ];

    public function __construct(int $seed, GenLayer $parent)
    {
        parent::__construct($seed);
        $this->parent = $parent;
    }

    public function getInts(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array
    {
        $in = $this->parent->getInts($areaX, $areaY, $areaWidth, $areaHeight);
        $out = IntCache::get($areaWidth * $areaHeight);

        for ($i = 0; $i < $areaHeight; $i++) {
            for ($j = 0; $j < $areaWidth; $j++) {
                $this->initChunkSeed($j + $areaX, $i + $areaY);
                $k = $in[$j + $i * $areaWidth];
                $special = ($k & 3840) >> 8;
                $k &= ~3840;

                if ($k === BiomeId::OCEAN || $k === BiomeId::DEEP_OCEAN || $k === BiomeId::FROZEN_OCEAN
                    || $k === BiomeId::MUSHROOM_ISLAND) {
                    $out[$j + $i * $areaWidth] = $k;
                } elseif ($k === 1) {
                    if ($special > 0) {
                        $out[$j + $i * $areaWidth] = $this->nextInt(3) === 0
                            ? BiomeId::MESA_PLATEAU : BiomeId::MESA_PLATEAU_F;
                    } else {
                        $arr = self::WARM[0];
                        $out[$j + $i * $areaWidth] = $arr[$this->nextInt(count($arr))];
                    }
                } elseif ($k === 2) {
                    if ($special > 0) {
                        $out[$j + $i * $areaWidth] = BiomeId::JUNGLE;
                    } else {
                        $arr = self::WARM[1];
                        $out[$j + $i * $areaWidth] = $arr[$this->nextInt(count($arr))];
                    }
                } elseif ($k === 3) {
                    if ($special > 0) {
                        $out[$j + $i * $areaWidth] = BiomeId::MEGA_TAIGA;
                    } else {
                        $arr = self::WARM[2];
                        $out[$j + $i * $areaWidth] = $arr[$this->nextInt(count($arr))];
                    }
                } elseif ($k === 4) {
                    $arr = self::WARM[3];
                    $out[$j + $i * $areaWidth] = $arr[$this->nextInt(count($arr))];
                } else {
                    $out[$j + $i * $areaWidth] = BiomeId::MUSHROOM_ISLAND;
                }
            }
        }

        return $out;
    }
}
