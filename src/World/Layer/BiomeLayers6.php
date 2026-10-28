<?php
declare(strict_types=1);

namespace PhpMc\World\Layer;

/**
 * 海岸：靠海的陆地变成沙滩，特殊情况用冷沙滩 / 石滩。
 *
 * 判断全部走 BiomeTable 的几个小函数，和原版 GenLayerShore 的
 * isBiomeOceanic / func_151631_c / func_151633_d 一一对应。
 */
final class GenLayerShore extends GenLayer
{
    public function __construct(int $seed, GenLayer $parent)
    {
        parent::__construct($seed);
        $this->parent = $parent;
    }

    public function getInts(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array
    {
        $w = $areaWidth + 2;
        $in = $this->parent->getInts($areaX - 1, $areaY - 1, $w, $areaHeight + 2);
        $out = IntCache::get($areaWidth * $areaHeight);

        for ($i = 0; $i < $areaHeight; $i++) {
            for ($j = 0; $j < $areaWidth; $j++) {
                $this->initChunkSeed($j + $areaX, $i + $areaY);
                $k = $in[$j + 1 + ($i + 1) * $w];

                $up = $in[$j + 1 + $i * $w];
                $right = $in[$j + 2 + ($i + 1) * $w];
                $left = $in[$j + ($i + 1) * $w];
                $down = $in[$j + 1 + ($i + 2) * $w];

                if ($k === BiomeId::MUSHROOM_ISLAND) {
                    $ocean = ($up === BiomeId::OCEAN || $right === BiomeId::OCEAN
                        || $left === BiomeId::OCEAN || $down === BiomeId::OCEAN);
                    $out[$j + $i * $areaWidth] = $ocean
                        ? BiomeId::MUSHROOM_ISLAND_SHORE : $k;
                } elseif (BiomeTable::isJungle($k)) {
                    $ok = BiomeTable::jungleEdgeOk($up) && BiomeTable::jungleEdgeOk($right)
                        && BiomeTable::jungleEdgeOk($left) && BiomeTable::jungleEdgeOk($down);
                    if (!$ok) {
                        $out[$j + $i * $areaWidth] = BiomeId::JUNGLE_EDGE;
                    } else {
                        $ocean = BiomeTable::isOceanic($up) || BiomeTable::isOceanic($right)
                            || BiomeTable::isOceanic($left) || BiomeTable::isOceanic($down);
                        $out[$j + $i * $areaWidth] = $ocean ? BiomeId::BEACH : $k;
                    }
                } elseif ($k !== BiomeId::EXTREME_HILLS && $k !== BiomeId::EXTREME_HILLS_PLUS
                    && $k !== BiomeId::EXTREME_HILLS_EDGE) {
                    if (BiomeTable::isSnowy($k)) {
                        $out[$j + $i * $areaWidth] = $this->beachOr($in, $j, $i, $w, $k, BiomeId::COLD_BEACH);
                    } elseif ($k !== BiomeId::MESA && $k !== BiomeId::MESA_PLATEAU_F) {
                        if ($k !== BiomeId::OCEAN && $k !== BiomeId::DEEP_OCEAN
                            && $k !== BiomeId::RIVER && $k !== BiomeId::SWAMPLAND) {
                            $ocean = BiomeTable::isOceanic($up) || BiomeTable::isOceanic($right)
                                || BiomeTable::isOceanic($left) || BiomeTable::isOceanic($down);
                            $out[$j + $i * $areaWidth] = $ocean ? BiomeId::BEACH : $k;
                        } else {
                            $out[$j + $i * $areaWidth] = $k;
                        }
                    } else {
                        // 台地：四邻都是台地才保持，否则沙漠
                        $allMesa = BiomeTable::isMesa($up) && BiomeTable::isMesa($right)
                            && BiomeTable::isMesa($left) && BiomeTable::isMesa($down);
                        $noOcean = !BiomeTable::isOceanic($up) && !BiomeTable::isOceanic($right)
                            && !BiomeTable::isOceanic($left) && !BiomeTable::isOceanic($down);
                        $out[$j + $i * $areaWidth] = ($noOcean && $allMesa) ? $k : BiomeId::DESERT;
                    }
                } else {
                    $out[$j + $i * $areaWidth] = $this->beachOr($in, $j, $i, $w, $k, BiomeId::STONE_BEACH);
                }
            }
        }

        return $out;
    }

    /** 原版 func_151632_a：自己靠海就保持，四邻都不靠海也保持，否则换成 $repl */
    private function beachOr(array $in, int $j, int $i, int $w, int $k, int $repl): int
    {
        if (BiomeTable::isOceanic($k)) {
            return $k;
        }
        $up = $in[$j + 1 + $i * $w];
        $right = $in[$j + 2 + ($i + 1) * $w];
        $left = $in[$j + ($i + 1) * $w];
        $down = $in[$j + 1 + ($i + 2) * $w];
        if (!BiomeTable::isOceanic($up) && !BiomeTable::isOceanic($right)
            && !BiomeTable::isOceanic($left) && !BiomeTable::isOceanic($down)) {
            return $k;
        }

        return $repl;
    }
}
