<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 各群系的表层方块（原版 BiomeGenBase 的 topBlock / fillerBlock）。
 *
 * 返回 [顶层 id, 顶层 meta, 填充层 id, 填充层 meta, 是否盖雪]
 */
final class SurfaceRules
{
    public static function for(int $biome): array
    {
        switch ($biome) {
            case 14:                    // 蘑菇岛
                return [110, 0, 3, 0, false];
            case 16: case 25: case 26:  // 沙滩 / 石滩 / 冷沙滩
                return [12, 0, 12, 0, false];
            case 2: case 17:            // 沙漠 / 沙漠丘陵
                return [12, 0, 24, 0, false];
            case 37: case 38: case 39:  // 台地：红沙 + 硬化黏土
                return [12, 1, 159, 1, false];
            case 12: case 13:           // 冰原 / 雪山
                return [2, 0, 3, 0, true];
            case 30: case 31:           // 冷针叶林
                return [2, 0, 3, 0, true];
            case 32: case 33:           // 巨型针叶林：灰化土
                return [3, 2, 3, 0, false];
            case 0: case 10: case 24:   // 海洋 / 深海
                return [12, 0, 12, 0, false];
            case 6:                     // 沼泽
                return [2, 0, 3, 0, false];
            default:                    // 平原 / 森林 / 丛林 / 针叶林 / 热带草原 / 山地
                return [2, 0, 3, 0, false];
        }
    }
}
