<?php
declare(strict_types=1);

/**
 * 把「原版客户端加载过的 DIM-1 存档」导入到主世界的地狱坐标带。
 *
 * 背景：我们自己生成的的地狱地形怎么调都不像原版。用户用官方 1.8.9 客户端
 * 加载了这份 PHP 存档，客户端顺手生成了 761 个真地狱区块（DIM-1/region）。
 * 与其继续猜参数，不如直接把这些区块搬过来 —— 地形、萤石、岩浆、下界要塞
 * 全都是原版的。
 *
 * 坐标映射：源区块 (cx,cz) → 目标 (cx+12500, cz+12500)，
 * 也就是原来 DIM-1 里 (0,0) 附近的地形，正好落在我们传送落点 (200000,200000)。
 *
 * 用法：php tools/import_nether.php [--dry]
 */

$root = dirname(__DIR__);

require $root . '/src/Nbt/Tag.php';
require $root . '/src/Nbt/Nbt.php';

use PhpMc\Nbt\Nbt;
use PhpMc\Nbt\Tag;

$srcDir = $root . '/客户端生成的存档/3434343434/DIM-1/region';
$dstDir = $root . '/world/region';
$offCx = 12500;
$offCz = 12500;
$dry = in_array('--dry', $argv, true);

if (!is_dir($srcDir)) {
    fwrite(STDERR, "找不到源目录：{$srcDir}\n");
    exit(1);
}

/**
 * 读一个 region 里所有区块的原始压缩数据。
 *
 * @return array<int, string> idx => 压缩后的负载（不含长度和压缩类型字节）
 */
function readRegionChunks(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return [];
    }
    $head = fread($fh, 4096);
    $out = [];

    for ($i = 0; $i < 1024; $i++) {
        $b = substr($head, $i * 4, 3);
        $off = (ord($b[0]) << 16) | (ord($b[1]) << 8) | ord($b[2]);
        if ($off < 2) {
            continue;
        }
        fseek($fh, $off * 4096);
        $lenRaw = fread($fh, 4);
        if ($lenRaw === false || strlen($lenRaw) < 4) {
            continue;
        }
        $len = unpack('N', $lenRaw)[1];
        if ($len <= 1) {
            continue;
        }
        fread($fh, 1);   // 压缩类型，重写时统一用 zlib
        $body = fread($fh, $len - 1);
        if ($body === false || strlen($body) !== $len - 1) {
            continue;
        }
        $out[$i] = $body;
    }

    fclose($fh);

    return $out;
}

/** 把 idx => gz 负载写成 region 文件（覆盖写） */
function writeRegionFile(string $path, array $chunks): void
{
    $now = time();
    $header = str_repeat("\x00", 8192);
    $body = '';
    $sector = 2;

    ksort($chunks);
    foreach ($chunks as $idx => $gz) {
        $len = strlen($gz) + 1;                       // +1 是压缩类型字节
        $total = 4 + $len;                            // +4 是长度字段
        $need = (int) ceil($total / 4096);
        if ($need < 1) {
            $need = 1;
        }
        // 偏移表：3 字节起始扇区 + 1 字节扇区数
        $entry = chr(($sector >> 16) & 0xFF)
            . chr(($sector >> 8) & 0xFF)
            . chr($sector & 0xFF)
            . chr($need & 0xFF);
        $header = substr_replace($header, $entry, $idx * 4, 4);
        // 时间戳表
        $header = substr_replace($header, pack('N', $now), 4096 + $idx * 4, 4);

        $body .= pack('N', $len) . chr(2) . $gz;      // 2 = zlib
        $pad = $need * 4096 - $total;
        if ($pad > 0) {
            $body .= str_repeat("\x00", $pad);
        }
        $sector += $need;
    }

    file_put_contents($path, $header . $body);
}

// ---- 1) 读源区块，改坐标，按目标 region 分组 ----
$byRegion = [];
$total = 0;
$failed = 0;

foreach (glob($srcDir . '/r.*.mca') ?: [] as $file) {
    if (!preg_match('/r\.(-?\d+)\.(-?\d+)\.mca$/', basename($file), $m)) {
        continue;
    }
    $rx = (int) $m[1];
    $rz = (int) $m[2];

    $fh = fopen($file, 'rb');
    if ($fh === false) {
        continue;
    }
    $head = fread($fh, 4096);

    for ($i = 0; $i < 1024; $i++) {
        $b = substr($head, $i * 4, 3);
        $off = (ord($b[0]) << 16) | (ord($b[1]) << 8) | ord($b[2]);
        if ($off < 2) {
            continue;
        }
        $cx = $rx * 32 + ($i % 32);
        $cz = $rz * 32 + intdiv($i, 32);

        fseek($fh, $off * 4096);
        $lenRaw = fread($fh, 4);
        if ($lenRaw === false || strlen($lenRaw) < 4) {
            $failed++;

            continue;
        }
        $len = unpack('N', $lenRaw)[1];
        $comp = ord(fread($fh, 1));
        $body = fread($fh, $len - 1);
        if ($body === false || strlen($body) !== $len - 1) {
            $failed++;

            continue;
        }

        $raw = match ($comp) {
            1 => @gzdecode($body),
            2 => @gzuncompress($body),
            3 => $body,
            default => false,
        };
        if ($raw === false) {
            $failed++;

            continue;
        }

        $nbt = Nbt::parse($raw);
        $level = Nbt::child($nbt, 'Level');
        if ($level === null) {
            $failed++;

            continue;
        }

        // 坐标搬到地狱带
        $level->value['xPos'] = Tag::int($cx + $offCx);
        $level->value['zPos'] = Tag::int($cz + $offCz);

        $dcx = $cx + $offCx;
        $dcz = $cz + $offCz;
        $drx = $dcx >> 5;
        $drz = $dcz >> 5;
        $didx = ($dcx & 31) + ($dcz & 31) * 32;

        $byRegion["{$drx},{$drz}"][$didx] = gzcompress(Nbt::write($nbt), 6);
        $total++;
    }

    fclose($fh);
}

printf("读到 %d 个原版地狱区块，失败 %d\n", $total, $failed);

if ($total === 0) {
    fwrite(STDERR, "没有可导入的区块\n");
    exit(1);
}

// ---- 2) 写目标 region ----
foreach ($byRegion as $key => $chunks) {
    [$drx, $drz] = array_map('intval', explode(',', $key));
    $path = $dstDir . "/r.{$drx}.{$drz}.mca";

    // 目标文件可能已经有主世界数据，先读进来合并（导入的覆盖同 idx）
    $existing = readRegionChunks($path);
    $merged = $existing;
    foreach ($chunks as $idx => $gz) {
        $merged[$idx] = $gz;
    }

    printf("  %s：原有 %d + 导入 %d = %d 个区块\n",
        basename($path), count($existing), count($chunks), count($merged));

    if (!$dry) {
        writeRegionFile($path, $merged);
    }
}

echo $dry ? "\n（--dry，没有真的写盘）\n" : "\n导入完成。\n";
