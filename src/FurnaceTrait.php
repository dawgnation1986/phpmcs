<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 熔炉：用石砖(98)承载，界面借用客户端原生的熔炉 GUI。
 *
 * 为什么不放真熔炉(61)：熔炉和箱子一样是 BlockContainer，客户端一设这个方块
 * 就走 Chunk.setBlockState 的 TE 分支并抛 NPE（已经用 /blocktest 对照证实）。
 * 所以方块用石砖，是不是熔炉由 cache/furnaces-*.json 里的坐标记录决定。
 */
trait FurnaceTrait
{
    /** @var array<string, array{in:?array,fuel:?array,out:?array,burn:int,cook:int,maxBurn:int}> */
    private array $furnaces = [];
    private bool $furnacesLoaded = false;
    private string $furnaceFile = '';
    private float $lastFurnaceTick = 0.0;

    /** 烧炼配方：输入 id => 输出 id */
    private const SMELT = [
        15 => 265,   // 铁矿 -> 铁锭
        14 => 266,   // 金矿 -> 金锭
        16 => 263,   // 煤矿 -> 煤炭
        56 => 264,   // 钻石矿 -> 钻石
        129 => 388,  // 绿宝石矿 -> 绿宝石
        73 => 331,   // 红石矿 -> 红石
        21 => 351,   // 青金石矿 -> 蓝色染料(meta 4)
        12 => 20,    // 沙子 -> 玻璃
        4 => 1,      // 圆石 -> 石头
        337 => 336,  // 黏土球 -> 红砖
        82 => 172,   // 黏土块 -> 硬化黏土
        81 => 351,   // 仙人掌 -> 绿色染料(meta 2)
        17 => 263,   // 原木 -> 木炭(meta 1)
        87 => 405,   // 地狱岩 -> 地狱砖
        319 => 320,  // 生猪排 -> 熟猪排
        363 => 364,  // 生牛肉 -> 牛排
        365 => 366,  // 生鸡肉 -> 熟鸡肉
        423 => 424,  // 生羊肉 -> 熟羊肉
        349 => 350,  // 生鱼 -> 熟鱼
        392 => 393,  // 生马铃薯 -> 烤马铃薯
        153 => 406,  // 下界石英矿 -> 下界石英
        12  => 20,   // 沙子 -> 玻璃（重复，保留幂等）
    ];

    /** 少数产物带 meta（木炭、染料） */
    private const SMELT_META = [
        17 => 1,
        81 => 2,
        21 => 4,
    ];

    /** 燃料 id => 能烧多少 tick */
    private const FUEL = [
        263 => 1600,  // 煤炭 / 木炭（meta 无关）
        173 => 16000, // 煤炭块
        327 => 20000, // 岩浆桶
        369 => 2400,  // 烈焰棒
        5 => 300,     // 木板
        17 => 300,    // 原木
        280 => 100,   // 木棍
        54 => 300,    // 箱子
        146 => 300,   // 陷阱箱
        47 => 300,    // 书架
        58 => 300,    // 工作台
        25 => 300,    // 音符盒
        85 => 300,    // 栅栏
        107 => 300,   // 栅栏门
        65 => 300,    // 梯子
        6 => 100,     // 树苗
        35 => 100,    // 羊毛
        268 => 200, 269 => 200, 270 => 200, 271 => 200, 290 => 200,  // 木制工具/剑
    ];

    private function furnaceFile(): string
    {
        if ($this->furnaceFile !== '') {
            return $this->furnaceFile;
        }
        $dir = __DIR__ . '/../cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $this->furnaceFile = $dir . '/furnaces.json';

        return $this->furnaceFile;
    }

    private function loadFurnaces(): void
    {
        if ($this->furnacesLoaded) {
            return;
        }
        $this->furnacesLoaded = true;
        $f = $this->furnaceFile();
        if (!is_file($f)) {
            return;
        }
        $json = json_decode((string) @file_get_contents($f), true);
        if (is_array($json)) {
            $this->furnaces = $json;
        }
    }

    private function saveFurnaces(): void
    {
        @file_put_contents($this->furnaceFile(), json_encode($this->furnaces), LOCK_EX);
    }

    /**
     * 供 AnvilWorld 写区块用：本区块内所有熔炉的 TE（原版格式）。
     * 槽位：0=输入 1=燃料 2=产出。
     */
    public function furnaceTilesForChunk(int $cx, int $cz): array
    {
        $this->loadFurnaces();
        $out = [];
        foreach ($this->furnaces as $k => $f) {
            $p = explode(',', (string) $k);
            if (count($p) !== 3) {
                continue;
            }
            [$x, $y, $z] = [(int) $p[0], (int) $p[1], (int) $p[2]];
            if (($x >> 4) !== $cx || ($z >> 4) !== $cz) {
                continue;
            }
            $items = [];
            foreach (['in' => 0, 'fuel' => 1, 'out' => 2] as $slot => $slotId) {
                $it = $f[$slot] ?? null;
                if (!is_array($it) || !isset($it['id']) || (int) ($it['count'] ?? 0) <= 0) {
                    continue;
                }
                $items[] = \PhpMc\Nbt\Tag::compound([
                    'Slot' => \PhpMc\Nbt\Tag::byte($slotId),
                    'id' => \PhpMc\Nbt\Tag::short((int) $it['id']),
                    'Count' => \PhpMc\Nbt\Tag::byte((int) $it['count']),
                    'Damage' => \PhpMc\Nbt\Tag::short((int) ($it['damage'] ?? 0)),
                ]);
            }
            $out[] = \PhpMc\Nbt\Tag::compound([
                'id' => \PhpMc\Nbt\Tag::str('Furnace'),
                'x' => \PhpMc\Nbt\Tag::int($x),
                'y' => \PhpMc\Nbt\Tag::int($y),
                'z' => \PhpMc\Nbt\Tag::int($z),
                'Items' => \PhpMc\Nbt\Tag::list(\PhpMc\Nbt\Tag::COMPOUND, $items),
                'BurnTime' => \PhpMc\Nbt\Tag::short((int) ($f['burn'] ?? 0)),
                'CookTime' => \PhpMc\Nbt\Tag::short((int) ($f['cook'] ?? 0)),
            ]);
        }

        return $out;
    }

    private function furnaceKey(int $x, int $y, int $z): string
    {
        return $x . ',' . $y . ',' . $z;
    }

    private function hasFurnace(int $x, int $y, int $z): bool
    {
        $this->loadFurnaces();

        return isset($this->furnaces[$this->furnaceKey($x, $y, $z)]);
    }

    private function ensureFurnace(int $x, int $y, int $z): void
    {
        $this->loadFurnaces();
        $k = $this->furnaceKey($x, $y, $z);
        if (isset($this->furnaces[$k])) {
            return;
        }
        $this->furnaces[$k] = ['in' => null, 'fuel' => null, 'out' => null, 'burn' => 0, 'cook' => 0, 'maxBurn' => 0];
        $this->saveFurnaces();
    }

    private function removeFurnace(int $x, int $y, int $z): void
    {
        $this->loadFurnaces();
        $k = $this->furnaceKey($x, $y, $z);
        if (!isset($this->furnaces[$k])) {
            return;
        }
        unset($this->furnaces[$k]);
        $this->saveFurnaces();
    }

    /** 打开熔炉界面：windowId 3 + 原生 GUI id "minecraft:furnace" + slotCount 0 */
    private function openFurnace(Connection $c, int $x, int $y, int $z): void
    {
        $this->ensureFurnace($x, $y, $z);
        $this->loadFurnaces();
        $c->chest = null;                       // 复用「当前打开的容器」字段
        $c->furnace = ['x' => $x, 'y' => $y, 'z' => $z, 'cursor' => $c->cursor];
        $c->cursor = null;

        $c->send(self::C_OPEN_WINDOW, (new Writer())
            // windowId 用 1：箱子界面用 1 是实测能打开的。
            // 3 和 2 都试过，客户端都无视。
            ->byte(1)
            ->string('minecraft:furnace')
            ->string(json_encode(['text' => '熔炉'], JSON_UNESCAPED_UNICODE))
            // slotCount 给真实的 39（3 熔炉槽 + 36 背包）。
            // 之前给 0 是照抄工作台，但工作台是 GUI 特殊分支，熔炉可能要看这个数。
            ->byte(39));

        $this->sendFurnaceWindow($c);
    }

    private function sendFurnaceWindow(Connection $c): void
    {
        if ($c->furnace === null) {
            return;
        }
        $k = $this->furnaceKey($c->furnace['x'], $c->furnace['y'], $c->furnace['z']);
        $f = $this->furnaces[$k] ?? ['in' => null, 'fuel' => null, 'out' => null];
        $slots = [$f['in'], $f['fuel'], $f['out']];
        for ($i = 9; $i <= 44; $i++) {
            $slots[] = $c->inventory[$i] ?? null;
        }
        $w = (new Writer())->byte(1)->short(count($slots));
        foreach ($slots as $it) {
            $w->raw($this->slotBytes($it));
        }
        $c->send(self::C_WINDOW_ITEMS, $w);
    }

    private function closeFurnace(Connection $c): void
    {
        if ($c->furnace === null) {
            return;
        }
        $this->saveFurnaces();
        $c->furnace = null;
    }

    /**
     * 点击熔炉界面。用和箱子同一套 clickedStacks 语义：
     * 左键整叠交换、右键一半、Shift 在「熔炉 3 格」和「玩家背包」之间搬。
     * 之前是「直接把格子设成光标的东西」，会吞掉原物品、也拿不回光标，
     * 玩家看到的就是「点了没反应 / 物品凭空多一个」。
     */
    private function clickFurnace(Connection $c, int $slot, int $button, int $mode, ?array $cursor): void
    {
        if ($c->furnace === null) {
            return;
        }
        $k = $this->furnaceKey($c->furnace['x'], $c->furnace['y'], $c->furnace['z']);
        if (!isset($this->furnaces[$k])) {
            // 记录没了一眼：与其静默丢掉这次点击（表现为「物品放进去了但不烧」），
            // 不如当场补一条，让它至少能正常工作
            $this->ensureFurnace($c->furnace['x'], $c->furnace['y'], $c->furnace['z']);
            if (!isset($this->furnaces[$k])) {
                return;
            }
        }
        if ($slot < 0 || $slot > 38) {
            return;
        }

        // 0-2 熔炉槽，3-38 玩家背包（对应 inventory 9-44）
        $get = function (int $s) use ($c, $k) {
            if ($s === 0) { return $this->furnaces[$k]['in']; }
            if ($s === 1) { return $this->furnaces[$k]['fuel']; }
            if ($s === 2) { return $this->furnaces[$k]['out']; }

            return $c->inventory[9 + ($s - 3)] ?? null;
        };
        $put = function (int $s, ?array $item) use ($c, $k): void {
            if ($s === 0) { $this->furnaces[$k]['in'] = $item; return; }
            if ($s === 1) { $this->furnaces[$k]['fuel'] = $item; return; }
            if ($s === 2) { $this->furnaces[$k]['out'] = $item; return; }
            $inv = 9 + ($s - 3);
            if ($item === null) { unset($c->inventory[$inv]); } else { $c->inventory[$inv] = $item; }
        };

        $current = $get($slot);

        if ($mode === 1 && $current !== null) {
            // Shift：熔炉槽 <-> 背包
            $range = $slot < 3 ? [3, 38] : [0, 2];
            for ($i = $range[0]; $i <= $range[1]; $i++) {
                if ($get($i) !== null) {
                    continue;
                }
                $put($i, $current);
                $put($slot, null);
                $this->saveFurnaces();
                $this->sendFurnaceWindow($c);

                return;
            }
            $this->sendFurnaceWindow($c);

            return;
        }

        // 创造模式中键：mode=3 是「克隆」，把这一格的物品复制一份到光标上。
        // 之前只认 mode=0，整个包被丢掉 —— 客户端拿到了东西、服端没有，
        // 再放进背包就「消失」了。
        if ($mode === 3) {
            if ($slot >= 0 && $slot <= 38 && $current !== null) {
                $c->cursor = $current;
                $this->sendFurnaceWindow($c);
                $this->sendPlayerInventory($c);
            }
            return;
        }

        if ($mode !== 0 || ($button !== 0 && $button !== 1)) {
            return;
        }
        if ($slot === 2) {
            // 输出槽只能取，不能放
            if ($c->cursor === null && $current !== null) {
                $c->cursor = $current;
                $put(2, null);
            }
        } else {
            [$next, $newCursor] = self::clickedStacks($current, $c->cursor, $button);
            $c->cursor = $newCursor;
            $put($slot, $next);
        }
        $this->saveFurnaces();
        $this->sendFurnaceWindow($c);
        // 背包也刷一遍：只刷窗口的话，客户端对背包的本地预测会和服
        // 务端分叉，再打开一次 GUI 就会看到「多出来一份」。
        $this->sendPlayerInventory($c);
    }

    /** 每 2 秒跑一次烧炼 */
    private function tickFurnaces(float $now): void
    {
        if ($now - $this->lastFurnaceTick < 2.0) {
            return;
        }
        $elapsed = $now - $this->lastFurnaceTick;
        $this->lastFurnaceTick = $now;
        $this->loadFurnaces();
        if ($this->furnaces === []) {
            return;
        }

        $ticks = (int) round($elapsed * 20);
        $changed = false;
        foreach ($this->furnaces as $k => &$f) {
            $in = $f['in'];
            $canSmelt = $in !== null && isset(self::SMELT[$in['id']])
                && ($f['out'] === null
                    || ($f['out']['id'] === self::SMELT[$in['id']] && $f['out']['count'] < 64));

            if ($f['burn'] > 0) {
                $f['burn'] -= $ticks;
                $changed = true;
            } elseif ($canSmelt && $f['fuel'] !== null && isset(self::FUEL[$f['fuel']['id']])) {
                $f['maxBurn'] = self::FUEL[$f['fuel']['id']];
                $f['burn'] = $f['maxBurn'];
                $f['fuel'] = ($f['fuel']['count'] > 1)
                    ? ['id' => $f['fuel']['id'], 'count' => $f['fuel']['count'] - 1, 'damage' => $f['fuel']['damage'] ?? 0]
                    : null;
                $changed = true;
            }

            if ($f['burn'] > 0 && $canSmelt) {
                $f['cook'] += $ticks;
                if ($f['cook'] >= 200) {
                    $f['cook'] = 0;
                    $outId = self::SMELT[$in['id']];
                    $f['out'] = $f['out'] === null
                        ? ['id' => $outId, 'count' => 1, 'damage' => self::SMELT_META[$in['id']] ?? 0]
                        : ['id' => $outId, 'count' => $f['out']['count'] + 1, 'damage' => self::SMELT_META[$in['id']] ?? 0];
                    $f['in'] = ($in['count'] > 1)
                        ? ['id' => $in['id'], 'count' => $in['count'] - 1, 'damage' => $in['damage'] ?? 0]
                        : null;
                    $changed = true;
                }
            } else {
                $f['cook'] = max(0, $f['cook'] - $ticks);
            }
        }
        unset($f);

        // 燃烧时把方块设成「点亮」状态（meta 的 0x8 位），灭了再清掉。
        // 原版就是这样让炉口发亮的，我们之前从没改过方块状态，所以烧着也黑着。
        foreach ($this->furnaces as $k2 => $f2) {
            $parts2 = explode(',', (string) $k2);
            if (count($parts2) !== 3) {
                continue;
            }
            [$bx, $by, $bz] = [(int) $parts2[0], (int) $parts2[1], (int) $parts2[2]];
            $chunk2 = $this->world->chunk($bx >> 4, $bz >> 4);
            $state = $chunk2->blockState($bx & 15, $by, $bz & 15);
            $curId = $state >> 4;
            if ($curId !== 61 && $curId !== 62) {
                continue;   // 已经不是熔炉了
            }
            // 1.8 里「点亮的熔炉」是**另一个方块 id**：
            //   61 = furnace，62 = lit_furnace（见 afh.java:860-861）
            // 改 meta 的 0x8 位没用 —— 客户端根本不看那一位。
            $meta = $state & 15;
            $wantId = ((int) ($f2['burn'] ?? 0)) > 0 ? 62 : 61;
            if ($curId === $wantId) {
                continue;
            }
            if ($chunk2->setBlock($bx & 15, $by, $bz & 15, $wantId, $meta)) {
                $chunk2->dirty = true;
                foreach ($this->players() as $pp) {
                    $pp->send(self::C_BLOCK_CHANGE, (new Writer())
                        ->raw($this->packPositionBytes($bx, $by, $bz))
                        ->varInt(($wantId << 4) | $meta));
                }
            }
        }

        if ($changed) {
            $this->saveFurnaces();
            foreach ($this->players() as $p) {
                if ($p->furnace !== null) {
                    $this->sendFurnaceWindow($p);
                }
            }
        }
    
        // 同步打开着熔炉界面的玩家：0=余火 1=已烧时长 2=总时长
        foreach ($this->furnaces as $fk => $ff) {
            $parts = explode(',', (string) $fk);
            if (count($parts) !== 3) {
                continue;
            }
            [$fx, $fy, $fz] = [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
            foreach ($this->conns as $fp) {
                if ($fp->closed || $fp->furnace === null) {
                    continue;
                }
                if ((int) $fp->furnace['x'] !== $fx || (int) $fp->furnace['y'] !== $fy
                    || (int) $fp->furnace['z'] !== $fz) {
                    continue;
                }
                // 1.8 的四个属性：0=余火 1=燃料总时长 2=已烧时长 3=总时长
                // （之前写成 0/1/2，整体错位，所以箭头永远不动）
                foreach ([[0, min(200, (int) ($ff['burn'] ?? 0))],
                          [1, min(200, (int) ($ff['maxBurn'] ?? 200))],
                          [2, min(200, (int) ($ff['cook'] ?? 0))],
                          [3, 200]] as [$prop, $val]) {
                    $fp->send(self::C_WINDOW_PROPERTY, (new Writer())
                        ->byte(1)->short($prop)->short($val));
                }
            }
        }
    }
}
