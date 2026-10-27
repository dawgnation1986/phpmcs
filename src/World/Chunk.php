<?php
declare(strict_types=1);

namespace PhpMc\World;

use PhpMc\Nbt\Nbt;
use PhpMc\Nbt\Tag;

/**
 * 一个区块。手里攥着磁盘上那份 NBT，改方块就是改 NBT 里的
 * Blocks / Data 两个字节数组，所以存回去天然保真 —— 不认识的标签（实体、
 * 方块实体、模组数据）一个都不会丢。
 */
final class Chunk
{
    /** @var array<string, string> 方块值 => 小端两字节，避免反复 pack */
    private static array $packCache = [];

    private ?array $payload = null;
    public bool $dirty = false;

    public function __construct(
        public int $cx,
        public int $cz,
        public Tag $root,      // 区块 NBT 根（含 Level）
    ) {
    }

    /** 256 字节的群系数组（z*16+x），拿不到返回 null。 */
    public function biomes(): ?string
    {
        return Nbt::bytes($this->level(), 'Biomes');
    }

    private function level(): ?Tag
    {
        return Nbt::child($this->root, 'Level');
    }

    /** @return Tag[] */
    private function sections(): array
    {
        return Nbt::listItems($this->level(), 'Sections');
    }

    private function sectionFor(int $sy): ?Tag
    {
        foreach ($this->sections() as $sec) {
            if (Nbt::int($sec, 'Y') === $sy) {
                return $sec;
            }
        }

        return null;
    }

    // ---------------- 发往客户端 ----------------

    /**
     * 拼 1.8 的区块负载。顺序是原版 go.java（S21PacketChunkData）的写法：
     *   1. 所有段的方块（每块 ushort 小端）
     *   2. 所有段的方块光
     *   3. 所有段的天空光
     *   4. 生物群系（groundUp 时）
     * 不是「每段 方块+光 各自打包」！那样只有在单段区块里才碰巧正确，
     * 多段区块会被客户端读串位 —— 表现就是进去满屏石头、放个方块才恢复。
     *
     * @return array{0:int,1:string} [bitmask, 区块负载]
     */
    public function payload(): array
    {
        if ($this->payload !== null) {
            return $this->payload;
        }

        $sections = $this->sections();
        usort($sections, static fn (Tag $a, Tag $b) => Nbt::int($a, 'Y') <=> Nbt::int($b, 'Y'));

        $mask = 0;
        /** @var Tag[] $kept */
        $kept = [];

        foreach ($sections as $sec) {
            $sy = Nbt::int($sec, 'Y');
            if ($sy < 0 || $sy > 15) {
                continue;
            }
            $blocks = Nbt::bytes($sec, 'Blocks');
            if ($blocks === null || strlen($blocks) !== 4096) {
                continue;
            }
            // 全空气的段不放进 bitmask，跟原版一致
            if (trim($blocks, "\x00") === '') {
                continue;
            }
            $mask |= 1 << $sy;
            $kept[] = $sec;
        }

        $blocksPart = '';
        foreach ($kept as $sec) {
            $blocksPart .= $this->blocksBytes($sec);
        }

        $blockLightPart = '';
        foreach ($kept as $sec) {
            $blockLightPart .= $this->blockLightBytes($sec, Nbt::int($sec, 'Y'));
        }

        $skyLightPart = '';
        foreach ($kept as $sec) {
            $skyLightPart .= $this->skyLightBytes($sec, Nbt::int($sec, 'Y'));
        }

        $biomes = Nbt::bytes($this->level(), 'Biomes');

        $data = $blocksPart . $blockLightPart . $skyLightPart
            . (($biomes !== null && strlen($biomes) === 256) ? $biomes : str_repeat("\x01", 256));

        return $this->payload = [$mask, $data];
    }

    /**
     * 单独一段的负载：方块 + 方块光 + 天空光。
     * 只有一段时，这个顺序和整块的分组布局是一样的。
     */
    public function sectionPayload(int $sy): ?string
    {
        $sec = $this->sectionFor($sy);
        if ($sec === null) {
            return null;
        }

        return $this->blocksBytes($sec)
            . $this->blockLightBytes($sec, $sy)
            . $this->skyLightBytes($sec, $sy);
    }

    /** 会发光的方块：id => 亮度（1.8 的取值） */
    private const EMITTERS = [
        50 => 14,   // 火把
        51 => 15,   // 火
        62 => 13,   // 点着的熔炉
        74 => 9,    // 点着的红石矿
        89 => 15,   // 萤石
        90 => 11,   // 下界传送门
        94 => 7,    // 点着的红石中继器
        117 => 1,   // 酿造台
        119 => 15,  // 末地传送门
        124 => 15,  // 点着的红石灯
        138 => 15,  // 信标
        169 => 15,  // 海晶灯
    ];

    /**
     * 发出去的方块光：存档里的旧值当底，再按发光方块在段内扩散一遍。
     * 火把、萤石这些才亮得起来；同样只抬高不压低。
     */
    private function blockLightBytes(Tag $sec, int $sy): string
    {
        $arr = Nbt::bytes($sec, 'BlockLight');
        if ($arr === null || strlen($arr) !== 2048) {
            $arr = str_repeat("\x00", 2048);
        }

        $blocks = Nbt::bytes($sec, 'Blocks');
        if ($blocks === null || strlen($blocks) !== 4096) {
            return $arr;
        }

        $get = static function (string $s, int $i): int {
            $b = ord($s[$i >> 1]);

            return ($i & 1) ? ($b >> 4) : ($b & 0x0F);
        };
        $put = static function (string &$s, int $i, int $v): void {
            $bi = $i >> 1;
            $b = ord($s[$bi]);
            $s[$bi] = chr(($i & 1)
                ? (($b & 0x0F) | (($v & 0x0F) << 4))
                : (($b & 0xF0) | ($v & 0x0F)));
        };

        $queue = [];
        for ($i = 0; $i < 4096; $i++) {
            $emit = self::EMITTERS[ord($blocks[$i])] ?? 0;
            if ($emit > 0 && $get($arr, $i) < $emit) {
                $put($arr, $i, $emit);
            }
            if ($get($arr, $i) > 1) {
                $queue[] = $i;
            }
        }

        $head = 0;
        $count = count($queue);
        while ($head < $count) {
            $i = $queue[$head++];
            $l = $get($arr, $i);
            if ($l <= 1) {
                continue;
            }
            $ly = ($i >> 8) & 0x0F;
            $z = ($i >> 4) & 0x0F;
            $x = $i & 0x0F;
            $nei = [];
            if ($x > 0) {
                $nei[] = $i - 1;
            }
            if ($x < 15) {
                $nei[] = $i + 1;
            }
            if ($z > 0) {
                $nei[] = $i - 16;
            }
            if ($z < 15) {
                $nei[] = $i + 16;
            }
            if ($ly > 0) {
                $nei[] = $i - 256;
            }
            if ($ly < 15) {
                $nei[] = $i + 256;
            }
            foreach ($nei as $n) {
                if ($get($arr, $n) < $l - 1) {
                    $put($arr, $n, $l - 1);
                    $queue[] = $n;
                    $count++;
                }
            }
        }

        return $arr;
    }

    /**
     * 发出去的天空光：**只抬高、不压低**。
     *
     * 存档里有些格子的光是旧的（单机保存时就没算对），原样发过去客户端就画成纯黑；
     * 反过来，如果拿我们算的粗糙光照去覆盖客户端的，又会把人家算好的地方压暗
     * ——「越挖越黑」就是这么来的。
     *
     * 所以：地表以上一律给 15（按高度图），地下的保留 NBT 原值。
     */
    private function skyLightBytes(Tag $sec, int $sy): string
    {
        $arr = Nbt::bytes($sec, 'SkyLight');
        if ($arr === null || strlen($arr) !== 2048) {
            $arr = str_repeat("\x00", 2048);
        }

        $get = static function (string $s, int $i): int {
            $b = ord($s[$i >> 1]);

            return ($i & 1) ? ($b >> 4) : ($b & 0x0F);
        };
        $put = static function (string &$s, int $i, int $v): void {
            $bi = $i >> 1;
            $b = ord($s[$bi]);
            $s[$bi] = chr(($i & 1)
                ? (($b & 0x0F) | (($v & 0x0F) << 4))
                : (($b & 0xF0) | ($v & 0x0F)));
        };

        // 1) 地表以上直接 15
        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                $h = $this->heightAt($x, $z);
                for ($ly = 0; $ly < 16; $ly++) {
                    if ($sy * 16 + $ly < $h) {
                        continue;
                    }
                    $put($arr, ($ly << 8) | ($z << 4) | $x, 15);
                }
            }
        }

        // 2) 段内扩散：亮度逐格 -1。原版是整块 BFS，这里只在 16³ 里做，
        //    够把洞口侧壁、屋檐底下照亮，也不会跨段把事搞乱。
        $queue = [];
        for ($i = 0; $i < 4096; $i++) {
            if ($get($arr, $i) === 15) {
                $queue[] = $i;
            }
        }
        $head = 0;
        $count = count($queue);
        while ($head < $count) {
            $i = $queue[$head++];
            $l = $get($arr, $i);
            if ($l <= 1) {
                continue;
            }
            $ly = ($i >> 8) & 0x0F;
            $z = ($i >> 4) & 0x0F;
            $x = $i & 0x0F;
            $nei = [];
            if ($x > 0) {
                $nei[] = $i - 1;
            }
            if ($x < 15) {
                $nei[] = $i + 1;
            }
            if ($z > 0) {
                $nei[] = $i - 16;
            }
            if ($z < 15) {
                $nei[] = $i + 16;
            }
            if ($ly > 0) {
                $nei[] = $i - 256;
            }
            if ($ly < 15) {
                $nei[] = $i + 256;
            }
            foreach ($nei as $n) {
                if ($get($arr, $n) < $l - 1) {
                    $put($arr, $n, $l - 1);
                    $queue[] = $n;
                    $count++;
                }
            }
        }

        return $arr;
    }

    /** 一段的方块数据：4096 个小端 ushort，(id << 4) | meta */
    private function blocksBytes(Tag $sec): string
    {
        $blocks = (string) Nbt::bytes($sec, 'Blocks');
        $add = Nbt::bytes($sec, 'Add');
        $meta = Nbt::bytes($sec, 'Data') ?? str_repeat("\x00", 2048);

        $ids = array_values(unpack('C*', $blocks));
        $out = '';

        for ($i = 0; $i < 4096; $i++) {
            $id = $ids[$i];
            if ($add !== null) {
                $id |= self::nibble($add, $i) << 8;
            }
            $v = ($id << 4) | self::nibble($meta, $i);
            $out .= self::$packCache[$v] ??= pack('v', $v);
        }

        return $out;
    }

    private static function nibble(string $s, int $i): int
    {
        $b = ord($s[$i >> 1]);

        // 原版 NibbleArray：偶数索引在低 4 位，奇数索引在高 4 位
        return ($i & 1) ? ($b >> 4) : ($b & 0x0F);
    }

    // ---------------- 改方块 ----------------

    /**
     * 找出区块里所有指定方块 id 的世界坐标。
     * 直接在 Blocks 字节数组里扫，不逐格调 blockState —— 区块有 65536 格，
     * 每个玩家发一次区块都逐格查会太慢。
     *
     * @return array<int, array{0:int,1:int,2:int}>
     */
    public function blockPositions(int $id): array
    {
        $needle = chr($id & 0xFF);
        $out = [];
        foreach ($this->sections() as $sec) {
            $sy = Nbt::int($sec, 'Y');
            if ($sy < 0 || $sy > 15) {
                continue;
            }
            $blocks = Nbt::bytes($sec, 'Blocks');
            if ($blocks === null || strlen($blocks) !== 4096) {
                continue;
            }
            $add = Nbt::bytes($sec, 'Add');
            $offset = 0;
            while (($i = strpos($blocks, $needle, $offset)) !== false) {
                $offset = $i + 1;
                if ($add !== null && self::nibble($add, $i) !== 0) {
                    continue;
                }
                $out[] = [
                    $this->cx * 16 + ($i & 15),
                    $sy * 16 + (($i >> 8) & 15),
                    $this->cz * 16 + (($i >> 4) & 15),
                ];
            }
        }

        return $out;
    }

    /** 返回 (blockId << 4) | meta */
    public function blockState(int $x, int $y, int $z): int
    {
        if ($y < 0 || $y > 255) {
            return 0;
        }
        $sec = $this->sectionFor($y >> 4);
        if ($sec === null) {
            return 0;
        }
        $i = (($y & 15) << 8) | ($z << 4) | $x;
        $blocks = Nbt::bytes($sec, 'Blocks');
        if ($blocks === null || !isset($blocks[$i])) {
            return 0;
        }

        $id = ord($blocks[$i]);
        $add = Nbt::bytes($sec, 'Add');
        if ($add !== null) {
            $id |= self::nibble($add, $i) << 8;
        }
        $meta = Nbt::bytes($sec, 'Data');
        $m = $meta !== null ? self::nibble($meta, $i) : 0;

        return ($id << 4) | $m;
    }

    /** 返回 true 表示真的变了（需要广播 + 存盘）。 */
    public function setBlock(int $x, int $y, int $z, int $id, int $meta): bool
    {
        if ($y < 0 || $y > 255 || $x < 0 || $x > 15 || $z < 0 || $z > 15) {
            return false;
        }
        $old = $this->blockState($x, $y, $z);
        $new = (($id << 4) | ($meta & 0x0F));
        if ($old === $new) {
            return false;
        }

        $sy = $y >> 4;
        $sec = $this->sectionFor($sy);
        if ($sec === null) {
            $sec = $this->addSection($sy);
        }

        $i = (($y & 15) << 8) | ($z << 4) | $x;

        $blocks = Nbt::bytes($sec, 'Blocks') ?? str_repeat("\x00", 4096);
        $sec->value['Blocks'] = Tag::byteArray(
            substr_replace($blocks, chr($id & 0xFF), $i, 1)
        );

        $data = Nbt::bytes($sec, 'Data') ?? str_repeat("\x00", 2048);
        $bi = $i >> 1;
        $b = ord($data[$bi]);
        $b = ($i & 1)
            ? (($b & 0x0F) | (($meta & 0x0F) << 4))
            : (($b & 0xF0) | ($meta & 0x0F));
        $sec->value['Data'] = Tag::byteArray(substr_replace($data, chr($b), $bi, 1));

        // 高度要跟着变，不然天空光照旧算错。
        $this->updateHeight($x, $z);
        // 挖空了就试着把光放进来。注意是「只提亮、绝不压暗」——
        // 之前按高度把整段刷成 0，挖一格整片变黑，那是我加错的。
        if ($id === 0) {
            $this->brightenBlock($x, $y, $z);
        }

        $this->payload = null;
        $this->dirty = true;

        return true;
    }

    private function addSection(int $sy): Tag
    {
        $sec = Tag::compound([
            'Y' => Tag::byte($sy),
            'Blocks' => Tag::byteArray(str_repeat("\x00", 4096)),
            'Data' => Tag::byteArray(str_repeat("\x00", 2048)),
            'BlockLight' => Tag::byteArray(str_repeat("\x00", 2048)),
            'SkyLight' => Tag::byteArray(str_repeat("\xff", 2048)),
        ]);

        $level = $this->level();
        if ($level === null) {
            throw new \RuntimeException('区块缺少 Level 标签');
        }

        $sectionsTag = $level->value['Sections'] ?? null;
        if ($sectionsTag === null || $sectionsTag->type !== Tag::LIST) {
            $level->value['Sections'] = Tag::list(Tag::COMPOUND, [$sec]);
        } else {
            $sectionsTag->value['items'][] = $sec;
        }

        return $sec;
    }

    /** 某一列最高非空气方块 +1（HeightMap 的第 x + z*16 项）。 */
    public function heightAt(int $x, int $z): int
    {
        $level = $this->level();
        $hm = $level?->value['HeightMap'] ?? null;
        if ($hm !== null && $hm->type === Tag::INT_ARRAY && count($hm->value) === 256) {
            return (int) $hm->value[$z * 16 + $x];
        }

        for ($y = 255; $y >= 0; $y--) {
            if ($this->blockState($x, $y, $z) !== 0) {
                return $y + 1;
            }
        }

        return 0;
    }

    /** 重算某一列的高度（从最高 section 往下找第一个非空气）。 */
    public function updateHeight(int $x, int $z): void
    {
        $h = 0;
        for ($sy = 15; $sy >= 0; $sy--) {
            $sec = $this->sectionFor($sy);
            if ($sec === null) {
                continue;
            }
            $blocks = Nbt::bytes($sec, 'Blocks');
            if ($blocks === null) {
                continue;
            }
            for ($ly = 15; $ly >= 0; $ly--) {
                $i = ($ly << 8) | ($z << 4) | $x;
                if (ord($blocks[$i]) !== 0) {
                    $h = $sy * 16 + $ly + 1;
                    break 2;
                }
            }
        }

        $level = $this->level();
        $hm = $level?->value['HeightMap'] ?? null;
        if ($hm !== null && $hm->type === Tag::INT_ARRAY && count($hm->value) === 256) {
            $hm->value[$z * 16 + $x] = $h;
        }
    }

    public function skyLightAt(int $x, int $y, int $z): int
    {
        if ($y < 0 || $y > 255 || $x < 0 || $x > 15 || $z < 0 || $z > 15) {
            return 0;
        }
        $sec = $this->sectionFor($y >> 4);
        $arr = $sec === null ? null : Nbt::bytes($sec, 'SkyLight');
        if ($arr === null || strlen($arr) !== 2048) {
            return 0;
        }

        return self::nibble($arr, (($y & 15) << 8) | ($z << 4) | $x);
    }

    public function setSkyLightAt(int $x, int $y, int $z, int $v): void
    {
        if ($y < 0 || $y > 255 || $x < 0 || $x > 15 || $z < 0 || $z > 15) {
            return;
        }
        $sec = $this->sectionFor($y >> 4);
        if ($sec === null) {
            return;
        }
        $arr = Nbt::bytes($sec, 'SkyLight');
        if ($arr === null || strlen($arr) !== 2048) {
            $arr = str_repeat("\x00", 2048);
        }
        $i = (($y & 15) << 8) | ($z << 4) | $x;
        $bi = $i >> 1;
        $b = ord($arr[$bi]);
        $b = ($i & 1)
            ? (($b & 0x0F) | (($v & 0x0F) << 4))
            : (($b & 0xF0) | ($v & 0x0F));
        $sec->value['SkyLight'] = Tag::byteArray(substr_replace($arr, chr($b), $bi, 1));
    }

    /**
     * 挖空之后，如果这一列上方已经没有遮挡，就把这一格点成天空光 15。
     * 只改这一格，绝不批量刷 0 —— 宁可「亮得不够」，也不要「一片黑」。
     */
    public function brightenBlock(int $x, int $y, int $z): void
    {
        if ($y < $this->heightAt($x, $z)) {
            return; // 上面还压着方块，本来就不该有天空光
        }
        if ($this->skyLightAt($x, $y, $z) < 15) {
            $this->setSkyLightAt($x, $y, $z, 15);
        }
    }

    /** 最高非空气方块 +1，改完方块顺手更新一下 HeightMap。 */
    public function recalcHeightMap(): void
    {
        $level = $this->level();
        if ($level === null) {
            return;
        }
        $heights = [];
        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                $h = 0;
                for ($y = 255; $y >= 0; $y--) {
                    if ($this->blockState($x, $y, $z) !== 0) {
                        $h = $y + 1;
                        break;
                    }
                }
                $heights[] = $h;
            }
        }
        $level->value['HeightMap'] = Tag::intArray($heights);
    }
}
