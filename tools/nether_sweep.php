<?php
declare(strict_types=1);

/**
 * 扫 airShift，找最接近原版比例的取值。
 * 原版 references：空气 44.9% / 地狱岩 46.0% / 岩浆 4.2% / 基岩 4.7%
 *
 * 用法：php tools/nether_sweep.php
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

$seed = 6479490443963374634;
$chunks = [[12500, 12500], [12502, 12500], [12497, 12499], [12505, 12503]];

$shifts = [45, 50, 55, 60, 70, 80];

// 原版参考值
$target = ['air' => 44.9, 'rock' => 46.0, 'lava' => 4.2];

printf("目标（原版单区块实测）：空气 %.1f%% / 地狱岩 %.1f%% / 岩浆 %.1f%%\n\n", $target['air'], $target['rock'], $target['lava']);
printf("%-8s %8s %8s %8s %8s\n", 'airShift', '空气%', '地狱岩%', '岩浆%', '误差');

foreach ($shifts as $shift) {
    $gen = new NetherGenerator($seed);
    $gen->airShift = (float) $shift;

    $tot = ['air' => 0, 'rock' => 0, 'lava' => 0, 'other' => 0];

    foreach ($chunks as $ci => [$cx, $cz]) {
        // 每个区块重新构造，避免 airShift 影响随机流（其实不影响，稳妥起见）
        $g = new NetherGenerator($seed);
        $g->airShift = (float) $shift;
        $root = $g->generate($cx, $cz);
        $lv = Nbt::child($root, 'Level');

        foreach (Nbt::listItems($lv, 'Sections') as $sec) {
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
                $id = ord($bytes[$i]);
                if ($id === 0) {
                    $tot['air']++;
                } elseif ($id === 87) {
                    $tot['rock']++;
                } elseif ($id === 11 || $id === 10) {
                    $tot['lava']++;
                } else {
                    $tot['other']++;
                }
            }
        }
    }

    $all = array_sum($tot);
    $air = $tot['air'] / $all * 100;
    $rock = $tot['rock'] / $all * 100;
    $lava = $tot['lava'] / $all * 100;
    $err = abs($air - $target['air']) + abs($rock - $target['rock']) + abs($lava - $target['lava']);

    printf("%-8d %7.1f%% %7.1f%% %7.1f%% %7.1f\n", $shift, $air, $rock, $lava, $err);
}
