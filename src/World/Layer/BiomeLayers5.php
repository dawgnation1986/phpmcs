<?php
declare(strict_types=1);

namespace PhpMc\World\Layer;

/** 稀有群系：57 分之一把平原换成向日葵平原（id + 128） */
final class GenLayerRareBiome extends GenLayer
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
                if ($this->nextInt(57) === 0 && $k === BiomeId::PLAINS) {
                    $k += 128;
                }
                $out[$j + $i * $areaWidth] = $k;
            }
        }

        return $out;
    }
}

/** 丘陵变体：按第二个输入层（河流初值层）的编号决定是否变成丘陵/高地 */
final class GenLayerHills extends GenLayer
{
    private ?GenLayer $variantLayer = null;

    public function __construct(int $seed, GenLayer $parent, GenLayer $variant)
    {
        parent::__construct($seed);
        $this->parent = $parent;
        $this->variantLayer = $variant;
    }

    public function getInts(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array
    {
        $w = $areaWidth + 2;
        $in = $this->parent->getInts($areaX - 1, $areaY - 1, $w, $areaHeight + 2);
        $var = $this->variantLayer->getInts($areaX - 1, $areaY - 1, $w, $areaHeight + 2);
        $out = IntCache::get($areaWidth * $areaHeight);

        for ($i = 0; $i < $areaHeight; $i++) {
            for ($j = 0; $j < $areaWidth; $j++) {
                $this->initChunkSeed($j + $areaX, $i + $areaY);
                $k = $in[$j + 1 + ($i + 1) * $w];
                $l = $var[$j + 1 + ($i + 1) * $w];
                $flag = ($l - 2) % 29 === 0;

                if ($k !== 0 && $l >= 2 && ($l - 2) % 29 === 1 && $k < 128) {
                    // 少见变体（+128：向日葵平原 / 花林 等），存在才用
                    $out[$j + $i * $areaWidth] = self::hasVariant($k + 128) ? $k + 128 : $k;
                    if (getenv('HILLS_DBG')) { echo "{$i},{$j}|{$k}|{$l}|{[($flag ? 1 : 0)]}|{$out[$j + $i * $areaWidth]}\n"; }
                    continue;
                }
                if ($this->nextInt(3) === 0 || $flag) {
                    $i1 = $k;
                    if ($k === BiomeId::DESERT) {
                        $i1 = BiomeId::DESERT_HILLS;
                    } elseif ($k === BiomeId::FOREST) {
                        $i1 = BiomeId::FOREST_HILLS;
                    } elseif ($k === BiomeId::BIRCH_FOREST) {
                        $i1 = BiomeId::BIRCH_FOREST_HILLS;
                    } elseif ($k === BiomeId::ROOFED_FOREST) {
                        $i1 = BiomeId::PLAINS;
                    } elseif ($k === BiomeId::TAIGA) {
                        $i1 = BiomeId::TAIGA_HILLS;
                    } elseif ($k === BiomeId::MEGA_TAIGA) {
                        $i1 = BiomeId::MEGA_TAIGA_HILLS;
                    } elseif ($k === BiomeId::COLD_TAIGA) {
                        $i1 = BiomeId::COLD_TAIGA_HILLS;
                    } elseif ($k === BiomeId::PLAINS) {
                        $i1 = $this->nextInt(3) === 0 ? BiomeId::FOREST_HILLS : BiomeId::FOREST;
                    } elseif ($k === BiomeId::ICE_PLAINS) {
                        $i1 = BiomeId::ICE_MOUNTAINS;
                    } elseif ($k === BiomeId::JUNGLE) {
                        $i1 = BiomeId::JUNGLE_HILLS;
                    } elseif ($k === BiomeId::OCEAN) {
                        $i1 = BiomeId::DEEP_OCEAN;
                    } elseif ($k === BiomeId::EXTREME_HILLS) {
                        $i1 = BiomeId::EXTREME_HILLS_PLUS;
                    } elseif ($k === BiomeId::SAVANNA) {
                        $i1 = BiomeId::SAVANNA_PLATEAU;
                    } elseif (BiomeTable::equalOrMesaPlateau($k, BiomeId::MESA_PLATEAU_F)) {
                        $i1 = BiomeId::MESA;
                    } elseif ($k === BiomeId::DEEP_OCEAN && $this->nextInt(3) === 0) {
                        $i1 = $this->nextInt(2) === 0 ? BiomeId::PLAINS : BiomeId::FOREST;
                    }

                    if ($flag && $i1 !== $k) {
                        $i1 = self::hasVariant($i1 + 128) ? $i1 + 128 : $k;
                    }

                    // 关键：丘陵不是无条件套用的。
                    // 原版会数四邻里有几个和 k 同族，不足 3 个就保持原群系，
                    // 免得出现孤零零一块丘陵。这一段我之前整个漏了。
                    if ($i1 === $k) {
                        $out[$j + $i * $areaWidth] = $k;
                    } else {
                        $n1 = $in[$j + 1 + $i * $w];
                        $n2 = $in[$j + 2 + ($i + 1) * $w];
                        $n3 = $in[$j + ($i + 1) * $w];
                        $n4 = $in[$j + 1 + ($i + 2) * $w];
                        $same = 0;
                        foreach ([$n1, $n2, $n3, $n4] as $nv) {
                            if (BiomeTable::equalOrMesaPlateau($nv, $k)) {
                                $same++;
                            }
                        }
                        $out[$j + $i * $areaWidth] = $same >= 3 ? $i1 : $k;
                    }
                } else {
                    $out[$j + $i * $areaWidth] = $k;
                }
                if (getenv('HILLS_DBG')) { echo "{$i},{$j}|{$k}|{$l}|{[($flag ? 1 : 0)]}|{$out[$j + $i * $areaWidth]}\n"; }
            }
        }

        return $out;
    }

    /** 原版 BiomeGenBase.getBiome(id + 128) != null 的等价判断 */
    private static function hasVariant(int $id): bool
    {
        return isset(self::VARIANTS[$id]);
    }

    /**
     * 原版注册过的「+128 变体」群系。
     *
     * 注意：1.8 的 BiomeGenBase 源码里没有直接注册这些（向日葵平原等
     * 走的是另外的注册路径），我还没把那部分搬过来，所以这张表**暂时留空**
     * —— 效果是 Hills 的「+128 变体」分支不触发，与目前的 Java 对照实现一致。
     * 等把变体群系注册补齐后再填进来，否则就是凭空猜 id。
     */
    private const VARIANTS = [];
}
