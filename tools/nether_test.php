<?php
declare(strict_types=1);

/**
 * 地狱地形自测：直接跑 NetherGenerator，导出剖面看形状对不对。
 *
 * 用法：php tools/nether_test.php [seed] [cx] [cz]
 */

require __DIR__ . '/../src/Nbt/Tag.php';
require __DIR__ . '/../src/Nbt/Nbt.php';
require __DIR__ . '/../src/World/LongMath.php';
require __DIR__ . '/../src/World/JavaRandom.php';
require __DIR__ . '/../src/World/NoiseGeneratorImproved.php';
require __DIR__ . '/../src/World/NoiseGeneratorOctaves.php';
require __DIR__ . '/../src/World/NetherCaveGenerator.php';
require __DIR__ . '/../src/World/NetherGenerator.php';

use PhpMc\Nbt\Nbt;
use PhpMc\World\NetherGenerator;

$seed = isset($argv[1]) ? (int) $argv[1] : 12345;
$cx = isset($argv[2]) ? (int) $argv[2] : 12500;
$cz = isset($argv[3]) ? (int) $argv[3] : 12500;

$gen = new NetherGenerator($seed);

$t0 = microtime(true);
$root = $gen->generate($cx, $cz);
$ms = (microtime(true) - $t0) * 1000;

$level = Nbt::child($root, 'Level');
if ($level === null) {
    fwrite(STDERR, "没有 Level\n");
    exit(1);
}

// 把区块读成 [y][idx] 的 id 表
$blocks = [];
for ($y = 0; $y < 128; $y++) {
    $blocks[$y] = array_fill(0, 256, 0);
}
foreach (Nbt::listItems($level, 'Sections') as $sec) {
    $sy = Nbt::int($sec, 'Y');
    $bytes = Nbt::bytes($sec, 'Blocks');
    if ($bytes === null) {
        continue;
    }
    for ($i = 0; $i < 4096; $i++) {
        $y = ($sy << 4) | ($i >> 8);
        if ($y > 127) {
            continue;
        }
        $blocks[$y][$i & 255] = ord($bytes[$i]);
    }
}

$idAt = static function (int $x, int $y, int $z) use (&$blocks): int {
    return $blocks[$y][($z << 4) | $x] ?? 0;
};

$name = static function (int $id): string {
    return match ($id) {
        0 => ' .',
        7 => ' #',
        11 => ' ~',
        13 => ' g',
        87 => ' n',
        88 => ' s',
        89 => ' *',
        153 => ' q',
        default => sprintf('%2d', $id),
    };
};

printf("区块 %d,%d 生成耗时 %.1f ms\n", $cx, $cz, $ms);

$hm = $level->value['HeightMap'] ?? null;
if ($hm !== null) {
    printf("HeightMap 前 8 项：%s\n", implode(' ', array_slice($hm->value, 0, 8)));
}
$bio = $level->value['Biomes'] ?? null;
if ($bio !== null) {
    $u = array_unique(unpack('C*', $bio->value));
    printf("群系种类：%s\n", implode(',', $u));
}

echo "\n=== 俯视（每列最高方块） ===\n";
for ($z = 0; $z < 16; $z++) {
    $row = '';
    for ($x = 0; $x < 16; $x++) {
        $top = 0;
        for ($y = 127; $y >= 0; $y--) {
            if ($idAt($x, $y, $z) !== 0) {
                $top = $y;
                break;
            }
        }
        $row .= $name($idAt($x, $top, $z));
    }
    echo $row . "\n";
}

echo "\n=== 侧剖 x=8（y 从 126 到 0，每 2 层） ===\n";
for ($y = 126; $y >= 0; $y -= 2) {
    $row = sprintf('%3d ', $y);
    for ($z = 0; $z < 16; $z++) {
        $row .= $name($idAt(8, $y, $z));
    }
    echo $row . "\n";
}

$count = [];
foreach ($blocks as $layer) {
    foreach ($layer as $id) {
        $count[$id] = ($count[$id] ?? 0) + 1;
    }
}
ksort($count);
echo "\n=== 方块统计 ===\n";
foreach ($count as $id => $n) {
    printf("  id=%-4d %6d 格  %s\n", $id, $n, match ($id) {
        0 => '空气',
        7 => '基岩',
        11 => '岩浆',
        13 => '沙砾',
        87 => '地狱岩',
        88 => '灵魂沙',
        89 => '萤石',
        153 => '石英矿',
        default => '?',
    });
}

printf("\n空腔占比 %.1f%%\n", ($count[0] ?? 0) / (128 * 256) * 100);
