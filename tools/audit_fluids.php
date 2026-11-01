<?php
declare(strict_types=1);

/**
 * 审计：扫世界里的水/岩浆方块 ID 分布。
 *
 * 目的：ChatGPT 说 1.8.9 里 8=流动水、9=静止水，我们的代码写反了。
 * 改之前先搞清楚「存档里实际存的是什么」，否则改完可能把全世界的河
 * 变成流动水外观、或者让水凭空消失。
 *
 * 用法：php tools/audit_fluids.php world
 */

require __DIR__ . '/../src/Nbt/Tag.php';
require __DIR__ . '/../src/Nbt/Nbt.php';
require __DIR__ . '/../src/World/LongMath.php';
require __DIR__ . '/../src/World/JavaRandom.php';
require __DIR__ . '/../src/World/NoiseGeneratorImproved.php';
require __DIR__ . '/../src/World/NoiseGeneratorOctaves.php';
foreach (glob(__DIR__ . '/../src/World/Layer/*.php') as $f) {
    require $f;
}
require __DIR__ . '/../src/World/DensityField.php';
require __DIR__ . '/../src/World/SurfaceRules.php';
require __DIR__ . '/../src/World/CaveGenerator.php';
require __DIR__ . '/../src/World/OreDecorator.php';
require __DIR__ . '/../src/World/VillageGenerator.php';
require __DIR__ . '/../src/World/Chunk.php';
require __DIR__ . '/../src/World/TerrainGenerator.php';
require __DIR__ . '/../src/World/ChestStore.php';
require __DIR__ . '/../src/World/AnvilWorld.php';

$dir = $argv[1] ?? 'world';
$world = new PhpMc\World\AnvilWorld($dir);

$count = [];        // id => 总块数
$meta0 = [];        // id => meta 0 的块数
$chunks = 0;

// 半径 6 区块（出生点附近），够判断语义
for ($cx = -6; $cx <= 6; $cx++) {
    for ($cz = -6; $cz <= 6; $cz++) {
        $chunk = $world->chunk($cx, $cz);
        $chunks++;
        for ($y = 1; $y < 256; $y++) {
            for ($z = 0; $z < 16; $z++) {
                for ($x = 0; $x < 16; $x++) {
                    $st = $chunk->blockState($x, $y, $z);
                    $id = $st >> 4;
                    if ($id !== 8 && $id !== 9 && $id !== 10 && $id !== 11) {
                        continue;
                    }
                    $count[$id] = ($count[$id] ?? 0) + 1;
                    if (($st & 15) === 0) {
                        $meta0[$id] = ($meta0[$id] ?? 0) + 1;
                    }
                }
            }
        }
    }
}

echo "扫描 {$chunks} 个区块\n";
echo "ID  总数        meta=0\n";
foreach ([8, 9, 10, 11] as $id) {
    printf("%-3d %-10d %d\n", $id, $count[$id] ?? 0, $meta0[$id] ?? 0);
}
