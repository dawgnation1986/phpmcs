<?php
declare(strict_types=1);

namespace PhpMc\World;

use PhpMc\Nbt\Tag;

/**
 * 地形生成：Perlin 噪声 + 温度/湿度气候群系。
 *
 * 这不是逐块复刻原版 1.8，但噪声换成了梯度 Perlin，并加入了海洋、沙滩、
 * 平原、森林、沙漠、针叶林、冰原、沼泽、热带草原和山地，避免之前那种
 * 到处一样、只有平原/森林的重复感。
 */
final class TerrainGenerator
{
    private const SEA = 62;

    private int $seed;
    /** @var int[] 512 项的 Perlin 排列表 */
    private array $perm = [];
    /** 群系层（按原版建链，和真存档逐块一致） */
    private \PhpMc\World\Layer\BiomeLayerProvider $layers;

    public function __construct(int $seed)
    {
        $this->seed = $seed;
        $this->layers = new \PhpMc\World\Layer\BiomeLayerProvider($seed);

        // 和 Minecraft 的 NoiseGeneratorPerlin 一样：用 java.util.Random
        // 做 Fisher-Yates 洗牌，同一个种子得到的排列表与原版一致。
        $rand = new JavaRandom($seed);
        $p = range(0, 255);
        for ($i = 0; $i < 256; $i++) {
            $k = $rand->nextIntBound(256 - $i) + $i;
            $t = $p[$i];
            $p[$i] = $p[$k];
            $p[$k] = $t;
        }
        $this->perm = array_merge($p, $p);
    }

    public function generate(int $cx, int $cz): Tag
    {
        // 只生成 y=0..127，够放下最高地形和树冠
        $sections = [];
        for ($sy = 0; $sy < 8; $sy++) {
            $sections[$sy] = [
                'blocks' => str_repeat("\x00", 4096),
                'data' => str_repeat("\x00", 2048),
                'blockLight' => str_repeat("\x00", 2048),
                'skyLight' => str_repeat("\x00", 2048),
            ];
        }

        $set = function (int $x, int $y, int $z, int $id, int $meta = 0) use (&$sections): void {
            if ($y < 0 || $y > 127) {
                return;
            }
            $sy = $y >> 4;
            $i = (($y & 15) << 8) | ($z << 4) | $x;
            $sections[$sy]['blocks'][$i] = chr($id & 0xFF);
            $bi = $i >> 1;
            $b = ord($sections[$sy]['data'][$bi]);
            $sections[$sy]['data'][$bi] = chr(($i & 1)
                ? (($b & 0x0F) | (($meta & 0x0F) << 4))
                : (($b & 0xF0) | ($meta & 0x0F)));
        };
        $get = function (int $x, int $y, int $z) use (&$sections): int {
            if ($y < 0 || $y > 127) {
                return 0;
            }

            return ord($sections[$y >> 4]['blocks'][(($y & 15) << 8) | ($z << 4) | $x]);
        };
        $setSky = function (int $x, int $y, int $z, int $v) use (&$sections): void {
            if ($y < 0 || $y > 127) {
                return;
            }
            $sy = $y >> 4;
            $i = (($y & 15) << 8) | ($z << 4) | $x;
            $bi = $i >> 1;
            $b = ord($sections[$sy]['skyLight'][$bi]);
            $sections[$sy]['skyLight'][$bi] = chr(($i & 1)
                ? (($b & 0x0F) | (($v & 0x0F) << 4))
                : (($b & 0xF0) | ($v & 0x0F)));
        };

        $biomes = '';
        $surface = array_fill(0, 256, 0);
        // 群系直接用原版群系层算出来的结果，不再自己猜
        $biomeGrid = $this->layers->chunkBiomes($cx, $cz);

        // ---------------- 基础地形 ----------------
        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                $wx = $cx * 16 + $x;
                $wz = $cz * 16 + $z;
                $h = $this->terrainHeightAt($wx, $wz);
                $biome = $biomeGrid[$z * 16 + $x];
                $surface[$z * 16 + $x] = $h;
                $biomeGrid[$z * 16 + $x] = $biome;
                $biomes .= chr($biome);

                $set($x, 0, $z, 7);        // 基岩

                if ($h < self::SEA) {
                    // 海底：石头 + 表层沙，水灌到海平面，冷海面结冰
                    for ($y = 1; $y <= $h - 3; $y++) {
                        $set($x, $y, $z, 1);
                    }
                    for ($y = max(1, $h - 2); $y <= $h; $y++) {
                        $set($x, $y, $z, 12);
                    }
                    for ($y = $h + 1; $y <= self::SEA; $y++) {
                        $set($x, $y, $z, 9);
                    }
                    if ($biome === 10 && $h + 1 <= self::SEA) {
                        $set($x, self::SEA, $z, 79);   // 冰
                    }
                    continue;
                }

                if ($h <= 64) {
                    // 沙滩
                    for ($y = 1; $y <= $h - 3; $y++) {
                        $set($x, $y, $z, 1);
                    }
                    for ($y = max(1, $h - 3); $y <= $h; $y++) {
                        $set($x, $y, $z, 12);
                    }
                    continue;
                }

                if ($biome === 2) {
                    // 沙漠：沙子 + 砂岩
                    for ($y = 1; $y <= $h - 5; $y++) {
                        $set($x, $y, $z, 1);
                    }
                    $set($x, max(1, $h - 4), $z, 24);
                    for ($y = max(1, $h - 3); $y <= $h; $y++) {
                        $set($x, $y, $z, 12);
                    }
                    continue;
                }

                if ($biome === 3) {
                    // 山地：高处露石头，低处还是草
                    for ($y = 1; $y < $h; $y++) {
                        $set($x, $y, $z, $h > 100 ? 1 : 3);
                    }
                    $set($x, $h, $z, $h > 100 ? 1 : 2);
                    continue;
                }

                // 森林/平原/针叶林/冰原/沼泽/热带草原：草 + 泥土
                for ($y = 1; $y <= $h - 4; $y++) {
                    $set($x, $y, $z, 1);
                }
                for ($y = max(1, $h - 3); $y < $h; $y++) {
                    $set($x, $y, $z, 3);
                }
                $set($x, $h, $z, 2);
            }
        }

        // ---------------- 树 ----------------
        for ($t = 0; $t < 5; $t++) {
            $r1 = self::hash01($cx, $cz, $this->seed ^ (0x1000 + $t * 2));
            $r2 = self::hash01($cx, $cz, $this->seed ^ (0x1001 + $t * 2));
            $tx = 2 + (int) floor($r1 * 12);
            $tz = 2 + (int) floor($r2 * 12);
            $idx = $tz * 16 + $tx;
            $h = $surface[$idx];
            $biome = $biomeGrid[$idx];

            $tree = match ($biome) {
                4 => [17, 0, 18, 0, 0.62],   // 橡树
                5 => [17, 1, 18, 1, 0.50],   // 云杉
                6 => [17, 0, 18, 0, 0.42],   // 沼泽橡树
                35 => [162, 0, 161, 0, 0.32], // 金合欢
                3 => [17, 0, 18, 0, 0.10],   // 山地少量橡树
                1 => [17, 0, 18, 0, 0.18],   // 平原少量橡树
                default => null,
            };
            if ($tree === null || $h <= 64 || $get($tx, $h, $tz) !== 2) {
                continue;
            }
            [$logId, $logMeta, $leafId, $leafMeta, $chance] = $tree;
            if (self::hash01($cx, $cz, $this->seed ^ (0x2000 + $t)) > $chance) {
                continue;
            }

            $th = $biome === 35 ? 5 + (int) floor($r1 * 3) : 4 + (int) floor($r1 * 3);
            if ($h + $th + 2 > 127) {
                continue;
            }
            for ($dy = 1; $dy <= $th; $dy++) {
                $set($tx, $h + $dy, $tz, $logId, $logMeta);
            }
            for ($dy = $th - 3; $dy <= $th; $dy++) {
                $radius = $dy >= $th ? 1 : 2;
                for ($dx = -$radius; $dx <= $radius; $dx++) {
                    for ($dz = -$radius; $dz <= $radius; $dz++) {
                        if ($dx * $dx + $dz * $dz > $radius * $radius + 1) {
                            continue;
                        }
                        $lx = $tx + $dx;
                        $lz = $tz + $dz;
                        $ly = $h + $dy;
                        if ($lx < 0 || $lx > 15 || $lz < 0 || $lz > 15) {
                            continue;
                        }
                        if ($get($lx, $ly, $lz) === 0) {
                            $set($lx, $ly, $lz, $leafId, $leafMeta);
                        }
                    }
                }
            }
            if ($get($tx, $h + $th + 1, $tz) === 0) {
                $set($tx, $h + $th + 1, $tz, $leafId, $leafMeta);
            }
        }

        // ---------------- 沙漠仙人掌/枯木 ----------------
        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                $idx = $z * 16 + $x;
                if ($biomeGrid[$idx] !== 2 || $surface[$idx] <= 64) {
                    continue;
                }
                $h = $surface[$idx];
                if ($get($x, $h, $z) !== 12) {
                    continue;
                }
                $wx = $cx * 16 + $x;
                $wz = $cz * 16 + $z;
                $r = self::hash01($wx, $wz, $this->seed ^ 0xCAC7);
                if ($r < 0.012 && $get($x, $h + 1, $z) === 0) {
                    $ch = 1 + (int) floor(self::hash01($wx, $wz, $this->seed ^ 0xCAC8) * 3);
                    for ($dy = 1; $dy <= $ch; $dy++) {
                        $set($x, $h + $dy, $z, 81);
                    }
                } elseif ($r < 0.025 && $get($x, $h + 1, $z) === 0) {
                    $set($x, $h + 1, $z, 32);
                }
            }
        }

        // ---------------- 水边甘蔗 ----------------
        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                $h = $surface[$z * 16 + $x];
                if ($h !== self::SEA || $get($x, $h, $z) !== 12) {
                    continue;
                }
                $nearWater = false;
                foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dz]) {
                    $nx = $x + $dx;
                    $nz = $z + $dz;
                    if ($nx < 0 || $nx > 15 || $nz < 0 || $nz > 15) {
                        continue;
                    }
                    $nid = $get($nx, $h, $nz);
                    if ($nid === 8 || $nid === 9) {
                        $nearWater = true;
                        break;
                    }
                }
                if (!$nearWater) {
                    continue;
                }
                $wx = $cx * 16 + $x;
                $wz = $cz * 16 + $z;
                if (self::hash01($wx, $wz, $this->seed ^ 0xCA11) > 0.08) {
                    continue;
                }
                $top = $h + 1 + (int) floor(self::hash01($wx, $wz, $this->seed ^ 0xCA12) * 3);
                for ($y = $h + 1; $y <= $top; $y++) {
                    if ($get($x, $y, $z) === 0) {
                        $set($x, $y, $z, 83);
                    }
                }
            }
        }

        // ---------------- 草/花/雪 ----------------
        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                $idx = $z * 16 + $x;
                $biome = $biomeGrid[$idx];
                $h = $surface[$idx];
                if ($h <= 64 || $get($x, $h, $z) !== 2 || $get($x, $h + 1, $z) !== 0) {
                    continue;
                }
                $wx = $cx * 16 + $x;
                $wz = $cz * 16 + $z;
                $r = self::hash01($wx, $wz, $this->seed ^ 0x55AA);

                if ($biome === 12 || $biome === 5) {
                    // 冰原/针叶林：雪层，针叶林只铺一部分
                    if ($biome === 12 || $r < 0.35) {
                        $set($x, $h + 1, $z, 78);
                    }
                    continue;
                }
                if ($biome === 2 || $biome === 35) {
                    // 沙漠没有草；热带草原只有高草
                    if ($biome === 35 && $r < 0.08) {
                        $set($x, $h + 1, $z, 31, 1);
                    }
                    continue;
                }
                if ($r < 0.10) {
                    $set($x, $h + 1, $z, 31, 1);
                } elseif ($r < 0.105) {
                    $set($x, $h + 1, $z, $r < 0.1025 ? 37 : 38);
                }
            }
        }

        // ---------------- 高度图/天空光/段 ----------------
        $heights = array_fill(0, 256, 0);
        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                for ($y = 127; $y >= 0; $y--) {
                    if ($get($x, $y, $z) !== 0) {
                        $heights[$z * 16 + $x] = $y + 1;
                        break;
                    }
                }
            }
        }

        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                for ($y = $heights[$z * 16 + $x]; $y < 128; $y++) {
                    $setSky($x, $y, $z, 15);
                }
            }
        }

        $sectionTags = [];
        for ($sy = 0; $sy < 8; $sy++) {
            if (trim($sections[$sy]['blocks'], "\x00") === '') {
                continue;
            }
            $sectionTags[] = Tag::compound([
                'Y' => Tag::byte($sy),
                'Blocks' => Tag::byteArray($sections[$sy]['blocks']),
                'Data' => Tag::byteArray($sections[$sy]['data']),
                'BlockLight' => Tag::byteArray($sections[$sy]['blockLight']),
                'SkyLight' => Tag::byteArray($sections[$sy]['skyLight']),
            ]);
        }

        return Tag::compound([
            'Level' => Tag::compound([
                'xPos' => Tag::int($cx),
                'zPos' => Tag::int($cz),
                'LastUpdate' => Tag::long(time()),
                'phpMcGenerated' => Tag::byte(1),
                'TerrainPopulated' => Tag::byte(1),
                'InhabitedTime' => Tag::long(0),
                'Biomes' => Tag::byteArray($biomes),
                'HeightMap' => Tag::intArray($heights),
                'Sections' => Tag::list(Tag::COMPOUND, $sectionTags),
                'Entities' => Tag::list(Tag::COMPOUND, []),
                'TileEntities' => Tag::list(Tag::COMPOUND, []),
            ]),
        ]);
    }

    /** 多倍频 Perlin 算出来的地表高度（y 是最高一个实心/水方块的 y） */
    private function terrainHeightAt(int $wx, int $wz): int
    {
        $continent = $this->fbm($wx * 0.0035, $wz * 0.0035, 3, 11);
        $hills = $this->fbm($wx * 0.020, $wz * 0.020, 4, 23);
        $detail = $this->perlin2($wx * 0.08, $wz * 0.08, 37);
        $h = 70.0 + $continent * 24.0 + $hills * 14.0 + $detail * 3.0;

        $mount = $this->fbm($wx * 0.007, $wz * 0.007, 3, 41);
        if ($mount > 0.35) {
            $h += ($mount - 0.35) * 75.0;
        }

        // 河流：用绝对值 Perlin 做窄沟，边缘平滑过渡
        $river = abs($this->perlin2($wx * 0.0035, $wz * 0.0035, 53));
        if ($river < 0.05 && $h > self::SEA) {
            $t = $river / 0.05;
            $h = self::SEA - 1 + ($h - (self::SEA - 1)) * $t;
        }

        return max(8, min(118, (int) round($h)));
    }

    /** 气候群系：海洋/沙滩/平原/森林/沙漠/针叶林/冰原/沼泽/热带草原/山地 */
    private function biomeAt(int $wx, int $wz, int $h): int
    {
        $temp = $this->fbm($wx * 0.0030, $wz * 0.0030, 3, 61);
        $humid = $this->fbm($wx * 0.0035, $wz * 0.0035, 3, 67);

        if ($h < self::SEA) {
            return $temp < -0.25 ? 10 : 0;   // 冷海 / 海洋
        }
        if ($h <= 64) {
            return 16;                        // 沙滩
        }
        if ($h > 92) {
            return 3;                         // 山地
        }
        if ($temp < -0.25) {
            return 12;                        // 冰原
        }
        if ($temp < -0.05 && $humid > 0.0) {
            return 5;                         // 针叶林
        }
        if ($temp > 0.25 && $humid < -0.05) {
            return 2;                         // 沙漠
        }
        if ($temp > 0.10 && $humid < 0.20) {
            return 35;                        // 热带草原
        }
        if ($humid > 0.25 && $temp > 0.05) {
            return 6;                         // 沼泽
        }
        if ($humid > 0.08) {
            return 4;                         // 森林
        }

        return 1;                             // 平原
    }

    private function fade(float $t): float
    {
        return $t * $t * $t * ($t * ($t * 6.0 - 15.0) + 10.0);
    }

    private function lerp(float $a, float $b, float $t): float
    {
        return $a + $t * ($b - $a);
    }

    private function grad(int $hash, float $x, float $z): float
    {
        $h = $hash & 15;
        $u = $h < 8 ? $x : $z;
        $v = $h < 4 ? $z : ($h === 12 || $h === 14 ? $x : 0.0);

        return (($h & 1) === 0 ? $u : -$u) + (($h & 2) === 0 ? $v : -$v);
    }

    /** 2D 梯度 Perlin，返回约 -1..1 */
    private function perlin2(float $x, float $z, int $salt): float
    {
        $x += ($salt & 0xFFFF) * 0.137;
        $z += (($salt >> 16) & 0xFFFF) * 0.193;

        $xi = ((int) floor($x)) & 255;
        $zi = ((int) floor($z)) & 255;
        $xf = $x - floor($x);
        $zf = $z - floor($z);
        $u = $this->fade($xf);
        $v = $this->fade($zf);

        $aa = $this->perm[$this->perm[$xi] + $zi];
        $ab = $this->perm[$this->perm[$xi] + $zi + 1];
        $ba = $this->perm[$this->perm[$xi + 1] + $zi];
        $bb = $this->perm[$this->perm[$xi + 1] + $zi + 1];

        $x1 = $this->lerp(
            $this->grad($aa, $xf, $zf),
            $this->grad($ba, $xf - 1.0, $zf),
            $u
        );
        $x2 = $this->lerp(
            $this->grad($ab, $xf, $zf - 1.0),
            $this->grad($bb, $xf - 1.0, $zf - 1.0),
            $u
        );

        return $this->lerp($x1, $x2, $v) * 0.72;
    }

    private function fbm(float $x, float $z, int $octaves, int $salt): float
    {
        $sum = 0.0;
        $amp = 1.0;
        $norm = 0.0;
        $freq = 1.0;
        for ($i = 0; $i < $octaves; $i++) {
            $sum += $this->perlin2($x * $freq, $z * $freq, $salt + $i * 131) * $amp;
            $norm += $amp;
            $amp *= 0.5;
            $freq *= 2.0;
        }

        return $sum / $norm;
    }

    /** 0..1 的稳定哈希，用来放树、草、花 */
    private static function hash01(int $x, int $z, int $salt): float
    {
        $salt &= 0x7FFFFFFF;
        $h = ($x * 374761393 + $z * 668265263 + $salt * 1442695041) & 0x7FFFFFFF;
        $h = (($h ^ ($h >> 13)) * 1274126177) & 0x7FFFFFFF;
        $h ^= $h >> 16;

        return ($h & 0x7FFFFFFF) / 2147483647.0;
    }
}
