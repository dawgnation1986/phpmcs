<?php
declare(strict_types=1);

namespace PhpMc\World;

use PhpMc\Nbt\Tag;

/**
 * 末地中央岛地形 —— 原版 ChunkProviderEnd 的移植。
 *
 * 和主世界/地狱最大的区别：地形不是靠噪声本身撑起来的，而是靠
 * **以原点为中心的径向衰减**。原点附近 density 被抬到 +80，整片实心；
 * 往外每 8 格掉 1，到 1000 格左右就全负了，于是岛自然收成一个圆。
 * 噪声只负责边缘的不规则海岸。
 *
 * 采样网格 3×33×3 = 297 点，插值成 16×128×16。
 */
final class EndGenerator
{
    private const END_STONE = 121;
    private const OBSIDIAN = 49;
    private const BEDROCK = 7;
    private const END_PORTAL = 119;
    private const TORCH = 50;
    private const DRAGON_EGG = 122;
    public const BIOME_END = 9;

    /** 基础缩放：原版 684.412，X/Z 会再乘 2 */
    private const BASE_SCALE = 684.412;

    private NoiseGeneratorOctaves $noise1;   // 16 八度
    private NoiseGeneratorOctaves $noise2;   // 16 八度
    private NoiseGeneratorOctaves $noise3;   // 8 八度

    /** 末地带中心（区块坐标），岛的原点映射到这里 */
    private int $centerCx;
    private int $centerCz;

    public function __construct(int $seed, int $centerCx, int $centerCz)
    {
        $this->centerCx = $centerCx;
        $this->centerCz = $centerCz;

        // 构造顺序必须和原版一致
        $rand = new JavaRandom($seed);
        $this->noise1 = new NoiseGeneratorOctaves($rand, 16);
        $this->noise2 = new NoiseGeneratorOctaves($rand, 16);
        $this->noise3 = new NoiseGeneratorOctaves($rand, 8);
    }

    /**
     * 3×33×3 密度网格。索引 ((x * 3 + z) * 33 + y)。
     *
     * @return float[]
     */
    private function initializeNoiseField(int $cx, int $cz): array
    {
        // 相对区块坐标：末地带中心就是原点
        $rx = $cx - $this->centerCx;
        $rz = $cz - $this->centerCz;

        $sx = 1368.824;
        $sy = self::BASE_SCALE;

        // 原版先 baseScaleX *= 2
        $n1 = $this->noise3->generateNoiseOctaves(
            null, $rx * 2, 0, $rz * 2, 3, 33, 3,
            $sx / 80.0, $sy / 160.0, $sx / 80.0
        );
        $n2 = $this->noise1->generateNoiseOctaves(null, $rx * 2, 0, $rz * 2, 3, 33, 3, $sx, $sy, $sx);
        $n3 = $this->noise2->generateNoiseOctaves(null, $rx * 2, 0, $rz * 2, 3, 33, 3, $sx, $sy, $sx);

        $out = [];
        $idx = 0;

        for ($sxi = 0; $sxi < 3; $sxi++) {
            for ($szi = 0; $szi < 3; $szi++) {
                // 径向衰减用「粗格」为单位：一个采样点 = 8 格 = 半个区块
                $wx = (float) ($sxi + $rx * 2);
                $wz = (float) ($szi + $rz * 2);
                $radial = 100.0 - sqrt($wx * $wx + $wz * $wz) * 8.0;
                if ($radial < -100.0) {
                    $radial = -100.0;
                } elseif ($radial > 80.0) {
                    $radial = 80.0;
                }

                for ($syi = 0; $syi < 33; $syi++) {
                    $a = $n2[$idx] / 512.0;
                    $b = $n3[$idx] / 512.0;
                    $selector = ($n1[$idx] / 10.0 + 1.0) / 2.0;

                    if ($selector < 0.0) {
                        $d = $a;
                    } elseif ($selector > 1.0) {
                        $d = $b;
                    } else {
                        $d = $a + ($b - $a) * $selector;
                    }

                    // 原版：先减 8，再加径向项
                    $d -= 8.0;
                    $d += $radial;

                    // 顶部衰减：sampleY > 14 时往 -3000 拉
                    if ($syi > 14) {
                        $f = ($syi - 14) / 64.0;
                        if ($f > 1.0) {
                            $f = 1.0;
                        }
                        $d = $d * (1.0 - $f) + (-3000.0) * $f;
                    }

                    // 底部衰减：sampleY < 8 时往 -30 拉
                    if ($syi < 8) {
                        $f = (8 - $syi) / 7.0;
                        $d = $d * (1.0 - $f) + (-30.0) * $f;
                    }

                    $out[$idx] = $d;
                    $idx++;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<int, array<int, int>> [y][z*16+x]
     */
    private function generateTerrain(int $cx, int $cz): array
    {
        $noise = $this->initializeNoiseField($cx, $cz);

        $blocks = [];
        for ($y = 0; $y < 128; $y++) {
            $blocks[$y] = array_fill(0, 256, 0);
        }

        $n = function (int $x, int $z, int $y) use ($noise): float {
            return $noise[(($x * 3) + $z) * 33 + $y];
        };

        for ($cxc = 0; $cxc < 2; $cxc++) {
            for ($czc = 0; $czc < 2; $czc++) {
                for ($cyc = 0; $cyc < 32; $cyc++) {
                    // 8 个角
                    $d000 = $n($cxc, $czc, $cyc);
                    $d001 = $n($cxc, $czc + 1, $cyc);
                    $d100 = $n($cxc + 1, $czc, $cyc);
                    $d101 = $n($cxc + 1, $czc + 1, $cyc);
                    $d010 = $n($cxc, $czc, $cyc + 1);
                    $d011 = $n($cxc, $czc + 1, $cyc + 1);
                    $d110 = $n($cxc + 1, $czc, $cyc + 1);
                    $d111 = $n($cxc + 1, $czc + 1, $cyc + 1);

                    for ($subY = 0; $subY < 4; $subY++) {
                        $ty = $subY / 4.0;
                        $by = $d000 + ($d010 - $d000) * $ty;
                        $by1 = $d001 + ($d011 - $d001) * $ty;
                        $by2 = $d100 + ($d110 - $d100) * $ty;
                        $by3 = $d101 + ($d111 - $d101) * $ty;

                        for ($subX = 0; $subX < 8; $subX++) {
                            $tx = $subX / 8.0;
                            $bx0 = $by + ($by2 - $by) * $tx;
                            $bx1 = $by1 + ($by3 - $by1) * $tx;

                            for ($subZ = 0; $subZ < 8; $subZ++) {
                                $tz = $subZ / 8.0;
                                $d = $bx0 + ($bx1 - $bx0) * $tz;

                                if ($d > 0.0) {
                                    $lx = $cxc * 8 + $subX;
                                    $ly = $cyc * 4 + $subY;
                                    $lz = $czc * 8 + $subZ;
                                    $blocks[$ly][($lz << 4) | $lx] = self::END_STONE;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $blocks;
    }

    public function generate(int $cx, int $cz): Tag
    {
        $metas = [];
        $blocks = $this->generateTerrain($cx, $cz);

        // 返回基座（龙死后才该出现，先按结构做出来看效果）
        $this->buildExitPortal($blocks, $metas, $cx, $cz);

        $this->buildPillars($blocks, $cx, $cz);

        // 进末地的落脚平台挪到基座南边 8 格，别盖住传送门
        $this->buildSpawnPlatform($blocks, $cx, $cz);

        return $this->pack($cx, $cz, $blocks, $metas);
    }

    /**
     * 返回传送门基座 —— 文档 6.4 的逐格参数。
     *
     *   半径 3.5 的圆盘，j=-1 是底、j=0 是传送门层、j=1..32 清空
     *   j=-1: r2 <= 6.25 基岩
     *   j= 0: r2 <= 6.25 末地传送门(119)，外圈 6.25<r2<=12.25 基岩
     *   中心柱：y+0..2 基岩，y+2 四个侧向火把，y+3 基岩，y+4 龙蛋
     *
     * @param array<int, array<int, int>> $blocks
     * @param array<int, array<int, int>> $metas
     */
    private function buildExitPortal(array &$blocks, array &$metas, int $cx, int $cz): void
    {
        $rx = $cx - $this->centerCx;
        $rz = $cz - $this->centerCz;
        if (abs($rx) > 1 || abs($rz) > 1) {
            return;
        }

        $baseX = $rx * 16;
        $baseZ = $rz * 16;
        $baseY = 64;

        for ($j = -1; $j <= 32; $j++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                for ($dz = -4; $dz <= 4; $dz++) {
                    $r2 = $dx * $dx + $dz * $dz;
                    if ($r2 > 12.25) {
                        continue;
                    }

                    $lx = $dx - $baseX;
                    $lz = $dz - $baseZ;
                    if ($lx < 0 || $lx > 15 || $lz < 0 || $lz > 15) {
                        continue;
                    }
                    $y = $baseY + $j;
                    if ($y < 0 || $y > 127) {
                        continue;
                    }

                    $col = ($lz << 4) | $lx;
                    if ($j === -1) {
                        if ($r2 <= 6.25) {
                            $blocks[$y][$col] = self::BEDROCK;
                        }
                    } elseif ($j === 0) {
                        $blocks[$y][$col] = $r2 <= 6.25 ? self::END_PORTAL : self::BEDROCK;
                    } else {
                        $blocks[$y][$col] = 0;
                    }
                }
            }
        }

        // 中心柱 + 火把 + 龙蛋
        $put = function (int $dx, int $dy, int $dz, int $id, int $meta = 0) use (&$blocks, &$metas, $baseX, $baseZ): void {
            $lx = $dx - $baseX;
            $lz = $dz - $baseZ;
            if ($lx < 0 || $lx > 15 || $lz < 0 || $lz > 15) {
                return;
            }
            $y = 64 + $dy;
            if ($y < 0 || $y > 127) {
                return;
            }
            $col = ($lz << 4) | $lx;
            $blocks[$y][$col] = $id;
            if ($meta !== 0) {
                $metas[$y][$col] = $meta;
            }
        };

        $put(0, 0, 0, self::BEDROCK);
        $put(0, 1, 0, self::BEDROCK);
        $put(0, 2, 0, self::BEDROCK);
        // 火把朝向：1=East 2=West 3=South 4=North
        $put(0, 2, -1, self::TORCH, 3);
        $put(0, 2, 1, self::TORCH, 4);
        $put(-1, 2, 0, self::TORCH, 1);
        $put(1, 2, 0, self::TORCH, 2);
        $put(0, 3, 0, self::BEDROCK);
        // 龙蛋不在这里放 —— 原版是龙死后才出现，由 killDragon() 补上
    }

    /**
     * 进末地的落脚平台：基座南边 8 格，5×5 黑曜石 + 上方清 3 格。
     *
     * @param array<int, array<int, int>> $blocks
     */
    private function buildSpawnPlatform(array &$blocks, int $cx, int $cz): void
    {
        $rx = $cx - $this->centerCx;
        $rz = $cz - $this->centerCz;
        if (abs($rx) > 1 || abs($rz) > 1) {
            return;
        }

        $baseX = $rx * 16;
        $baseZ = $rz * 16;

        for ($dx = -2; $dx <= 2; $dx++) {
            for ($dz = 6; $dz <= 10; $dz++) {
                $lx = $dx - $baseX;
                $lz = $dz - $baseZ;
                if ($lx < 0 || $lx > 15 || $lz < 0 || $lz > 15) {
                    continue;
                }
                $col = ($lz << 4) | $lx;
                $blocks[64][$col] = self::OBSIDIAN;
                for ($y = 65; $y <= 67; $y++) {
                    $blocks[$y][$col] = 0;
                }
            }
        }
    }

    /**
     * 10 根柱子的顶端坐标（相对末地中心），给服务端放水晶用。
     *
     * 返回的是水晶该待的 y：柱顶那块末地石在 topY+1，水晶坐在它上面，
     * 所以这里是 topY+2。
     *
     * @return array<int, array{0:int,1:int,2:int}>
     */
    public static function pillarTops(): array
    {
        $out = [];
        for ($i = 0; $i < 10; $i++) {
            $angle = $i * 2.0 * M_PI / 10.0;
            $px = (int) round(cos($angle) * 42.0);
            $pz = (int) round(sin($angle) * 42.0);
            $topY = 76 + ($i % 3) * 9;
            $out[] = [$px, $topY + 1, $pz];   // 柱顶没有末地石了，水晶直接坐上去
        }

        return $out;
    }

    /**
     * 黑曜石柱：10 根围着主岛成环形。
     *
     * 之前按文档写成「每区块 1/5 概率随机长一根」，结果是一堆散落的尖刺，
     * 完全不是末地的样子。原版主岛就是 10 根：半径约 42 格，角度 36° 一根，
     * 高度 76 / 85 / 94 三档交替，柱身黑曜石、顶端末地石。
     *
     * 柱心跨区块，所以每个区块生成时都要把 10 根都过一遍，看自己覆盖到哪根。
     *
     * @param array<int, array<int, int>> $blocks
     */
    private function buildPillars(array &$blocks, int $cx, int $cz): void
    {
        $rx = $cx - $this->centerCx;
        $rz = $cz - $this->centerCz;
        $baseX = $rx * 16;
        $baseZ = $rz * 16;

        $radius = 3;

        for ($i = 0; $i < 10; $i++) {
            $angle = $i * 2.0 * M_PI / 10.0;
            $px = (int) round(cos($angle) * 42.0);
            $pz = (int) round(sin($angle) * 42.0);
            $topY = 76 + ($i % 3) * 9;

            // 包围盒和本区块没交集就跳过
            if ($px + $radius < $baseX || $px - $radius > $baseX + 15) {
                continue;
            }
            if ($pz + $radius < $baseZ || $pz - $radius > $baseZ + 15) {
                continue;
            }

            // 柱身：从岛底一直长到 topY
            for ($y = 40; $y <= $topY && $y <= 127; $y++) {
                for ($dx = -$radius; $dx <= $radius; $dx++) {
                    for ($dz = -$radius; $dz <= $radius; $dz++) {
                        if ($dx * $dx + $dz * $dz > $radius * $radius) {
                            continue;
                        }
                        $lx = $px + $dx - $baseX;
                        $lz = $pz + $dz - $baseZ;
                        if ($lx < 0 || $lx > 15 || $lz < 0 || $lz > 15) {
                            continue;
                        }
                        $blocks[$y][($lz << 4) | $lx] = self::OBSIDIAN;
                    }
                }
            }

            // 柱顶什么都不放 —— 原版水晶直接坐在黑曜石柱最上面那格，
            // 不需要末地石垫底。之前盖了一层反而像给柱子扣了个盖子。
        }
    }

    /** 区块坐标 → 稳定的 64 位种子 */
    private function hash(int $cx, int $cz): int
    {
        $h = LongMath::mul($cx, 341873128712) + LongMath::mul($cz, 132897987541);

        return $h ^ 0x5DEECE66D;
    }

    /**
     * @param array<int, array<int, int>> $blocks
     */
    private function pack(int $cx, int $cz, array $blocks, array $metas = []): Tag
    {
        $sections = [];
        for ($sy = 0; $sy < 8; $sy++) {
            $sections[$sy] = [
                'blocks' => str_repeat("\x00", 4096),
                'data' => str_repeat("\x00", 2048),
            ];
        }

        for ($y = 0; $y < 128; $y++) {
            $sy = $y >> 4;
            $base = ($y & 15) << 8;
            for ($z = 0; $z < 16; $z++) {
                $line = '';
                for ($x = 0; $x < 16; $x++) {
                    $line .= chr($blocks[$y][($z << 4) | $x] & 0xFF);
                }
                $sections[$sy]['blocks'] = substr_replace($sections[$sy]['blocks'], $line, $base | ($z << 4), 16);
            }
        }

        // 火把之类的朝向写在 Data 里，其余方块 meta 都是 0
        foreach ($metas as $y => $row) {
            $sy = $y >> 4;
            $base = ($y & 15) << 8;
            foreach ($row as $col => $m) {
                $i = $base | $col;
                $bi = $i >> 1;
                $b = ord($sections[$sy]['data'][$bi]);
                $sections[$sy]['data'][$bi] = chr(($i & 1)
                    ? (($b & 0x0F) | (($m & 0x0F) << 4))
                    : (($b & 0xF0) | ($m & 0x0F)));
            }
        }

        $heights = array_fill(0, 256, 0);
        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                for ($y = 127; $y >= 0; $y--) {
                    if ($blocks[$y][($z << 4) | $x] !== 0) {
                        $heights[$z * 16 + $x] = $y + 1;
                        break;
                    }
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
                'BlockLight' => Tag::byteArray(str_repeat("\x00", 2048)),
                'SkyLight' => Tag::byteArray(str_repeat("\xff", 2048)),
            ]);
        }

        return Tag::compound([
            'Level' => Tag::compound([
                'xPos' => Tag::int($cx),
                'zPos' => Tag::int($cz),
                'LastUpdate' => Tag::long(time()),
                'phpMcGenerated' => Tag::byte(1),
                'phpMcEnd' => Tag::byte(1),
                'TerrainPopulated' => Tag::byte(1),
                'InhabitedTime' => Tag::long(0),
                'Biomes' => Tag::byteArray(str_repeat("\x09", 256)),
                'HeightMap' => Tag::intArray($heights),
                'Sections' => Tag::list(Tag::COMPOUND, $sectionTags),
                'Entities' => Tag::list(Tag::COMPOUND, []),
                'TileEntities' => Tag::list(Tag::COMPOUND, []),
            ]),
        ]);
    }
}
