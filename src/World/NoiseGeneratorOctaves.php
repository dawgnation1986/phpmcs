<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 原版 NoiseGeneratorOctaves 的移植：把 N 个 Improved 叠加，
 * 每层频率减半（d3 /= 2），用来做地形密度场。
 */
final class NoiseGeneratorOctaves
{
    /** @var NoiseGeneratorImproved[] */
    private array $generators = [];
    private int $octaves;

    public function __construct(JavaRandom $rand, int $octaves)
    {
        $this->octaves = $octaves;
        for ($i = 0; $i < $octaves; $i++) {
            $this->generators[] = new NoiseGeneratorImproved($rand);
        }
    }

    /**
     * @param float[]|null $noiseArray
     * @return float[]
     */
    public function generateNoiseOctaves(
        ?array $noiseArray,
        int $xOffset, int $yOffset, int $zOffset,
        int $xSize, int $ySize, int $zSize,
        float $xScale, float $yScale, float $zScale
    ): array {
        $n = $xSize * $ySize * $zSize;
        if ($noiseArray === null) {
            $noiseArray = array_fill(0, $n, 0.0);
        } else {
            for ($i = 0; $i < count($noiseArray); $i++) {
                $noiseArray[$i] = 0.0;
            }
        }

        $d3 = 1.0;
        for ($j = 0; $j < $this->octaves; $j++) {
            $d0 = (float) $xOffset * $d3 * $xScale;
            $d1 = (float) $yOffset * $d3 * $yScale;
            $d2 = (float) $zOffset * $d3 * $zScale;

            $k = (int) floor($d0);
            $l = (int) floor($d2);
            $d0 -= (float) $k;
            $d2 -= (float) $l;
            $k %= 16777216;
            $l %= 16777216;
            $d0 += (float) $k;
            $d2 += (float) $l;

            $this->generators[$j]->populateNoiseArray(
                $noiseArray,
                $d0, $d1, $d2,
                $xSize, $ySize, $zSize,
                $xScale * $d3, $yScale * $d3, $zScale * $d3,
                $d3
            );
            $d3 /= 2.0;
        }

        return $noiseArray;
    }
}
