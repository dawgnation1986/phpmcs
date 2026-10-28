<?php
declare(strict_types=1);

namespace PhpMc\World\Layer;

/**
 * 群系层入口：按原版 initializeAllBiomeGenerators 建链。
 *
 * 验收标准（已达成）：同一个 seed，输出的 16x16 群系数组与真实 1.8 原版存档
 * 完全一致（拿 world_real 的 Biomes 数组比对，0/256 差异）。
 *
 * 两个必须显式喂种子的地方（原版靠 ChunkProviderGenerate 的调用顺序保证）：
 *   - g4（DeepOcean 输出，整条群系链的根）
 *   - l10（Hills 的第二个输入，它不在 parent 链上，initWorldGenSeed 传不到）
 * 漏掉任何一个，整片地形会退化成单一群系。
 */
final class BiomeLayerProvider
{
    private GenLayer $voronoi;

    public function __construct(int $seed)
    {
        $l = new GenLayerIsland(1);
        $l = new GenLayerFuzzyZoom(2000, $l);
        $l = new GenLayerAddIsland(1, $l);
        $l = new GenLayerZoom(2001, $l);
        $l = new GenLayerAddIsland(2, $l);
        $l = new GenLayerAddIsland(50, $l);
        $l = new GenLayerAddIsland(70, $l);
        $l = new GenLayerRemoveTooMuchOcean(2, $l);
        $l = new GenLayerAddSnow(2, $l);
        $l = new GenLayerAddIsland(3, $l);
        $l = new GenLayerEdge(2, $l, GenLayerEdge::COOL_WARM);
        $l = new GenLayerEdge(2, $l, GenLayerEdge::HEAT_ICE);
        $l = new GenLayerEdge(3, $l, GenLayerEdge::SPECIAL);
        $l = new GenLayerZoom(2002, $l);
        $l = new GenLayerZoom(2003, $l);
        $l = new GenLayerAddIsland(4, $l);
        $l = new GenLayerAddMushroomIsland(5, $l);
        $deep = new GenLayerDeepOcean(4, $l);

        // biomeSize = 4、riverSize = 4（原版默认）
        $g4 = GenLayerZoom::magnify(1000, $deep, 0);
        $g4->initWorldGenSeed($seed);

        $riverInit = new GenLayerRiverInit(100, GenLayerZoom::magnify(1000, $g4, 0));
        $biome = new GenLayerBiome(200, $g4);
        $biomeEdge = new GenLayerBiomeEdge(1000, GenLayerZoom::magnify(1000, $biome, 2));

        $hillsVar = GenLayerZoom::magnify(1000, $riverInit, 2);
        $hillsVar->initWorldGenSeed($seed);
        $hills = new GenLayerHills(1000, $biomeEdge, $hillsVar);
        $hills->initWorldGenSeed($seed);

        $riv = GenLayerZoom::magnify(1000, $riverInit, 2);
        $riv = GenLayerZoom::magnify(1000, $riv, 4);
        $riverSmooth = new GenLayerSmooth(1000, new GenLayerRiver(1, $riv));

        $chain = new GenLayerRareBiome(1001, $hills);
        for ($k = 0; $k < 4; $k++) {
            $chain = new GenLayerZoom(1000 + $k, $chain);
            if ($k === 0) {
                $chain = new GenLayerAddIsland(3, $chain);
            }
            if ($k === 1) {
                $chain = new GenLayerShore(1000, $chain);
            }
        }
        $smooth = new GenLayerSmooth(1000, $chain);
        $mix = new GenLayerRiverMix(100, $smooth, $riverSmooth);

        $this->voronoi = new GenLayerVoronoiZoom(10, $mix);
        $this->voronoi->initWorldGenSeed($seed);
    }

    /** @return int[] 256 个群系 id，按 z*16+x 排列 */
    public function chunkBiomes(int $chunkX, int $chunkZ): array
    {
        return $this->voronoi->getInts($chunkX * 16, $chunkZ * 16, 16, 16);
    }
}
