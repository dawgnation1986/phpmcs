<?php
declare(strict_types=1);

namespace PhpMc\World\Layer;

/**
 * 群系温度表 + 原版的两个比较函数。
 *
 * 原版 getTempCategory()：
 *   temperature < 0.2 → COLD，< 1.0 → MEDIUM，否则 WARM
 * 数值全部取自 MCP 源码 BiomeGenBase 里各群系的 setTemperatureRainfall。
 */
final class BiomeTable
{
    public const COLD = 0;
    public const MEDIUM = 1;
    public const WARM = 2;

    /** biomeID => 温度（源码里的 setTemperatureRainfall 第一个参数） */
    private const TEMP = [
        0 => 0.5,    // ocean
        1 => 0.8,    // plains
        2 => 2.0,    // desert
        3 => 0.2,    // extreme hills
        4 => 0.7,    // forest
        5 => 0.25,   // taiga
        6 => 0.8,    // swampland
        7 => 0.5,    // river
        8 => 2.0,    // hell
        9 => 0.5,    // sky
        10 => 0.0,   // frozen ocean
        11 => 0.0,   // frozen river
        12 => 0.0,   // ice plains
        13 => 0.0,   // ice mountains
        14 => 0.9,   // mushroom island
        15 => 0.9,   // mushroom island shore
        16 => 0.8,   // beach
        17 => 2.0,   // desert hills
        18 => 0.7,   // forest hills
        19 => 0.25,  // taiga hills
        20 => 0.2,   // extreme hills edge
        21 => 0.95,  // jungle
        22 => 0.95,  // jungle hills
        23 => 0.95,  // jungle edge
        24 => 0.5,   // deep ocean
        25 => 0.2,   // stone beach
        26 => 0.05,  // cold beach
        27 => 0.6,   // birch forest
        28 => 0.6,   // birch forest hills
        29 => 0.7,   // roofed forest
        30 => -0.5,  // cold taiga
        31 => -0.5,  // cold taiga hills
        32 => 0.3,   // mega taiga
        33 => 0.3,   // mega taiga hills
        34 => 0.2,   // extreme hills+
        35 => 1.2,   // savanna
        36 => 1.0,   // savanna plateau
        37 => 2.0,   // mesa
        38 => 2.0,   // mesa plateau
        39 => 2.0,   // mesa plateau F
        129 => 0.8,  // sunflower plains
        130 => 0.7,  // flower forest
    ];

    public static function tempCategory(int $biomeId): int
    {
        $t = self::TEMP[$biomeId] ?? 0.5;
        if ($t < 0.2) {
            return self::COLD;
        }

        return $t < 1.0 ? self::MEDIUM : self::WARM;
    }

    /**
     * 原版 biomeEqualOrMesaPlateau： Mesa 的两个高原变体视为同族，
     * 其余按「同类且同 id」比较 —— 群系 id 唯一，所以等价于 id 相同。
     */
    public static function equalOrMesaPlateau(int $a, int $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a !== BiomeId::MESA_PLATEAU_F && $a !== BiomeId::MESA_PLATEAU) {
            return false;
        }

        return $b === BiomeId::MESA_PLATEAU_F || $b === BiomeId::MESA_PLATEAU;
    }


    /** 海洋：普通海 / 深海 / 冰海 */
    public static function isOceanic(int $id): bool
    {
        return $id === BiomeId::OCEAN || $id === BiomeId::DEEP_OCEAN || $id === BiomeId::FROZEN_OCEAN;
    }

    /** 雪原类（原版 enableSnow 的那几个） */
    public static function isSnowy(int $id): bool
    {
        return in_array($id, [10, 11, 12, 13, 26, 30, 31, 140], true);
    }

    /** 丛林类（BiomeGenJungle） */
    public static function isJungle(int $id): bool
    {
        return $id === BiomeId::JUNGLE || $id === 22 || $id === BiomeId::JUNGLE_EDGE;
    }

    /** 台地类（BiomeGenMesa） */
    public static function isMesa(int $id): bool
    {
        return $id === BiomeId::MESA || $id === BiomeId::MESA_PLATEAU || $id === BiomeId::MESA_PLATEAU_F;
    }

    /** 原版 func_151631_c：丛林边缘允许的邻居 */
    public static function jungleEdgeOk(int $id): bool
    {
        return self::isJungle($id)
            || $id === BiomeId::FOREST
            || $id === BiomeId::TAIGA
            || self::isOceanic($id);
    }

    /** 原版 canBiomesBeNeighbors：同类或任一方是温带，就能相邻 */
    public static function canBeNeighbors(int $a, int $b): bool
    {
        if (self::equalOrMesaPlateau($a, $b)) {
            return true;
        }
        $ta = self::tempCategory($a);
        $tb = self::tempCategory($b);

        return $ta === $tb || $ta === self::MEDIUM || $tb === self::MEDIUM;
    }
}

/** 群系边界：把突兀的相邻换成过渡群系（山地边缘 / 台地 / 丛林边缘…） */
final class GenLayerBiomeEdge extends GenLayer
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

                if ($this->edgeIfNecessary($in, $out, $j, $i, $areaWidth, $k,
                        BiomeId::EXTREME_HILLS, BiomeId::EXTREME_HILLS_EDGE)
                    || $this->edge($in, $out, $j, $i, $areaWidth, $k,
                        BiomeId::MESA_PLATEAU_F, BiomeId::MESA)
                    || $this->edge($in, $out, $j, $i, $areaWidth, $k,
                        BiomeId::MESA_PLATEAU, BiomeId::MESA)
                    || $this->edge($in, $out, $j, $i, $areaWidth, $k,
                        BiomeId::MEGA_TAIGA, BiomeId::TAIGA)) {
                    continue;
                }

                if ($k === BiomeId::DESERT) {
                    $l1 = $in[$j + 1 + $i * $w];
                    $i2 = $in[$j + 2 + ($i + 1) * $w];
                    $j2 = $in[$j + ($i + 1) * $w];
                    $k2 = $in[$j + 1 + ($i + 2) * $w];
                    if ($l1 !== BiomeId::ICE_PLAINS && $i2 !== BiomeId::ICE_PLAINS
                        && $j2 !== BiomeId::ICE_PLAINS && $k2 !== BiomeId::ICE_PLAINS) {
                        $out[$j + $i * $areaWidth] = $k;
                    } else {
                        $out[$j + $i * $areaWidth] = BiomeId::EXTREME_HILLS_PLUS;
                    }
                } elseif ($k === BiomeId::SWAMPLAND) {
                    $l = $in[$j + 1 + $i * $w];
                    $i1 = $in[$j + 2 + ($i + 1) * $w];
                    $j1 = $in[$j + ($i + 1) * $w];
                    $k1 = $in[$j + 1 + ($i + 2) * $w];
                    $bad = ($l === BiomeId::DESERT || $i1 === BiomeId::DESERT || $j1 === BiomeId::DESERT || $k1 === BiomeId::DESERT
                        || $l === BiomeId::COLD_TAIGA || $i1 === BiomeId::COLD_TAIGA || $j1 === BiomeId::COLD_TAIGA || $k1 === BiomeId::COLD_TAIGA
                        || $l === BiomeId::ICE_PLAINS || $i1 === BiomeId::ICE_PLAINS || $j1 === BiomeId::ICE_PLAINS || $k1 === BiomeId::ICE_PLAINS);
                    if ($bad) {
                        $out[$j + $i * $areaWidth] = BiomeId::PLAINS;
                    } else {
                        $jungle = ($l === BiomeId::JUNGLE || $k1 === BiomeId::JUNGLE
                            || $i1 === BiomeId::JUNGLE || $j1 === BiomeId::JUNGLE);
                        $out[$j + $i * $areaWidth] = $jungle ? BiomeId::JUNGLE_EDGE : $k;
                    }
                } else {
                    $out[$j + $i * $areaWidth] = $k;
                }
            }
        }

        return $out;
    }

    private function edgeIfNecessary(array $in, array &$out, int $j, int $i, int $w, int $id, int $self, int $repl): bool
    {
        if (!BiomeTable::equalOrMesaPlateau($id, $self)) {
            return false;
        }
        $n = [];
        $n[] = $in[$j + 1 + $i * ($w + 2)];
        $n[] = $in[$j + 2 + ($i + 1) * ($w + 2)];
        $n[] = $in[$j + ($i + 1) * ($w + 2)];
        $n[] = $in[$j + 1 + ($i + 2) * ($w + 2)];
        foreach ($n as $v) {
            if (!BiomeTable::canBeNeighbors($v, $self)) {
                $out[$j + $i * $w] = $repl;

                return true;
            }
        }
        $out[$j + $i * $w] = $id;

        return true;
    }

    private function edge(array $in, array &$out, int $j, int $i, int $w, int $id, int $self, int $repl): bool
    {
        if ($id !== $self) {
            return false;
        }
        $n = [];
        $n[] = $in[$j + 1 + $i * ($w + 2)];
        $n[] = $in[$j + 2 + ($i + 1) * ($w + 2)];
        $n[] = $in[$j + ($i + 1) * ($w + 2)];
        $n[] = $in[$j + 1 + ($i + 2) * ($w + 2)];
        foreach ($n as $v) {
            if (!BiomeTable::equalOrMesaPlateau($v, $self)) {
                $out[$j + $i * $w] = $repl;

                return true;
            }
        }
        $out[$j + $i * $w] = $id;

        return true;
    }
}
