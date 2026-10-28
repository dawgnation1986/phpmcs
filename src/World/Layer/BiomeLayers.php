<?php
declare(strict_types=1);

namespace PhpMc\World\Layer;

use PhpMc\World\LongMath;

/** 原版 IntCache 的等价实现：每次给一块新数组（各层都是整块写完再读） */
final class IntCache
{
    public static function get(int $size): array
    {
        return array_fill(0, $size, 0);
    }
}

/** 原版 GenLayer 基类：LCG + 按区块派生种子 + 随机挑选 */
abstract class GenLayer
{
    protected int $baseSeed = 0;
    protected int $worldGenSeed = 0;
    protected int $chunkSeed = 0;
    protected ?GenLayer $parent = null;

    private static function lcg(int $s): int
    {
        return LongMath::add(LongMath::mul($s, 6364136223846793005), 1442695040888963407);
    }

    public function __construct(int $seed)
    {
        $this->baseSeed = $seed;
        for ($i = 0; $i < 3; $i++) {
            $this->baseSeed = LongMath::add(LongMath::mul($this->baseSeed, self::lcg($this->baseSeed)), $seed);
        }
    }

    public function initWorldGenSeed(int $seed): void
    {
        $this->worldGenSeed = $seed;
        if ($this->parent !== null) {
            $this->parent->initWorldGenSeed($seed);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->worldGenSeed = LongMath::add(LongMath::mul($this->worldGenSeed, self::lcg($this->worldGenSeed)), $this->baseSeed);
        }
    }

    protected function initChunkSeed(int $x, int $z): void
    {
        $this->chunkSeed = $this->worldGenSeed;
        $this->chunkSeed = LongMath::add(LongMath::mul($this->chunkSeed, self::lcg($this->chunkSeed)), $x);
        $this->chunkSeed = LongMath::add(LongMath::mul($this->chunkSeed, self::lcg($this->chunkSeed)), $z);
        $this->chunkSeed = LongMath::add(LongMath::mul($this->chunkSeed, self::lcg($this->chunkSeed)), $x);
        $this->chunkSeed = LongMath::add(LongMath::mul($this->chunkSeed, self::lcg($this->chunkSeed)), $z);
    }

    protected function nextInt(int $bound): int
    {
        // 原版：(int)((chunkSeed >> 24) % bound)
        // >> 是算术右移 = 向下取整，不是向零截断 —— 负数时两者不同，这里必须补
        $x = $this->chunkSeed;
        $q = intdiv($x, 16777216);
        if ($x < 0 && $x % 16777216 !== 0) {
            $q--;
        }
        $i = LongMath::mod($q, $bound);
        if ($i < 0) {
            $i += $bound;
        }
        $this->chunkSeed = LongMath::add(LongMath::mul($this->chunkSeed, self::lcg($this->chunkSeed)), $this->worldGenSeed);

        return $i;
    }

    protected function selectRandom(array $choices): int
    {
        return $choices[$this->nextInt(count($choices))];
    }

    protected function selectModeOrRandom(int $a, int $b, int $c, int $d): int
    {
        if ($b === $c && $c === $d) {
            return $b;
        }
        if (($a === $b && $a === $c) || ($a === $b && $a === $d) || ($a === $c && $a === $d)
            || ($a === $b && $c !== $d) || ($a === $c && $b !== $d) || ($a === $d && $b !== $c)) {
            return $a;
        }
        if (($b === $c && $a !== $d) || ($b === $d && $a !== $c)) {
            return $b;
        }
        if ($c === $d && $a !== $b) {
            return $c;
        }

        return $this->selectRandom([$a, $b, $c, $d]);
    }

    abstract public function getInts(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array;
}

final class GenLayerIsland extends GenLayer
{
    public function getInts(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array
    {
        $out = IntCache::get($areaWidth * $areaHeight);
        for ($i = 0; $i < $areaHeight; $i++) {
            for ($j = 0; $j < $areaWidth; $j++) {
                $this->initChunkSeed($areaX + $j, $areaY + $i);
                $out[$j + $i * $areaWidth] = $this->nextInt(10) === 0 ? 1 : 0;
            }
        }
        if ($areaX > -$areaWidth && $areaX <= 0 && $areaY > -$areaHeight && $areaY <= 0) {
            $out[-$areaX + -$areaY * $areaWidth] = 1;
        }

        return $out;
    }
}

class GenLayerZoom extends GenLayer
{
    public function __construct(int $seed, ?GenLayer $parent = null)
    {
        parent::__construct($seed);
        $this->parent = $parent;
    }

    public static function magnify(int $seed, GenLayer $layer, int $times): GenLayer
    {
        $l = $layer;
        for ($i = 0; $i < $times; $i++) {
            $l = new GenLayerZoom($seed + $i, $l);
        }

        return $l;
    }

    public function getInts(int $areaX, int $areaY, int $areaWidth, int $areaHeight): array
    {
        $i = $areaX >> 1;
        $j = $areaY >> 1;
        $k = ($areaWidth >> 1) + 2;
        $l = ($areaHeight >> 1) + 2;
        $in = $this->parent->getInts($i, $j, $k, $l);
        $i1 = ($k - 1) << 1;
        $j1 = ($l - 1) << 1;
        $buf = IntCache::get($i1 * $j1);

        for ($k1 = 0; $k1 < $l - 1; $k1++) {
            $l1 = ($k1 << 1) * $i1;
            $i2 = 0;
            $j2 = $in[$i2 + ($k1) * $k];
            $k2 = $in[$i2 + ($k1 + 1) * $k];
            for (; $i2 < $k - 1; $i2++) {
                $this->initChunkSeed(($i2 + $i) << 1, ($k1 + $j) << 1);
                $l2 = $in[$i2 + 1 + $k1 * $k];
                $i3 = $in[$i2 + 1 + ($k1 + 1) * $k];
                $buf[$l1] = $j2;
                $buf[$l1++ + $i1] = $this->selectRandom([$j2, $k2]);
                $buf[$l1] = $this->selectRandom([$j2, $l2]);
                $buf[$l1++ + $i1] = $this->selectModeOrRandom($j2, $l2, $k2, $i3);
                $j2 = $l2;
                $k2 = $i3;
            }
        }

        $out = IntCache::get($areaWidth * $areaHeight);
        for ($j3 = 0; $j3 < $areaHeight; $j3++) {
            $src = ($j3 + ($areaY & 1)) * $i1 + ($areaX & 1);
            for ($x = 0; $x < $areaWidth; $x++) {
                $out[$j3 * $areaWidth + $x] = $buf[$src + $x];
            }
        }

        return $out;
    }
}

final class GenLayerFuzzyZoom extends GenLayerZoom
{
    protected function selectModeOrRandom(int $a, int $b, int $c, int $d): int
    {
        return $this->selectRandom([$a, $b, $c, $d]);
    }
}

final class GenLayerAddIsland extends GenLayer
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

                if ($k2 !== 0 || ($k1 === 0 && $l1 === 0 && $i2 === 0 && $j2 === 0)) {
                    if ($k2 > 0 && ($k1 === 0 || $l1 === 0 || $i2 === 0 || $j2 === 0)) {
                        if ($this->nextInt(5) === 0) {
                            $out[$j1 + $i1 * $areaWidth] = $k2 === 4 ? 4 : 0;
                        } else {
                            $out[$j1 + $i1 * $areaWidth] = $k2;
                        }
                    } else {
                        $out[$j1 + $i1 * $areaWidth] = $k2;
                    }
                } else {
                    $l2 = 1;
                    $i3 = 1;
                    if ($k1 !== 0 && $this->nextInt($l2++) === 0) {
                        $i3 = $k1;
                    }
                    if ($l1 !== 0 && $this->nextInt($l2++) === 0) {
                        $i3 = $l1;
                    }
                    if ($i2 !== 0 && $this->nextInt($l2++) === 0) {
                        $i3 = $i2;
                    }
                    if ($j2 !== 0 && $this->nextInt($l2++) === 0) {
                        $i3 = $j2;
                    }
                    if ($this->nextInt(3) === 0) {
                        $out[$j1 + $i1 * $areaWidth] = $i3;
                    } elseif ($i3 === 4) {
                        $out[$j1 + $i1 * $areaWidth] = 4;
                    } else {
                        $out[$j1 + $i1 * $areaWidth] = 0;
                    }
                }
            }
        }

        return $out;
    }
}

final class GenLayerRemoveTooMuchOcean extends GenLayer
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
                $out[$j1 + $i1 * $areaWidth] = $k2;
                $this->initChunkSeed($j1 + $areaX, $i1 + $areaY);
                if ($k2 === 0 && $k1 === 0 && $l1 === 0 && $i2 === 0 && $j2 === 0 && $this->nextInt(2) === 0) {
                    $out[$j1 + $i1 * $areaWidth] = 1;
                }
            }
        }

        return $out;
    }
}

final class GenLayerAddSnow extends GenLayer
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
                $k1 = $in[$j1 + 1 + ($i1 + 1) * $k];
                $this->initChunkSeed($j1 + $areaX, $i1 + $areaY);
                if ($k1 === 0) {
                    $out[$j1 + $i1 * $areaWidth] = 0;
                } else {
                    $l1 = $this->nextInt(6);
                    $out[$j1 + $i1 * $areaWidth] = $l1 === 0 ? 4 : ($l1 <= 1 ? 3 : 1);
                }
            }
        }

        return $out;
    }
}
