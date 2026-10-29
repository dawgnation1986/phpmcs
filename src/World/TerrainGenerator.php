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
    /** 原版密度场（地形高度的来源） */
    private \PhpMc\World\DensityField $density;

    public function __construct(int $seed)
    {
        $this->seed = $seed;
        $this->layers = new \PhpMc\World\Layer\BiomeLayerProvider($seed);
        $this->density = new \PhpMc\World\DensityField($seed);

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

    /** 只查群系，不生成地形（/findbiome 用） */
    /** 一块矩形区域的群系（一次算完，比逐区块快几十倍） */
    public function biomeArea(int $x, int $z, int $w, int $h): array
    {
        return $this->layers->area($x, $z, $w, $h);
    }

    public function biomeGrid(int $cx, int $cz): array
    {
        return $this->layers->chunkBiomes($cx, $cz);
    }

    /**
     * 群系 → 树种配置。
     * 17/18 是 oak/spruce/birch/jungle（meta 0/1/2/3），
     * 161/162 是 2 号木头（acacia=0 / dark oak=1）。
     */
    private static function treeConfig(int $biome): ?array
    {
        return match ($biome) {
            1 => ['count' => 1, 'log' => 17, 'logMeta' => 0, 'leaf' => 18, 'leafMeta' => 0,
                  'minH' => 4, 'maxH' => 6, 'radius' => 2, 'canopyLayers' => 3],
            4 => ['count' => 8, 'log' => 17, 'logMeta' => 0, 'leaf' => 18, 'leafMeta' => 0,
                  'minH' => 4, 'maxH' => 7, 'radius' => 2, 'canopyLayers' => 3],
            27, 28 => ['count' => 7, 'log' => 17, 'logMeta' => 2, 'leaf' => 18, 'leafMeta' => 2,
                  'minH' => 5, 'maxH' => 7, 'radius' => 2, 'canopyLayers' => 3],
            29 => ['count' => 9, 'log' => 162, 'logMeta' => 1, 'leaf' => 161, 'leafMeta' => 1,
                  'minH' => 5, 'maxH' => 7, 'radius' => 3, 'canopyLayers' => 2],
            5, 19 => ['count' => 8, 'log' => 17, 'logMeta' => 1, 'leaf' => 18, 'leafMeta' => 1,
                  'minH' => 6, 'maxH' => 10, 'radius' => 1, 'canopyLayers' => 4],
            30, 31 => ['count' => 6, 'log' => 17, 'logMeta' => 1, 'leaf' => 18, 'leafMeta' => 1,
                  'minH' => 6, 'maxH' => 9, 'radius' => 1, 'canopyLayers' => 4],
            32, 33 => ['count' => 7, 'log' => 17, 'logMeta' => 1, 'leaf' => 18, 'leafMeta' => 1,
                  'minH' => 9, 'maxH' => 14, 'radius' => 2, 'canopyLayers' => 5],
            21, 22 => ['count' => 11, 'log' => 17, 'logMeta' => 3, 'leaf' => 18, 'leafMeta' => 3,
                  'minH' => 7, 'maxH' => 12, 'radius' => 2, 'canopyLayers' => 3],
            23 => ['count' => 4, 'log' => 17, 'logMeta' => 3, 'leaf' => 18, 'leafMeta' => 3,
                  'minH' => 6, 'maxH' => 9, 'radius' => 2, 'canopyLayers' => 3],
            35, 36 => ['count' => 2, 'log' => 162, 'logMeta' => 0, 'leaf' => 161, 'leafMeta' => 0,
                  'minH' => 5, 'maxH' => 6, 'radius' => 2, 'canopyLayers' => 2],
            6 => ['count' => 3, 'log' => 17, 'logMeta' => 0, 'leaf' => 18, 'leafMeta' => 0,
                  'minH' => 4, 'maxH' => 6, 'radius' => 2, 'canopyLayers' => 3],
            3, 34 => ['count' => 2, 'log' => 17, 'logMeta' => 1, 'leaf' => 18, 'leafMeta' => 1,
                  'minH' => 5, 'maxH' => 8, 'radius' => 1, 'canopyLayers' => 3],
            12, 13 => ['count' => 1, 'log' => 17, 'logMeta' => 1, 'leaf' => 18, 'leafMeta' => 1,
                  'minH' => 5, 'maxH' => 8, 'radius' => 1, 'canopyLayers' => 3],
            default => null,
        };
    }

    public function generate(int $cx, int $cz): Tag
    {
        // 生成 y=0..255，和原版 1.8 一样是 16 个 section
        $sections = [];
        for ($sy = 0; $sy < 16; $sy++) {
            $sections[$sy] = [
                'blocks' => str_repeat("\x00", 4096),
                'data' => str_repeat("\x00", 2048),
                'blockLight' => str_repeat("\x00", 2048),
                'skyLight' => str_repeat("\x00", 2048),
            ];
        }

        $set = function (int $x, int $y, int $z, int $id, int $meta = 0) use (&$sections): void {
            if ($y < 0 || $y > 255) {
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
            if ($y < 0 || $y > 255) {
                return 0;
            }

            return ord($sections[$y >> 4]['blocks'][(($y & 15) << 8) | ($z << 4) | $x]);
        };
        $setSky = function (int $x, int $y, int $z, int $v) use (&$sections): void {
            if ($y < 0 || $y > 255) {
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

        // 密度场：5x33x5 网格。原版是把这张网格三线性插值到每个方块，
        // 密度 > 0 就是石头；不是先算一个高度再整列填满。整列填满会把
        // 悬垂和自然过渡全部填实，变成大平顶和刀切断崖。
        // 这里先把每列的 33 层水平插值算好（y 方向的插值是线性的），
        // 逐格填充时就只剩一次 lerp，比每格重算 8 个角点快数倍。
        $field = $this->density->noiseField($cx, $cz, $this->layers->generationBiomes($cx, $cz));
        $profiles = [];
        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                $fx = $x / 4.0;
                $fz = $z / 4.0;
                $x0 = (int) floor($fx);
                $z0 = (int) floor($fz);
                $tx = $fx - $x0;
                $tz = $fz - $z0;
                $vals = [];
                for ($y = 0; $y < 33; $y++) {
                    $i00 = ($x0 * 5 + $z0) * 33 + $y;
                    $i10 = (($x0 + 1) * 5 + $z0) * 33 + $y;
                    $i01 = ($x0 * 5 + $z0 + 1) * 33 + $y;
                    $i11 = (($x0 + 1) * 5 + $z0 + 1) * 33 + $y;
                    $a0 = $field[$i00] + ($field[$i10] - $field[$i00]) * $tx;
                    $a1 = $field[$i01] + ($field[$i11] - $field[$i01]) * $tx;
                    $vals[$y] = $a0 + ($a1 - $a0) * $tz;
                }
                $profiles[$z * 16 + $x] = $vals;
            }
        }

        // 先扫一遍每列的地表高度，供「靠海才是沙滩」判断用（只读，不写方块）
        $surfPre = [];
        for ($pz = 0; $pz < 16; $pz++) {
            for ($px = 0; $px < 16; $px++) {
                $vals = $profiles[$pz * 16 + $px];
                $hp = 0;
                for ($y = 32; $y >= 0; $y--) {
                    if ($vals[$y] > 0.0) {
                        $hp = $y * 8 + 8;
                        break;
                    }
                }
                if ($hp > 255) {
                    $hp = 255;
                }
                $top = 0;
                for ($y = $hp; $y >= 1; $y--) {
                    $y0 = $y >> 3;
                    $ty = ($y & 7) / 8.0;
                    $d = $vals[$y0] + ($vals[$y0 + 1] - $vals[$y0]) * $ty;
                    if ($d > 0.0) {
                        $top = $y;
                        break;
                    }
                }
                $surfPre[$pz * 16 + $px] = $top;
            }
        }

        // ---------------- 基础地形 ----------------
        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                $wx = $cx * 16 + $x;
                $wz = $cz * 16 + $z;
                $vals = $profiles[$z * 16 + $x];
                // 最高正密度层，作为这一列的填充上界
                $h = 0;
                for ($y = 32; $y >= 0; $y--) {
                    if ($vals[$y] > 0.0) {
                        $h = $y * 8 + 8;
                        break;
                    }
                }
                if ($h > 255) {
                    $h = 255;
                }
                $biome = $biomeGrid[$z * 16 + $x];

                // 逐格判断密度，只填实心格，保留原版的 3D 起伏和悬垂
                $solid = [];
                for ($y = 1; $y <= $h; $y++) {
                    $y0 = $y >> 3;
                    $ty = ($y & 7) / 8.0;
                    $d = $vals[$y0] + ($vals[$y0 + 1] - $vals[$y0]) * $ty;
                    if ($d > 0.0) {
                        $solid[$y] = true;
                        $set($x, $y, $z, 1);
                    }
                }
                // 真正的地表 = 这一列最高的实心格
                $topSolid = 0;
                for ($y = $h; $y >= 0; $y--) {
                    if (isset($solid[$y])) {
                        $topSolid = $y;
                        break;
                    }
                }
                $h = $topSolid;

                $surface[$z * 16 + $x] = $h;
                $biomeGrid[$z * 16 + $x] = $biome;
                $biomes .= chr($biome);

                $set($x, 0, $z, 7);        // 基岩

                // 只在实心格里换表层方块，不会把空气填成泥土
                $put = function (int $y, int $id, int $meta = 0) use (&$solid, $set, $x, $z): void {
                    if (isset($solid[$y])) {
                        $set($x, $y, $z, $id, $meta);
                    }
                };

                if ($h < self::SEA) {
                    // 海底：石头 + 表层沙，水灌到海平面，冷海面结冰
                    for ($y = max(1, $h - 2); $y <= $h; $y++) {
                        $put($y, 12);
                    }
                    for ($y = $h + 1; $y <= self::SEA; $y++) {
                        if (!isset($solid[$y])) {
                            $set($x, $y, $z, 9);
                        }
                    }
                    if ($biome === 10 && $h + 1 <= self::SEA) {
                        $put(self::SEA, 79);   // 冰
                    }
                    continue;
                }

                // 只有紧挨着水才铺沙滩；内陆低地还是按群系走
                $isCoast = false;
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
                    $nx = $x + $dx;
                    $nz = $z + $dz;
                    if ($nx < 0 || $nx > 15 || $nz < 0 || $nz > 15) {
                        continue;
                    }
                    if ($surfPre[$nz * 16 + $nx] < self::SEA) {
                        $isCoast = true;
                        break;
                    }
                }
                if ($h <= self::SEA + 1 && $isCoast) {
                    // 沙滩
                    for ($y = max(1, $h - 3); $y <= $h; $y++) {
                        $put($y, 12);
                    }
                    continue;
                }

                if ($biome === 2) {
                    // 沙漠：沙子 + 砂岩
                    $put(max(1, $h - 4), 24);
                    for ($y = max(1, $h - 3); $y <= $h; $y++) {
                        $put($y, 12);
                    }
                    continue;
                }

                if ($biome === 3) {
                    // 山地：高处露石头，低处还是草
                    $topId = $h > 100 ? 1 : 2;
                    for ($y = max(1, $h - 3); $y < $h; $y++) {
                        $put($y, $topId === 1 ? 1 : 3);
                    }
                    $put($h, $topId);
                    continue;
                }

                // 其余群系走表层规则表：顶层 + 填充层 + 要不要盖雪
                [$topId, $topMeta, $fillId, $fillMeta, $snow] = SurfaceRules::for($biome);
                $depth = 3 + ((int) floor(self::hash01($wx, $wz, 0x5EA5) * 2));   // 3~4 格厚
                for ($y = max(1, $h - $depth); $y < $h; $y++) {
                    $put($y, $fillId, $fillMeta);
                }
                $put($h, $topId, $topMeta);
                if ($snow && $h < 255) {
                    $set($x, $h + 1, $z, 78);   // 雪层
                }
            }
        }

        // ---------------- 洞穴（原版 MapGenCaves） ----------------
        $cx0 = $cx * 16;
        $cz0 = $cz * 16;
        $getBlock = function (int $wx, int $wy, int $wz) use (&$sections, $cx0, $cz0): int {
            $x = $wx - $cx0;
            $z = $wz - $cz0;
            // 生成范围只有 y=0..255，超出的一律当空气
            if ($x < 0 || $x > 15 || $z < 0 || $z > 15 || $wy < 0 || $wy > 255) {
                return 0;
            }
            $i = (($wy & 15) << 8) | ($z << 4) | $x;
            $sy = $wy >> 4;
            if (!isset($sections[$sy]['blocks'][$i])) {
                return 0;
            }

            return ord($sections[$sy]['blocks'][$i]);
        };
        $setBlock = function (int $wx, int $wy, int $wz, int $id) use (&$sections, $cx0, $cz0): void {
            $x = $wx - $cx0;
            $z = $wz - $cz0;
            if ($x < 0 || $x > 15 || $z < 0 || $z > 15 || $wy < 1 || $wy > 255) {
                return;
            }
            $sections[$wy >> 4]['blocks'][(($wy & 15) << 8) | ($z << 4) | $x] = chr($id & 0xFF);
        };
        (new CaveGenerator($this->seed, $getBlock, $setBlock))->generate($this->seed, $cx, $cz);

        // ---------------- 矿石 ----------------
        $putOre = function (int $x, int $y, int $z, int $id) use (&$sections, $surface): void {
            if ($y >= $surface[$z * 16 + $x]) {
                return;   // 不在地表以上放
            }
            $sy = $y >> 4;
            $i = (($y & 15) << 8) | ($z << 4) | $x;
            if (ord($sections[$sy]['blocks'][$i]) !== 1) {
                return;   // 只替换石头
            }
            $sections[$sy]['blocks'][$i] = chr($id & 0xFF);
        };
        (new OreDecorator($putOre))->decorate($this->seed, $cx, $cz);

        // ---------------- 村庄 ----------------
        // 选址用原版 MapGenVillage 的公式，群系限定 平原/沙漠/热带草原
        if (VillageGenerator::isVillageChunk($this->seed, $cx, $cz)) {
            $centerBiome = $biomeGrid[8 * 16 + 8];
            if (in_array($centerBiome, VillageGenerator::BIOMES, true)) {
                VillageGenerator::build($cx, $cz, $centerBiome, $surface, $set, $get);
            }
        }

        // ---------------- 树 ----------------
        // 每个群系用自己的树种、高度和密度（原来是所有群系统一 5 棵橡树）
        for ($t = 0; $t < 12; $t++) {
            $r1 = self::hash01($cx, $cz, $this->seed ^ (0x1000 + $t * 2));
            $r2 = self::hash01($cx, $cz, $this->seed ^ (0x1001 + $t * 2));
            $tx = 2 + (int) floor($r1 * 12);
            $tz = 2 + (int) floor($r2 * 12);
            $idx = $tz * 16 + $tx;
            $h = $surface[$idx];
            $biome = $biomeGrid[$idx];

            $cfg = self::treeConfig($biome);
            if ($cfg === null || $t >= $cfg['count'] || $h <= 64) {
                continue;
            }
            if ($get($tx, $h, $tz) !== 2) {
                continue;   // 只在草方块上长
            }

            $th = $cfg['minH'] + (int) floor(
                self::hash01($cx, $cz, $this->seed ^ (0x2100 + $t)) * ($cfg['maxH'] - $cfg['minH'] + 1)
            );
            if ($h + $th + 2 > 255) {
                continue;
            }

            // 树干
            for ($dy = 1; $dy <= $th; $dy++) {
                $set($tx, $h + $dy, $tz, $cfg['log'], $cfg['logMeta']);
            }

            // 树冠：底下几层用配置的半径，顶上一层收窄
            for ($dy = $th - $cfg['canopyLayers']; $dy <= $th; $dy++) {
                $radius = ($dy >= $th) ? 1 : $cfg['radius'];
                for ($dx = -$radius; $dx <= $radius; $dx++) {
                    for ($dz = -$radius; $dz <= $radius; $dz++) {
                        if ($dx * $dx + $dz * $dz > $radius * $radius + 1) {
                            continue;
                        }
                        $lx = $tx + $dx;
                        $lz = $tz + $dz;
                        if ($lx < 0 || $lx > 15 || $lz < 0 || $lz > 15) {
                            continue;
                        }
                        if ($get($lx, $h + $dy, $lz) === 0) {
                            $set($lx, $h + $dy, $lz, $cfg['leaf'], $cfg['leafMeta']);
                        }
                    }
                }
            }
            if ($get($tx, $h + $th + 1, $tz) === 0) {
                $set($tx, $h + $th + 1, $tz, $cfg['leaf'], $cfg['leafMeta']);
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
                for ($y = 255; $y >= 0; $y--) {
                    if ($get($x, $y, $z) !== 0) {
                        $heights[$z * 16 + $x] = $y + 1;
                        break;
                    }
                }
            }
        }

        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                for ($y = $heights[$z * 16 + $x]; $y < 256; $y++) {
                    $setSky($x, $y, $z, 15);
                }
            }
        }

        $sectionTags = [];
        for ($sy = 0; $sy < 16; $sy++) {
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
