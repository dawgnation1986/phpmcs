<?php
declare(strict_types=1);

namespace PhpMc\World\Layer;

/** 平滑：把参差的边界抹平 */
final class GenLayerSmooth extends GenLayer
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
                $k1 = $in[$j1 + ($i1 + 1) * $k];
                $l1 = $in[$j1 + 2 + ($i1 + 1) * $k];
                $i2 = $in[$j1 + 1 + $i1 * $k];
                $j2 = $in[$j1 + 1 + ($i1 + 2) * $k];
                $k2 = $in[$j1 + 1 + ($i1 + 1) * $k];

                if ($k1 === $l1 && $i2 === $j2) {
                    $this->initChunkSeed($j1 + $areaX, $i1 + $areaY);
                    $k2 = $this->nextInt(2) === 0 ? $k1 : $i2;
                } else {
                    if ($k1 === $l1) {
                        $k2 = $k1;
                    }
                    if ($i2 === $j2) {
                        $k2 = $i2;
                    }
                }
                $out[$j1 + $i1 * $areaWidth] = $k2;
            }
        }

        return $out;
    }
}

/** 河流初值：陆地上给一个 2~300000 的编号，同一流域编号相同 */
final class GenLayerRiverInit extends GenLayer
{
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
                $out[$j + $i * $areaWidth] = $in[$j + $i * $areaWidth] > 0
                    ? $this->nextInt(299999) + 2 : 0;
            }
        }

        return $out;
    }
}

/** 河流：四邻编号都不一致的地方才算河道 */
final class GenLayerRiver extends GenLayer
{
    public function __construct(int $seed, GenLayer $parent)
    {
        parent::__construct($seed);
        $this->parent = $parent;
    }

    private function f(int $v): int
    {
        return $v >= 2 ? 2 + ($v & 1) : $v;
    }

    public function getInts(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array
    {
        $k = $areaWidth + 2;
        $in = $this->parent->getInts($areaX - 1, $areaY - 1, $k, $areaHeight + 2);
        $out = IntCache::get($areaWidth * $areaHeight);

        for ($i1 = 0; $i1 < $areaHeight; $i1++) {
            for ($j1 = 0; $j1 < $areaWidth; $j1++) {
                $k1 = $this->f($in[$j1 + ($i1 + 1) * $k]);
                $l1 = $this->f($in[$j1 + 2 + ($i1 + 1) * $k]);
                $i2 = $this->f($in[$j1 + 1 + $i1 * $k]);
                $j2 = $this->f($in[$j1 + 1 + ($i1 + 2) * $k]);
                $k2 = $this->f($in[$j1 + 1 + ($i1 + 1) * $k]);

                if ($k2 === $k1 && $k2 === $i2 && $k2 === $l1 && $k2 === $j2) {
                    $out[$j1 + $i1 * $areaWidth] = -1;
                } else {
                    $out[$j1 + $i1 * $areaWidth] = BiomeId::RIVER;
                }
            }
        }

        return $out;
    }
}

/** 把河流叠加到群系上 */
final class GenLayerRiverMix extends GenLayer
{
    private ?GenLayer $biomeChain = null;
    private ?GenLayer $riverChain = null;

    public function __construct(int $seed, GenLayer $biomes, GenLayer $rivers)
    {
        parent::__construct($seed);
        $this->biomeChain = $biomes;
        $this->riverChain = $rivers;
    }

    public function initWorldGenSeed(int $seed): void
    {
        $this->biomeChain->initWorldGenSeed($seed);
        $this->riverChain->initWorldGenSeed($seed);
        parent::initWorldGenSeed($seed);
    }

    public function getInts(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array
    {
        $biomes = $this->biomeChain->getInts($areaX, $areaY, $areaWidth, $areaHeight);
        $rivers = $this->riverChain->getInts($areaX, $areaY, $areaWidth, $areaHeight);
        $out = IntCache::get($areaWidth * $areaHeight);
        $n = $areaWidth * $areaHeight;

        for ($i = 0; $i < $n; $i++) {
            $b = $biomes[$i];
            if ($b !== BiomeId::OCEAN && $b !== BiomeId::DEEP_OCEAN) {
                if ($rivers[$i] === BiomeId::RIVER) {
                    if ($b === BiomeId::ICE_PLAINS) {
                        $out[$i] = BiomeId::FROZEN_RIVER;
                    } elseif ($b !== BiomeId::MUSHROOM_ISLAND && $b !== BiomeId::MUSHROOM_ISLAND_SHORE) {
                        $out[$i] = $rivers[$i] & 255;
                    } else {
                        $out[$i] = BiomeId::MUSHROOM_ISLAND_SHORE;
                    }
                } else {
                    $out[$i] = $b;
                }
            } else {
                $out[$i] = $b;
            }
        }

        return $out;
    }
}

/** Voronoi 放大：每个 4x4 单元挑最近的格点，得到更自然的边界 */
final class GenLayerVoronoiZoom extends GenLayer
{
    public function __construct(int $seed, GenLayer $parent)
    {
        parent::__construct($seed);
        $this->parent = $parent;
    }

    public function getInts(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array
    {
        $areaX -= 2;
        $areaY -= 2;
        $i = $areaX >> 2;
        $j = $areaY >> 2;
        $k = ($areaWidth >> 2) + 2;
        $l = ($areaHeight >> 2) + 2;
        $in = $this->parent->getInts($i, $j, $k, $l);
        $i1 = ($k - 1) << 2;
        $j1 = ($l - 1) << 2;
        $buf = IntCache::get($i1 * $j1);

        for ($k1 = 0; $k1 < $l - 1; $k1++) {
            $l1 = 0;
            $i2 = $in[$l1 + ($k1) * $k];
            $j2 = $in[$l1 + ($k1 + 1) * $k];

            for (; $l1 < $k - 1; $l1++) {
                $this->initChunkSeed(($l1 + $i) << 2, ($k1 + $j) << 2);
                $d1 = ($this->nextInt(1024) / 1024.0 - 0.5) * 3.6;
                $d2 = ($this->nextInt(1024) / 1024.0 - 0.5) * 3.6;
                $this->initChunkSeed(($l1 + $i + 1) << 2, ($k1 + $j) << 2);
                $d3 = ($this->nextInt(1024) / 1024.0 - 0.5) * 3.6 + 4.0;
                $d4 = ($this->nextInt(1024) / 1024.0 - 0.5) * 3.6;
                $this->initChunkSeed(($l1 + $i) << 2, ($k1 + $j + 1) << 2);
                $d5 = ($this->nextInt(1024) / 1024.0 - 0.5) * 3.6;
                $d6 = ($this->nextInt(1024) / 1024.0 - 0.5) * 3.6 + 4.0;
                $this->initChunkSeed(($l1 + $i + 1) << 2, ($k1 + $j + 1) << 2);
                $d7 = ($this->nextInt(1024) / 1024.0 - 0.5) * 3.6 + 4.0;
                $d8 = ($this->nextInt(1024) / 1024.0 - 0.5) * 3.6 + 4.0;

                $k2 = $in[$l1 + 1 + ($k1) * $k] & 255;
                $l2 = $in[$l1 + 1 + ($k1 + 1) * $k] & 255;

                for ($i3 = 0; $i3 < 4; $i3++) {
                    $j3 = (($k1 << 2) + $i3) * $i1 + ($l1 << 2);
                    for ($k3 = 0; $k3 < 4; $k3++) {
                        $d9 = ($i3 - $d2) * ($i3 - $d2) + ($k3 - $d1) * ($k3 - $d1);
                        $d10 = ($i3 - $d4) * ($i3 - $d4) + ($k3 - $d3) * ($k3 - $d3);
                        $d11 = ($i3 - $d6) * ($i3 - $d6) + ($k3 - $d5) * ($k3 - $d5);
                        $d12 = ($i3 - $d8) * ($i3 - $d8) + ($k3 - $d7) * ($k3 - $d7);

                        if ($d9 < $d10 && $d9 < $d11 && $d9 < $d12) {
                            $buf[$j3++] = $i2;
                        } elseif ($d10 < $d9 && $d10 < $d11 && $d10 < $d12) {
                            $buf[$j3++] = $k2;
                        } elseif ($d11 < $d9 && $d11 < $d10 && $d11 < $d12) {
                            $buf[$j3++] = $j2;
                        } else {
                            $buf[$j3++] = $l2;
                        }
                    }
                }
                $i2 = $k2;
                $j2 = $l2;
            }
        }

        $out = IntCache::get($areaWidth * $areaHeight);
        for ($l3 = 0; $l3 < $areaHeight; $l3++) {
            $src = ($l3 + ($areaY & 3)) * $i1 + ($areaX & 3);
            for ($x = 0; $x < $areaWidth; $x++) {
                $out[$l3 * $areaWidth + $x] = $buf[$src + $x];
            }
        }

        return $out;
    }
}
