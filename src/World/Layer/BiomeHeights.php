<?php
declare(strict_types=1);

namespace PhpMc\World\Layer;

/**
 * 每个群系的「基准高度 / 起伏幅度」。
 *
 * 数据来源：MCP 的 BiomeGenBase.java（各群系的 setHeight(height_X) 调用）
 * 和 BiomeGenPlains / BiomeGenMesa 等子类构造里的赋值。
 *
 * 用途：原版 initializeNoiseField 会对 5x5 邻域内的群系做加权平均，
 * 权重来自这里的 minHeight / maxHeight。猜错一个群系，那片地形就会整体
 * 偏高或偏低，所以在拿到全部源码之前宁可留空（用默认值）。
 */
final class BiomeHeights
{
    /** 原版 BiomeGenBase.height_Default */
    public const DEFAULT_ROOT = 0.1;
    public const DEFAULT_VARIATION = 0.2;

    /** 高度预设：名字 => [rootHeight, variation] */
    private const PRESETS = [
        'Oceans' => [-1.0, 0.1],
        'DeepOceans' => [-1.8, 0.1],
        'ShallowWaters' => [-0.5, 0.0],
        'LowPlains' => [0.125, 0.05],
        'MidPlains' => [0.2, 0.2],
        'LowHills' => [0.45, 0.3],
        'MidHills' => [1.0, 0.5],
        'HighPlateaus' => [1.5, 0.025],
        'Shores' => [0.0, 0.025],
        'RockyWaters' => [0.1, 0.8],
        'LowIslands' => [0.2, 0.3],
        'PartiallySubmerged' => [-0.2, 0.1],
    ];

    /** biomeID => 预设名（原版 BiomeGenBase 里显式 setHeight 的那些） */
    private const ASSIGN = [
        0 => 'Oceans',
        1 => 'LowPlains',          // BiomeGenPlains 构造函数
        2 => 'LowPlains',
        3 => 'MidHills',
        // 4 森林：BiomeGenForest 只调 super(id)，用 height_Default = (0.1, 0.2)
        // 之前错写成 MidPlains(0.2, 0.2)，基准高度多一倍，地形整体偏高
        5 => 'MidPlains',
        6 => 'PartiallySubmerged',
        7 => 'ShallowWaters',
        10 => 'Oceans',
        11 => 'ShallowWaters',
        12 => 'LowPlains',
        13 => 'LowHills',
        14 => 'LowIslands',
        15 => 'Shores',
        16 => 'Shores',
        17 => 'LowHills',
        18 => 'LowHills',
        19 => 'LowHills',
        20 => 'MidHills',
        22 => 'LowHills',
        24 => 'DeepOceans',
        25 => 'RockyWaters',
        26 => 'Shores',
        28 => 'LowHills',
        30 => 'MidPlains',
        31 => 'LowHills',
        32 => 'MidPlains',
        33 => 'LowHills',
        34 => 'MidHills',
        35 => 'LowPlains',
        36 => 'HighPlateaus',
        37 => 'LowHills',          // BiomeGenMesa 里 setHeight(height_LowHills)
        38 => 'HighPlateaus',
        39 => 'HighPlateaus',
    ];

    /** @return array{0:float,1:float} [rootHeight, variation] */
    public static function of(int $biomeId): array
    {
        $name = self::ASSIGN[$biomeId] ?? null;
        if ($name === null) {
            return [self::DEFAULT_ROOT, self::DEFAULT_VARIATION];
        }

        return self::PRESETS[$name];
    }

    public static function rootHeight(int $biomeId): float
    {
        return self::of($biomeId)[0];
    }

    public static function variation(int $biomeId): float
    {
        return self::of($biomeId)[1];
    }
}
