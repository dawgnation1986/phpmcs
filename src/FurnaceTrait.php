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
        12 => 20,    // 沙子 -> 玻璃
        4 => 1,      // 圆石 -> 石头
        337 => 336,  // 黏土球 -> 红砖
        319 => 320,  // 生猪排 -> 熟猪排
        363 => 364,  // 生牛肉 -> 牛排
        365 => 366,  // 生鸡肉 -> 熟鸡肉
        349 => 350,  // 生鱼 -> 熟鱼
        392 => 393,  // 生马铃薯 -> 烤马铃薯
        103 => 360,  // 西瓜 -> 西瓜片（顺手）
        1 => 1,      // 占位，避免 match 空
    ];

    /** 燃料 id => 能烧多少 tick */
    private const FUEL = [
        263 => 1600,  // 煤炭
        173 => 16000, // 煤炭块
        5 => 300,     // 木板
        17 => 300,    // 原木
        280 => 100,   // 木棍
        54 => 300,    // 箱子
        47 => 300,    // 书架
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

    /** 点击熔炉界面：0-2 是熔炉槽，3-38 是玩家背包 */
    private function clickFurnace(Connection $c, int $slot, int $button, int $mode, ?array $cursor): void
    {
        if ($c->furnace === null) {
            return;
        }
        $k = $this->furnaceKey($c->furnace['x'], $c->furnace['y'], $c->furnace['z']);
        if (!isset($this->furnaces[$k])) {
            return;
        }

        if ($slot >= 3 && $slot <= 38) {
            $inv = 9 + ($slot - 3);
            $cur = $c->inventory[$inv] ?? null;
            if ($mode === 1 && $cur !== null) {          // shift：交给背包逻辑
                $this->clickInventory($c, $inv, $button, $mode, $cursor);
            } else {
                $c->inventory[$inv] = $cursor;
                if ($c->inventory[$inv] === null) {
                    unset($c->inventory[$inv]);
                }
            }
            $this->sendFurnaceWindow($c);

            return;
        }

        if ($slot < 0 || $slot > 2) {
            return;
        }
        $field = ['in', 'fuel', 'out'][$slot];
        if ($slot === 2) {
            // 输出槽只能取，不能塞
            if ($cursor === null && $this->furnaces[$k]['out'] !== null) {
                $c->cursor = $this->furnaces[$k]['out'];
                $this->furnaces[$k]['out'] = null;
            }
        } else {
            $c->cursor = $this->furnaces[$k][$field];
            $this->furnaces[$k][$field] = $cursor;
        }
        $this->saveFurnaces();
        $this->sendFurnaceWindow($c);
        $this->sendCursor($c);
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
                        ? ['id' => $outId, 'count' => 1, 'damage' => 0]
                        : ['id' => $outId, 'count' => $f['out']['count'] + 1, 'damage' => 0];
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

        if ($changed) {
            $this->saveFurnaces();
            foreach ($this->players() as $p) {
                if ($p->furnace !== null) {
                    $this->sendFurnaceWindow($p);
                }
            }
        }
    }
}
