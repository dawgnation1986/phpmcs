<?php
declare(strict_types=1);

namespace PhpMc;

use PhpMc\Nbt\Nbt;
use PhpMc\Nbt\Tag;

/**
 * 附魔台（方块 116）。
 *
 * 客户端容器 xs 的结构（反编译确认）：
 *   槽 0  要附魔的物品
 *   槽 1  青金石（zy.aW，damage 4）
 *   窗口属性 0/1/2 = 附魔 id（-1 表示这一格不可用）
 *   窗口属性 4/5/6 = 等级消耗（界面右侧显示的数字）
 * 客户端点按钮发 0x11 Enchant Item（窗口 id + 按钮序号）。
 */
trait EnchantTrait
{
    /** @var array 正在开的附魔台 ['x','y','z','slots'=>[2],'costs'=>[3],'ids'=>[3]] */
    private array $enchants = [];

    /** 附魔 id（1.8）：0 保护 / 16 锋利 / 32 效率 / 34 耐久 / 48 力量 */
    private const ENCH_NAMES = [
        0 => '保护', 16 => '锋利', 32 => '效率', 34 => '耐久', 48 => '力量',
        20 => '火焰附加', 21 => '抢夺', 33 => '精准采集', 35 => '时运',
    ];

    private function openEnchantTable(Connection $c, int $x, int $y, int $z): void
    {
        $c->furnace = null;
        $this->closeChest($c);
        $this->closeWorkbench($c);
        $c->brewing = null;
        $this->returnCraftGrid($c);

        $k = $x . ',' . $y . ',' . $z;
        if (!isset($this->enchants[$k])) {
            $this->enchants[$k] = ['slots' => [null, null], 'costs' => [0, 0, 0], 'ids' => [-1, -1, -1]];
        }
        $c->enchantTable = ['x' => $x, 'y' => $y, 'z' => $z];

        $c->send(self::C_OPEN_WINDOW, (new Writer())
            ->byte(1)
            ->string('minecraft:enchanting_table')
            ->string(json_encode(['text' => '附魔台'], JSON_UNESCAPED_UNICODE) ?: '{"text":"Enchant"}')
            // 附魔台和工作台一样是「有 GUI id 的容器」，slotCount 必须发 0，
            // 发真实槽位数客户端会当成未知类型、退回箱子界面。
            ->byte(0));
        $this->sendEnchantWindow($c);
        $this->recalcEnchant($c);
    }

    /** 附魔台被挖掉：把槽里的东西吐出来 */
    private function breakEnchantTable(int $x, int $y, int $z, ?Connection $c): void
    {
        $k = $x . ',' . $y . ',' . $z;
        if (!isset($this->enchants[$k])) {
            return;
        }
        foreach ($this->enchants[$k]['slots'] as $it) {
            if (is_array($it)) {
                $this->spawnDrop($x, $y, $z, (int) $it['id'], (int) $it['count'],
                    (int) ($it['damage'] ?? 0), $c);
            }
        }
        unset($this->enchants[$k]);
        $this->log("  附魔台被挖掉，槽内物品已掉落 @ {$x},{$y},{$z}");
    }

    private function enchantKey(Connection $c): ?string
    {
        if ($c->enchantTable === null) {
            return null;
        }

        return $c->enchantTable['x'] . ',' . $c->enchantTable['y'] . ',' . $c->enchantTable['z'];
    }

    private function enchantSlots(Connection $c): array
    {
        $k = $this->enchantKey($c);
        $e = $k !== null ? ($this->enchants[$k] ?? null) : null;
        $out = [$e['slots'][0] ?? null, $e['slots'][1] ?? null];
        for ($i = 9; $i <= 44; $i++) {
            $out[] = $c->inventory[$i] ?? null;
        }

        return $out;
    }

    private function sendEnchantWindow(Connection $c): void
    {
        $slots = $this->enchantSlots($c);
        $w = (new Writer())->byte(1)->short(count($slots));
        foreach ($slots as $it) {
            $w->raw($this->slotBytes($it));
        }
        $c->send(self::C_WINDOW_ITEMS, $w);
    }

    /** 按物品类型和书架数量算三档消耗（简化版：不查真附魔表） */
    private function recalcEnchant(Connection $c): void
    {
        $k = $this->enchantKey($c);
        if ($k === null || !isset($this->enchants[$k])) {
            return;
        }
        $item = $this->enchants[$k]['slots'][0] ?? null;
        if ($item === null) {
            $this->enchants[$k]['costs'] = [0, 0, 0];
            $this->enchants[$k]['ids'] = [-1, -1, -1];
        } else {
            $id = (int) $item['id'];
            $ench = $this->enchantFor($id);
            if ($ench < 0) {
                $this->enchants[$k]['costs'] = [0, 0, 0];
                $this->enchants[$k]['ids'] = [-1, -1, -1];
            } else {
                // 三档：等级 1 / 2 / 3，消耗递增
                $this->enchants[$k]['costs'] = [5, 15, 30];
                $this->enchants[$k]['ids'] = [$ench, $ench, $ench];
            }
        }
        // 反编译 xs：g=消耗 发在属性 0~2，h=附魔 发在属性 4~6，
        // 而且 h 是打包的：id | (等级 << 8)。之前两者写反了，
        // 客户端把「消耗 5」当成「附魔 id 5」→ 显示水下呼吸。
        foreach ([0, 1, 2] as $i => $idx) {
            $c->send(self::C_WINDOW_PROPERTY, (new Writer())
                ->byte(1)->short($idx)->short($this->enchants[$k]['costs'][$i]));
        }
        foreach ([0, 1, 2] as $i => $idx) {
            $id = $this->enchants[$k]['ids'][$i];
            $packed = $id < 0 ? 0 : ($id | (($i + 1) << 8));
            $c->send(self::C_WINDOW_PROPERTY, (new Writer())
                ->byte(1)->short(4 + $idx)->short($packed));
        }
    }

    /** 这件物品能被附什么（简化：按类型给一个固定附魔） */
    private function enchantFor(int $itemId): int
    {
        // 剑
        if (in_array($itemId, [267, 268, 272, 276, 283], true)) {
            return 16;   // 锋利
        }
        // 镐/锹/斧
        if (in_array($itemId, [
            256, 257, 258, 269, 270, 271, 273, 274, 275, 277, 278, 279, 284, 285, 286,
        ], true)) {
            return 32;   // 效率
        }
        // 弓
        if ($itemId === 261) {
            return 48;   // 力量
        }
        // 盔甲四件套
        if (in_array($itemId, [
            298, 299, 300, 301, 302, 303, 304, 305, 306, 307, 308, 309,
            310, 311, 312, 313, 314, 315, 316, 317,
        ], true)) {
            return 0;    // 保护
        }

        return -1;
    }

    private function clickEnchant(Connection $c, int $slot, int $button, int $mode, ?array $cursor): void
    {
        $k = $this->enchantKey($c);
        if ($k === null || !isset($this->enchants[$k])) {
            return;
        }
        if ($slot < 0 || $slot > 37) {
            return;
        }
        $get = function (int $s) use ($c, $k) {
            if ($s < 2) {
                return $this->enchants[$k]['slots'][$s] ?? null;
            }

            return $c->inventory[9 + ($s - 2)] ?? null;
        };
        $put = function (int $s, ?array $item) use ($c, $k): void {
            if ($s < 2) {
                $this->enchants[$k]['slots'][$s] = $item;

                return;
            }
            $inv = 9 + ($s - 2);
            if ($item === null) {
                unset($c->inventory[$inv]);
            } else {
                $c->inventory[$inv] = $item;
            }
        };

        $current = $get($slot);
        if ($mode === 3) {
            if ($current !== null) {
                $c->cursor = $current;
                $this->sendEnchantWindow($c);
                $this->sendPlayerInventory($c);
            }

            return;
        }
        if ($mode === 1 && $current !== null) {
            $range = $slot < 2 ? [2, 37] : [0, 1];
            for ($i = $range[0]; $i <= $range[1]; $i++) {
                if ($get($i) !== null) {
                    continue;
                }
                $put($i, $current);
                $put($slot, null);
                $this->recalcEnchant($c);
                $this->sendEnchantWindow($c);
                $this->sendPlayerInventory($c);

                return;
            }
            $this->sendEnchantWindow($c);

            return;
        }
        if ($mode !== 0 || ($button !== 0 && $button !== 1)) {
            return;
        }
        [$next, $newCursor] = self::clickedStacks($current, $c->cursor, $button);
        $c->cursor = $newCursor;
        $put($slot, $next);
        $this->recalcEnchant($c);
        $this->sendEnchantWindow($c);
        $this->sendPlayerInventory($c);
    }

    /** 客户端点了第 $button 个附魔按钮（0x11） */
    private function doEnchant(Connection $c, int $button): void
    {
        $k = $this->enchantKey($c);
        if ($k === null || !isset($this->enchants[$k]) || $button < 0 || $button > 2) {
            return;
        }
        $item = $this->enchants[$k]['slots'][0] ?? null;
        $lapis = $this->enchants[$k]['slots'][1] ?? null;
        $cost = $this->enchants[$k]['costs'][$button];
        $enchId = $this->enchants[$k]['ids'][$button];
        if ($item === null || $enchId < 0 || $cost <= 0) {
            return;
        }
        $lapisNeed = $button + 1;

        // 创造模式：不消耗经验、也不要青金石（原版创造就是这样）
        if ($c->gamemode !== 1) {
            if ($c->xpLevel < $cost) {
                $this->sendChat($c, ['text' => '等级不够，需要 ' . $cost . ' 级', 'color' => 'red'], 1);

                return;
            }
            if ($lapis === null || (int) $lapis['count'] < $lapisNeed) {
                $this->sendChat($c, ['text' => '青金石不够，需要 ' . $lapisNeed . ' 个', 'color' => 'red'], 1);

                return;
            }

            // 扣等级（总经验按曲线倒推）
            $c->xpTotal = max(0, $c->xpTotal - $this->xpForLevels($cost));
            $c->xpLevel = max(0, $c->xpLevel - $cost);
            $c->xpProgress = 0.0;
            $this->loadXpStore();
            $this->xpStore[strtolower($c->username)] = $c->xpTotal;
            $this->saveXpStore();
            $c->send(self::C_SET_EXPERIENCE, (new Writer())
                ->float($c->xpProgress)->varInt($c->xpLevel)->varInt($c->xpTotal));

            // 扣青金石
            $lapis['count'] = (int) $lapis['count'] - $lapisNeed;
            $this->enchants[$k]['slots'][1] = (int) $lapis['count'] > 0 ? $lapis : null;
        }

        // 写附魔 NBT：ench 列表 + RepairCost
        $lvl = $button + 1;
        $item['ench'] = [['id' => $enchId, 'lvl' => $lvl]];
        $this->enchants[$k]['slots'][0] = $item;

        $this->log(sprintf('  %s 附魔 %s（%s %d 级，消耗 %d 级）',
            $c->username, self::ENCH_NAMES[$enchId] ?? $enchId, self::ENCH_NAMES[$enchId] ?? '', $lvl, $cost));

        $this->recalcEnchant($c);
        $this->sendEnchantWindow($c);
        $this->sendPlayerInventory($c);
    }

    /** 从 0 级升到 $levels 级需要多少点经验 */
    private function xpForLevels(int $levels): int
    {
        if ($levels <= 16) {
            return $levels * 17;
        }
        if ($levels <= 31) {
            return 17 * 16 + ($levels - 16) * 37;
        }

        return 17 * 16 + 37 * 15 + ($levels - 31) * 62;
    }
}
