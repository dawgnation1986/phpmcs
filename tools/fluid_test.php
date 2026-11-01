<?php
declare(strict_types=1);

/**
 * 流体无头测试：不依赖客户端，直接看世界里的方块状态。
 * 用法：php tools/fluid_test.php
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
require __DIR__ . '/../src/Protocol.php';
foreach (['ChestTrait', 'CraftingTrait', 'MobTrait', 'FurnaceTrait', 'FluidTrait',
    'RedstoneTrait', 'EnderChestTrait', 'SpawnerTrait', 'BannerTrait', 'BrewingTrait',
    'FarmingTrait', 'EnchantTrait', 'EntityStoreTrait', 'ArrowTrait',
    'BlockPhysicsTrait', 'Server'] as $f) {
    $p = __DIR__ . '/../src/' . $f . '.php';
    if (is_file($p)) {
        require $p;
    }
}

$r = new ReflectionClass(PhpMc\Server::class);
$s = $r->newInstanceWithoutConstructor();
$world = new PhpMc\World\AnvilWorld(__DIR__ . '/../world');
$set = function ($k, $v) {
    $this->$k = $v;
};
$get = function ($k) {
    return $this->$k;
};
$set->call($s, 'world', $world);
$set->call($s, 'conns', []);
$set->call($s, 'nextEntityId', 10000);
$set->call($s, 'startedAt', microtime(true));
$set->call($s, 'chests', new PhpMc\World\ChestStore(__DIR__ . '/../cache/_ft.json'));

$tick = $r->getMethod('tickFluids');
$tick->setAccessible(true);

function show(PhpMc\World\Chunk $c, array $pts): string
{
    $out = [];
    foreach ($pts as [$x, $y, $z]) {
        $st = $c->blockState($x & 15, $y, $z & 15);
        $out[] = sprintf('%s=(%d:%d)', "$x,$z", $st >> 4, $st & 15);
    }

    return implode(' ', $out);
}

// ── 场景：先铺一层石头平台，上面全清空 ──────────────────────────
$c = $world->chunk(0, 0);
for ($x = 0; $x < 12; $x++) {
    for ($z = 0; $z < 4; $z++) {
        $c->setBlock($x, 63, $z, 1, 0);      // 石头地面
        for ($y = 64; $y < 68; $y++) {
            $c->setBlock($x, $y, $z, 0, 0);
        }
    }
}

$place = $r->getMethod('placeFluid');
$place->setAccessible(true);

// F1：放一桶水，看它会不会留下、会不会扩散
$place->invoke($s, 2, 64, 2, 0);
for ($i = 0; $i < 20; $i++) {
    $tick->invoke($s, microtime(true) + $i * 0.3);
}
echo "F1 放水桶 5x5 区域：\n";
for ($z = 0; $z < 5; $z++) {
    echo '  ';
    for ($x = 0; $x < 6; $x++) {
        $st = $c->blockState($x, 64, $z);
        $id = $st >> 4;
        $m = $st & 15;
        echo $id === 0 ? ' . ' : sprintf('%d:%d ', $id, $m);
    }
    echo "\n";
}

// F2：两格水源夹一格（先把地面清出来）
for ($x = 0; $x < 6; $x++) {
    $c->setBlock($x, 64, 1, 0, 0);
}
$c->setBlock(1, 64, 1, 9, 0);
$c->setBlock(3, 64, 1, 9, 0);
$set->call($s, 'fluidQueue', []);
$set->call($s, 'fluidQueued', []);
$start = $r->getMethod('startFluid');
$start->setAccessible(true);
$start->invoke($s, 1, 64, 1, 0);
$start->invoke($s, 3, 64, 1, 0);
for ($i = 0; $i < 6; $i++) {
    $tick->invoke($s, microtime(true) + 100 + $i * 0.3);
}

echo "\nF2 两源夹一格：", show($c, [[1, 64, 1], [2, 64, 1], [3, 64, 1]]), "\n";
echo "  （期望：2,1 变成 9:0 水源）\n";

// ── F3：填掉水源，周围的水必须退干净 ────────────────────────────
for ($x = 0; $x < 10; $x++) {
    for ($z = 9; $z <= 11; $z++) {
        for ($y = 64; $y < 67; $y++) {
            $c->setBlock($x, $y, $z, 0, 0);      // 先把走廊清空，别被 F1/F2 的水污染
        }
        $c->setBlock($x, 63, $z, 1, 0);          // 石头地面
    }
}
$c->setBlock(1, 64, 10, 9, 0);           // 一桶水源
$set->call($s, 'fluidQueue', []);
$set->call($s, 'fluidQueued', []);
$start->invoke($s, 1, 64, 10, 0);
for ($i = 0; $i < 12; $i++) {
    $tick->invoke($s, microtime(true) + 200 + $i * 0.3);
}
$before = [];
for ($x = 1; $x <= 6; $x++) {
    $st = $c->blockState($x, 64, 10);
    $before[] = sprintf('%d:%d', $st >> 4, $st & 15);
}
echo "\nF3 退潮前：x1..6 = ", implode(' ', $before), "\n";

// 填掉水源（用泥土），然后只通知邻居
$c->setBlock(1, 64, 10, 3, 0);
$set->call($s, 'fluidQueue', []);
$set->call($s, 'fluidQueued', []);
$start->invoke($s, 2, 64, 10, 0);
for ($i = 0; $i < 20; $i++) {
    $tick->invoke($s, microtime(true) + 400 + $i * 0.3);
}
$after = [];
for ($x = 1; $x <= 6; $x++) {
    $st = $c->blockState($x, 64, 10);
    $after[] = ($st >> 4) === 0 ? '.' : sprintf('%d:%d', $st >> 4, $st & 15);
}
echo "F3 退潮后：x1..6 = ", implode(' ', $after), " （期望全是 . ）\n";
