<?php
declare(strict_types=1);

/**
 * 端到端验证：AnvilWorld 在地狱坐标带是否真的走了 NetherGenerator。
 *
 * 用真实 level.dat 的种子，但写到临时目录，不动 world/。
 *
 * 用法：php tools/nether_world_test.php
 */

$root = dirname(__DIR__);

require $root . '/src/Nbt/Tag.php';
require $root . '/src/Nbt/Nbt.php';
require $root . '/src/World/Chunk.php';
require $root . '/src/World/LongMath.php';
require $root . '/src/World/JavaRandom.php';
require $root . '/src/World/NoiseGeneratorImproved.php';
require $root . '/src/World/NoiseGeneratorOctaves.php';
require $root . '/src/World/DensityField.php';
require $root . '/src/World/SurfaceRules.php';
require $root . '/src/World/CaveCarver.php';
require $root . '/src/World/CaveGenerator.php';
require $root . '/src/World/OreDecorator.php';
require $root . '/src/World/VillageGenerator.php';
foreach (glob($root . '/src/World/Layer/*.php') as $f) {
    require $f;
}
require $root . '/src/World/TerrainGenerator.php';
require $root . '/src/World/NetherGenerator.php';
require $root . '/src/World/AnvilWorld.php';
require $root . '/src/World/ChestStore.php';

use PhpMc\Nbt\Nbt;
use PhpMc\World\AnvilWorld;

$tmp = $root . '/tools/tmp_nether_world';
if (is_dir($tmp)) {
    foreach (glob($tmp . '/*') as $f) {
        is_dir($f) ? null : unlink($f);
    }
} else {
    mkdir($tmp, 0777, true);
}
copy($root . '/world/level.dat', $tmp . '/level.dat');

$w = new AnvilWorld($tmp);
printf("世界种子 %d\n", $w->seed());
printf("isNetherChunk(12500,12500) = %s\n", $w->isNetherChunk(12500, 12500) ? 'true' : 'false');
printf("isNetherChunk(0,0)         = %s\n", $w->isNetherChunk(0, 0) ? 'true' : 'false');
printf("isNetherChunk(12500+128,12500) = %s\n", $w->isNetherChunk(12500 + 128, 12500) ? 'true' : 'false');
printf("isNetherChunk(12500+129,12500) = %s\n", $w->isNetherChunk(12500 + 129, 12500) ? 'true' : 'false');

// --- 地狱区块 ---
$t0 = microtime(true);
$chunk = $w->chunk(12500, 12500);
$ms = (microtime(true) - $t0) * 1000;
printf("\n地狱区块生成 %.1f ms\n", $ms);

$payload = $chunk->payload();
[$mask, $data] = $payload;
printf("bitmask = 0x%04X（只发实际有方块的段，全空段跳过）\n", $mask);
printf("负载长度 = %d 字节（%d 段）\n", strlen($data), substr_count(decbin($mask | 0x10000), '1') - 1);

// 直接从 chunk 的 NBT 里数方块（Chunk 没暴露 level()，这里重新解析 gen 结果不现实，
// 改用 payload 的方块段反推：每段 8192 字节 ushort）
$secCount = substr_count(decbin($mask | 0x10000), '1') - 1;
$solid = 0;
for ($k = 0; $k < $secCount; $k++) {
    for ($i = 0; $i < 4096; $i++) {
        $v = ord($data[$k * 8192 + $i * 2]) | (ord($data[$k * 8192 + $i * 2 + 1]) << 8);
        if (($v >> 4) !== 0) {
            $solid++;
        }
    }
}
printf("实心方块 %d 格 / %d 格（%.0f%% 空气）\n", $solid, $secCount * 4096,
    (1 - $solid / ($secCount * 4096)) * 100);

$bio = substr($data, -256);
$uniq = array_unique(unpack('C*', $bio));
printf("群系 = %s\n", implode(',', $uniq));

// --- 主世界区块对照 ---
$t0 = microtime(true);
$over = $w->chunk(0, 0);
$ms2 = (microtime(true) - $t0) * 1000;
[$m2, $d2] = $over->payload();
printf("\n主世界区块 0,0 生成 %.1f ms，bitmask = 0x%04X，负载 %d 字节\n", $ms2, $m2, strlen($d2));

// 清理
foreach (glob($tmp . '/region/*.mca') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp . '/region');
@unlink($tmp . '/level.dat');
@rmdir($tmp);
echo "\n临时目录已清理\n";
