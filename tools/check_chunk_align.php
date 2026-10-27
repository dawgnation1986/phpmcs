<?php
require 'src/Nbt/Tag.php'; require 'src/Nbt/Nbt.php'; require 'src/World/Chunk.php';
require 'src/World/TerrainGenerator.php'; require 'src/World/AnvilWorld.php';

$dir = 'world/region';
$w = new PhpMc\World\AnvilWorld('world');
$bad = 0; $ok = 0; $checked = 0;
foreach (glob($dir . '/r.*.mca') as $file) {
    if (!preg_match('/r\.(-?\d+)\.(-?\d+)\.mca$/', $file, $m)) continue;
    $rx = (int)$m[1]; $rz = (int)$m[2];
    $fh = fopen($file, 'rb');
    $header = fread($fh, 4096);
    fclose($fh);
    for ($i = 0; $i < 1024; $i++) {
        $o = unpack('N', substr($header, $i * 4, 4))[1];
        if ($o === 0) continue;
        $cx = $rx * 32 + ($i % 32);
        $cz = $rz * 32 + intdiv($i, 32);
        $checked++;
        [$mask, $data] = $w->chunkPayload($cx, $cz);
        $sections = 0;
        for ($b = 0; $b < 16; $b++) { if ($mask & (1 << $b)) $sections++; }
        $expect = $sections * 8192 + $sections * 4096 + 256;
        if (strlen($data) !== $expect) {
            $bad++;
            printf("✗ 区块(%d,%d) mask=0x%04x 段数=%d 期望=%d 实际=%d 差=%d\n",
                $cx, $cz, $mask, $sections, $expect, strlen($data), strlen($data) - $expect);
            if ($bad >= 8) break 2;
        } else {
            $ok++;
        }
    }
}
printf("\n检查 %d 个区块：正常 %d，错位 %d\n", $checked, $ok, $bad);
