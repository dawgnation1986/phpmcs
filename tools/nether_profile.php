<?php
declare(strict_types=1);

/**
 * 地狱地形生成各阶段耗时。只测算法本身，不含落盘和发包。
 *
 * 用法：php tools/nether_profile.php [次数]
 */

require __DIR__ . '/../src/Nbt/Tag.php';
require __DIR__ . '/../src/Nbt/Nbt.php';
require __DIR__ . '/../src/World/LongMath.php';
require __DIR__ . '/../src/World/JavaRandom.php';
require __DIR__ . '/../src/World/NoiseGeneratorImproved.php';
require __DIR__ . '/../src/World/NetherCaveGenerator.php';
require __DIR__ . '/../src/World/NetherGenerator.php';

use PhpMc\World\NetherGenerator;

$rounds = (int) ($argv[1] ?? 5);
$seed = 6479490443963374634;

$gen = new NetherGenerator($seed);
$r = new ReflectionClass($gen);
$mTerrain = $r->getMethod('generateTerrain');
$mTerrain->setAccessible(true);
$mDecorate = $r->getMethod('decorate');
$mDecorate->setAccessible(true);
$mPopulate = $r->getMethod('populate');
$mPopulate->setAccessible(true);
$mPack = $r->getMethod('pack');
$mPack->setAccessible(true);
$mInit = $r->getMethod('initializeNoiseField');
$mInit->setAccessible(true);
$rand = $r->getProperty('rand');
$rand->setAccessible(true);

$acc = ['init' => 0.0, 'terrain' => 0.0, 'decorate' => 0.0, 'populate' => 0.0, 'pack' => 0.0, 'total' => 0.0];

for ($i = 0; $i < $rounds; $i++) {
    $cx = 12500 + $i * 3;
    $cz = 12500 - $i * 2;

    $rand->getValue($gen)->setSeed($cx * 341873128712 + $cz * 132897987541);

    $t = microtime(true);
    $t0 = microtime(true);
    $mInit->invoke($gen, $cx, $cz);
    $acc['init'] += microtime(true) - $t0;

    $t0 = microtime(true);
    $blocks = $mTerrain->invoke($gen, $cx, $cz);
    $acc['terrain'] += microtime(true) - $t0;

    $t0 = microtime(true);
    $mDecorate->invokeArgs($gen, [$cx, $cz, &$blocks]);
    $acc['decorate'] += microtime(true) - $t0;

    $t0 = microtime(true);
    $mPopulate->invokeArgs($gen, [$cx, $cz, &$blocks]);
    $acc['populate'] += microtime(true) - $t0;

    $t0 = microtime(true);
    $mPack->invoke($gen, $cx, $cz, $blocks);
    $acc['pack'] += microtime(true) - $t0;

    $acc['total'] += microtime(true) - $t;
}

printf("跑 %d 个区块：\n", $rounds);
foreach ($acc as $k => $v) {
    printf("  %-9s %7.1f ms/区块  %5.1f%%\n", $k, $v / $rounds * 1000, $k === 'total' ? 100 : $v / $acc['total'] * 100);
}

// 单独看 octave 的成本
$mOct = $r->getMethod('octave');
$mOct->setAccessible(true);
$coarse = $r->getProperty('coarse');
$coarse->setAccessible(true);
$t = microtime(true);
for ($i = 0; $i < $rounds; $i++) {
    $mOct->invoke($gen, $coarse->getValue($gen), 50000, 0, 50000, 5, 17, 5, 0.085, 0.03, 0.085, 3);
}
printf("\n一组 3 层 octave（5×17×5）：%.1f ms\n", (microtime(true) - $t) / $rounds * 1000);
