<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 合成：玩家 2x2、工作台 3x3、配方匹配。
 * 单独放一个 trait，避免把 Server.php 继续撑大。
 */
trait CraftingTrait
{
    /**
     * 2x2 合成配方。只做生存最常用的几个；输出必须和客户端本地配方一致，
     * 否则客户端会先显示结果、点击时被服务端打回去。
     *
     * @param array<int,array{id:int,count:int,damage:int}|null> $g 行优先 2x2
     */

    /**
     * 用反编译得到的完整配方表匹配。
     * $g 是 3x3 行优先的格子（2x2 的调用方把它摆在左上角）。
     * 返回 [id, count, damage] 或 null。
     */
    private static function matchTable(array $g): ?array
    {
        // 收集非空格子
        $cells = [];
        for ($i = 0; $i < 9; $i++) {
            $cells[$i] = $g[$i] ?? null;
        }

        // 有形状：裁掉空行空列后逐格比
        [$trim, $h, $w] = self::trimGrid($cells, 3, 3);
        foreach ([...RecipeTable::SHAPED, ...RecipeTable::SHAPED_EXTRA] as [$out, $pat, $keys]) {
            $ph = count($pat);
            $pw = max(array_map('strlen', $pat));
            if ($ph !== $h || $pw !== $w) {
                continue;
            }
            foreach ([false, true] as $mirror) {
                $ok = true;
                for ($r = 0; $r < $ph && $ok; $r++) {
                    for ($c = 0; $c < $pw; $c++) {
                        $ch = $pat[$r][$c] ?? ' ';
                        $cell = $trim[$r * $w + $c] ?? null;
                        if ($ch === ' ') {
                            if ($cell !== null) {
                                $ok = false;
                                break;
                            }
                            continue;
                        }
                        if ($cell === null || !isset($keys[$ch])) {
                            $ok = false;
                            break;
                        }
                        [$id, $meta] = $keys[$ch];
                        if ((int) $cell['id'] !== $id || ((int) ($cell['damage'] ?? 0)) !== $meta) {
                            $ok = false;
                            break;
                        }
                    }
                    if ($ok && $mirror) {
                        // 镜像：把图案左右翻过来再比一次
                        $pat[$r] = strrev($pat[$r]);
                    }
                }
                if ($ok) {
                    return ['id' => $out[0], 'count' => $out[1], 'damage' => $out[2]];
                }
            }
        }

        // 无形状：多重集合比较
        $mine = [];
        foreach ($cells as $it) {
            if ($it !== null) {
                $mine[] = (int) $it['id'] . ':' . (int) ($it['damage'] ?? 0);
            }
        }
        sort($mine);
        foreach (RecipeTable::SHAPELESS as [$out, $ins]) {
            $want = [];
            foreach ($ins as [$id, $meta]) {
                $want[] = $id . ':' . $meta;
            }
            sort($want);
            if ($want === $mine) {
                return ['id' => $out[0], 'count' => $out[1], 'damage' => $out[2]];
            }
        }

        return null;
    }

    /** 裁掉四周空行空列，返回 [格子, 高, 宽] */
    private static function trimGrid(array $g, int $rows, int $cols): array
    {
        $minR = $rows;
        $maxR = -1;
        $minC = $cols;
        $maxC = -1;
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                if (($g[$r * $cols + $c] ?? null) !== null) {
                    $minR = min($minR, $r);
                    $maxR = max($maxR, $r);
                    $minC = min($minC, $c);
                    $maxC = max($maxC, $c);
                }
            }
        }
        if ($maxR < 0) {
            return [[], 0, 0];
        }
        $out = [];
        for ($r = $minR; $r <= $maxR; $r++) {
            for ($c = $minC; $c <= $maxC; $c++) {
                $out[] = $g[$r * $cols + $c] ?? null;
            }
        }

        return [$out, $maxR - $minR + 1, $maxC - $minC + 1];
    }

    private static function matchCraft2x2(array $g): ?array
    {
        // 先查反编译出来的完整配方表（2x2 摆在 3x3 的左上角）
        $grid9 = [
            $g[0] ?? null, $g[1] ?? null, null,
            $g[2] ?? null, $g[3] ?? null, null,
            null, null, null,
        ];
        $hit = self::matchTable($grid9);
        if ($hit !== null) {
            return $hit;
        }

        $items = array_values(array_filter($g, static fn ($it) => $it !== null));

        // 原木 -> 4 木板
        if (count($items) === 1) {
            $it = $items[0];
            if ($it['id'] === 17 && $it['damage'] >= 0 && $it['damage'] <= 3) {
                return ['id' => 5, 'count' => 4, 'damage' => $it['damage']];
            }
            if ($it['id'] === 162 && $it['damage'] >= 0 && $it['damage'] <= 1) {
                return ['id' => 5, 'count' => 4, 'damage' => 4 + $it['damage']];
            }
        }

        // 单个物品的转换（原版是无序配方，客户端本地也会算出来，
        // 服务端不认就会出现「结果闪一下就消失」）
        if (count($items) === 1) {
            $it = $items[0];
            switch ($it['id']) {
                case 369: return ['id' => 377, 'count' => 2, 'damage' => 0];   // 烈焰棒 -> 2 烈焰粉
                case 352: return ['id' => 351, 'count' => 3, 'damage' => 15];  // 骨头 -> 3 骨粉
                case 338: return ['id' => 353, 'count' => 1, 'damage' => 0];   // 甘蔗 -> 糖
                case 348: return ['id' => 351, 'count' => 1, 'damage' => 1];   // 墨囊 -> 墨汁
                case 39:  return ['id' => 351, 'count' => 2, 'damage' => 3];   // 棕色蘑菇 -> 可可豆(近似)
            }
            // 3 根线 -> 1 羊毛（竖着排）
            if ($it['id'] === 287) {
                $n = count(array_filter($g, static fn ($x) => $x !== null && $x['id'] === 287));
                if ($n === 3) {
                    return ['id' => 35, 'count' => 1, 'damage' => 0];
                }
            }
        }

        // 4 石头 -> 4 石砖
        if (count($items) === 4
            && $g[0] !== null && $g[1] !== null && $g[2] !== null && $g[3] !== null
            && $g[0]['id'] === 1 && $g[1]['id'] === 1 && $g[2]['id'] === 1 && $g[3]['id'] === 1) {
            return ['id' => 98, 'count' => 4, 'damage' => 0];
        }

        // 4 沙子 -> 4 沙石
        if (count($items) === 4
            && $g[0] !== null && $g[1] !== null && $g[2] !== null && $g[3] !== null
            && $g[0]['id'] === 12 && $g[1]['id'] === 12 && $g[2]['id'] === 12 && $g[3]['id'] === 12) {
            return ['id' => 24, 'count' => 1, 'damage' => 0];
        }

        // 2 木板竖着 -> 4 木棍
        if (count($items) === 2 && $items[0]['id'] === 5 && $items[1]['id'] === 5) {
            if (($g[0] !== null && $g[2] !== null && $g[1] === null && $g[3] === null)
                || ($g[1] !== null && $g[3] !== null && $g[0] === null && $g[2] === null)) {
                return ['id' => 280, 'count' => 4, 'damage' => 0];
            }
        }

        // 4 木板 -> 工作台
        if (count($items) === 4
            && $g[0] !== null && $g[1] !== null && $g[2] !== null && $g[3] !== null
            && $g[0]['id'] === 5 && $g[1]['id'] === 5 && $g[2]['id'] === 5 && $g[3]['id'] === 5) {
            return ['id' => 58, 'count' => 1, 'damage' => 0];
        }

        // 煤/木炭 + 木棍 -> 4 火把
        if (count($items) === 2) {
            $coal = static fn (?array $it) => $it !== null && $it['id'] === 263;
            $stick = static fn (?array $it) => $it !== null && $it['id'] === 280;
            if (($coal($g[0]) && $stick($g[2]) && $g[1] === null && $g[3] === null)
                || ($coal($g[1]) && $stick($g[3]) && $g[0] === null && $g[2] === null)) {
                return ['id' => 50, 'count' => 4, 'damage' => 0];
            }
        }

        return null;
    }

    /**
     * 3x3 合成：2x2 以内的配方先裁出来套用 2x2 表；
     * 3x3 的只做箱子（8 木板围一圈）和熔炉（8 圆石围一圈）。
     */
    private static function matchCraft3x3(array $g): ?array
    {
        // 先查反编译出来的完整配方表
        $hit = self::matchTable($g);
        if ($hit !== null) {
            return $hit;
        }

        // 床：一排 3 个羊毛，正下方一排 3 个木板。
        // 允许摆在「第 1~2 行」或「第 2~3 行」——之前只认顶两行，
        // 玩家把羊毛放中间行就匹配不到。
        for ($row = 0; $row <= 1; $row++) {
            $used = [];
            $ok = true;
            for ($c = 0; $c < 3; $c++) {
                $top = $g[$row * 3 + $c] ?? null;
                $bot = $g[($row + 1) * 3 + $c] ?? null;
                if ($top === null || $top['id'] !== 35 || $bot === null || $bot['id'] !== 5) {
                    $ok = false;
                    break;
                }
                $used[] = $row * 3 + $c;
                $used[] = ($row + 1) * 3 + $c;
            }
            if (!$ok) {
                continue;
            }
            $extra = 0;
            for ($i = 0; $i < 9; $i++) {
                if (!in_array($i, $used, true) && ($g[$i] ?? null) !== null) {
                    $extra++;
                }
            }
            if ($extra === 0) {
                return [355, 1];   // 床
            }
        }

        $minR = 3;
        $maxR = -1;
        $minC = 3;
        $maxC = -1;
        $count = 0;
        for ($r = 0; $r < 3; $r++) {
            for ($c = 0; $c < 3; $c++) {
                if ($g[$r * 3 + $c] === null) {
                    continue;
                }
                $minR = min($minR, $r);
                $maxR = max($maxR, $r);
                $minC = min($minC, $c);
                $maxC = max($maxC, $c);
                $count++;
            }
        }
        if ($count === 0) {
            return null;
        }

        if ($maxR - $minR + 1 <= 2 && $maxC - $minC + 1 <= 2) {
            $small = [null, null, null, null];
            for ($r = $minR; $r <= $maxR; $r++) {
                for ($c = $minC; $c <= $maxC; $c++) {
                    $small[($r - $minR) * 2 + ($c - $minC)] = $g[$r * 3 + $c];
                }
            }

            return self::matchCraft2x2($small);
        }

        // 工具：木板/圆石/铁/钻石/金 + 木棍
        $crop = [];
        for ($r = $minR; $r <= $maxR; $r++) {
            $row = [];
            for ($c = $minC; $c <= $maxC; $c++) {
                $row[] = $g[$r * 3 + $c];
            }
            $crop[] = $row;
        }
        $tool = self::matchToolPattern($crop);
        if ($tool !== null) {
            return $tool;
        }

        if ($count === 8 && $g[4] === null) {
            $ringId = null;
            $ok = true;
            for ($r = 0; $r < 3; $r++) {
                for ($c = 0; $c < 3; $c++) {
                    if ($r === 1 && $c === 1) {
                        continue;
                    }
                    $id = $g[$r * 3 + $c]['id'] ?? 0;
                    if ($ringId === null) {
                        $ringId = $id;
                    }
                    if ($id !== $ringId) {
                        $ok = false;
                        break 2;
                    }
                }
            }
            if ($ok) {
                if ($ringId === 5) {
                    return ['id' => 54, 'count' => 1, 'damage' => 0];   // 箱子
                }
                if ($ringId === 4) {
                    return ['id' => 61, 'count' => 1, 'damage' => 0];   // 熔炉
                }
            }
        }

        return null;
    }

    /**
     * 工具配方的形状匹配（裁剪后的网格）。
     * M=材料，S=木棍，.=空。斧头和锄头同时接受左右镜像。
     */
    private static function matchToolPattern(array $crop): ?array
    {
        $patterns = [
            'pickaxe' => [['MMM', '.S.', '.S.']],
            'axe' => [['MM', 'MS', '.S'], ['MM', 'SM', 'S.']],
            'shovel' => [['M', 'S', 'S']],
            'sword' => [['M', 'M', 'S']],
            'hoe' => [['MM', '.S', '.S'], ['MM', 'S.', 'S.']],
        ];
        $outputs = [
            5 => ['pickaxe' => 270, 'axe' => 271, 'shovel' => 269, 'sword' => 268, 'hoe' => 290],
            4 => ['pickaxe' => 274, 'axe' => 275, 'shovel' => 273, 'sword' => 272, 'hoe' => 291],
            265 => ['pickaxe' => 257, 'axe' => 258, 'shovel' => 256, 'sword' => 267, 'hoe' => 292],
            264 => ['pickaxe' => 278, 'axe' => 279, 'shovel' => 277, 'sword' => 276, 'hoe' => 293],
            266 => ['pickaxe' => 285, 'axe' => 286, 'shovel' => 284, 'sword' => 283, 'hoe' => 294],
        ];

        foreach ($outputs as $matId => $out) {
            foreach ($patterns as $name => $variants) {
                foreach ($variants as $rows) {
                    if (count($crop) !== count($rows)) {
                        continue;
                    }
                    $ok = true;
                    foreach ($rows as $r => $row) {
                        if (strlen($row) !== count($crop[$r])) {
                            $ok = false;
                            break;
                        }
                        for ($c = 0; $c < strlen($row); $c++) {
                            $ch = $row[$c];
                            $it = $crop[$r][$c];
                            if ($ch === '.') {
                                if ($it !== null) {
                                    $ok = false;
                                    break 2;
                                }
                                continue;
                            }
                            if ($it === null) {
                                $ok = false;
                                break 2;
                            }
                            if ($ch === 'M' && $it['id'] !== $matId) {
                                $ok = false;
                                break 2;
                            }
                            if ($ch === 'S' && $it['id'] !== 280) {
                                $ok = false;
                                break 2;
                            }
                        }
                    }
                    if ($ok) {
                        return ['id' => $out[$name], 'count' => 1, 'damage' => 0];
                    }
                }
            }
        }

        return null;
    }

    /** 合成格变了以后，把输出槽 0 的新结果发给客户端 */
    private function updateCraftOutput(Connection $c): void
    {
        $this->sendWindowSlot($c, 0, 0, self::matchCraft2x2($c->craftGrid));
    }

    /**
     * Shift+点击输出：一次把所有材料能合成的份数都做出来，
     * 上限受合成格材料和背包剩余空间限制。
     */
    private function craftAll(Connection $c, bool $workbench, array $out): bool
    {
        $grid = $workbench ? $c->craft9 : $c->craftGrid;
        $max = PHP_INT_MAX;
        foreach ($grid as $it) {
            if ($it !== null) {
                $max = min($max, $it['count']);
            }
        }
        if ($max === PHP_INT_MAX) {
            $max = 0;
        }
        $space = $this->giveSpace($c, $out['id'], $out['damage']);
        $per = max(1, $out['count']);
        $crafts = min($max, intdiv($space, $per));
        if ($crafts <= 0) {
            $this->log(($workbench ? '工作台' : '2x2') . ' 背包满，合成输出保留');
            if ($workbench) {
                $this->updateWorkbenchOutput($c);
                $this->sendCursor($c);
            } else {
                $this->updateCraftOutput($c);
                $this->sendCursor($c);
            }

            return false;
        }

        $total = $out['count'] * $crafts;
        $this->giveItem($c, $out['id'], $total, $out['damage']);
        foreach ($grid as $i => $it) {
            if ($it === null) {
                continue;
            }
            $it['count'] -= $crafts;
            $grid[$i] = $it['count'] > 0 ? $it : null;
        }
        if ($workbench) {
            $c->craft9 = $grid;
            $this->updateWorkbenchOutput($c);
            $this->sendCursor($c);
        } else {
            $c->craftGrid = $grid;
            $this->updateCraftOutput($c);
            $this->sendCursor($c);
        }
        $this->log("  合成全部 " . ($workbench ? '工作台' : '2x2')
            . " -> {$out['id']}x{$total} crafts={$crafts}");

        return true;
    }

    /** 点击合成输出：消耗每个格子 1 个，把结果放到光标或背包 */
    private function takeCraftOutput(Connection $c, bool $shift): void
    {
        $out = self::matchCraft2x2($c->craftGrid);
        if ($out === null) {
            $this->log('  2x2 输出为空 grid=' . json_encode($c->craftGrid) . ' cursor=' . json_encode($c->cursor));
            // 客户端本地有完整配方表，会先画出结果；服务端不认就会「拿起来又消失」。
            // 与其让人以为丢东西，不如明说一句。
            $this->sendChat($c, ['text' => '服务端还不支持这个配方（结果已撤回）', 'color' => 'red'], 1);
            $this->updateCraftOutput($c);
            $this->sendCursor($c);

            return;
        }

        $this->log("  合成 2x2 -> {$out['id']}x{$out['count']} grid=" . json_encode($c->craftGrid)
            . ' cursor=' . json_encode($c->cursor));

        if ($shift) {
            // Shift+点击：一次性把材料能做的份数全部合成
            $this->craftAll($c, false, $out);

            return;
        } elseif ($c->cursor === null) {
            $c->cursor = $out;
        } elseif ($c->cursor['id'] === $out['id'] && $c->cursor['damage'] === $out['damage']
            && $c->cursor['count'] + $out['count'] <= self::maxStack($out['id'])) {
            $c->cursor['count'] += $out['count'];
        } else {
            // 光标上物品不同/放不下：塞进背包；背包也满就保留输出，不吞掉
            if (!$this->canGive($c, $out['id'], $out['count'], $out['damage'])) {
                $this->log('  2x2 背包满，合成输出保留');
                $this->updateCraftOutput($c);
                $this->sendCursor($c);

                return;
            }
            $this->giveItem($c, $out['id'], $out['count'], $out['damage']);
        }

        foreach ($c->craftGrid as $i => $it) {
            if ($it === null) {
                continue;
            }
            $it['count']--;
            $c->craftGrid[$i] = $it['count'] > 0 ? $it : null;
            $this->sendWindowSlot($c, 0, $i + 1, $c->craftGrid[$i]);
        }
        $this->updateCraftOutput($c);
        $this->sendCursor($c);
    }

    /** 关窗/断线时把 2x2 合成格里的东西还回背包 */
    private function returnCraftGrid(Connection $c): void
    {
        foreach ($c->craftGrid as $i => $it) {
            if ($it !== null) {
                // 合成格里的东西也要保住附魔（如果玩家把附魔装备放进去过）
                $extra = [];
                if (!empty($it['ench'])) {
                    $extra['ench'] = $it['ench'];
                }
                $this->giveItem($c, $it['id'], $it['count'], $it['damage'], $extra);
                $c->craftGrid[$i] = null;
            }
        }
        $this->sendWindowSlot($c, 0, 0, null);
        for ($i = 0; $i < 4; $i++) {
            $this->sendWindowSlot($c, 0, $i + 1, null);
        }
    }

    /** 打开工作台：1.8 客户端的 ContainerWorkbench windowId 就是 0，46 格 */
    private function openWorkbench(Connection $c): void
    {
        $c->furnace = null;   // 同上：清掉熔炉状态，避免点击分发串台
        if ($c->workbench) {
            $this->closeWorkbench($c);
        }
        $this->closeChest($c);
        $this->returnCraftGrid($c);
        $c->craft9 = array_fill(0, 9, null);
        $c->workbench = true;

        // 1.8 的两个坑：
        // 1) 客户端用 Open Window 包里的 windowId 设置 openContainer.windowId。
        //    玩家背包是 0、箱子是 1，工作台用 2；不能用 0，否则客户端会把
        //    工作台和玩家背包容器混在一起，3x3 格子的点击收不到。
        // 2) slotCount 对 GUI-ID 容器必须写 0，客户端才会走 GuiCrafting 分支；
        //    写 46 会走通用容器分支，画成一个箱子 GUI。
        //    真正的工作台 46 个槽位由后面的 Window Items 决定。
        $c->send(self::C_OPEN_WINDOW, (new Writer())
            ->byte(2)
            ->string('minecraft:crafting_table')
            ->string(json_encode(['text' => '工作台'], JSON_UNESCAPED_UNICODE) ?: '{"text":"Crafting"}')
            ->byte(0));

        $w = (new Writer())->byte(2)->short(46);
        $w->raw($this->slotBytes(null));
        for ($i = 0; $i < 9; $i++) {
            $w->raw($this->slotBytes($c->craft9[$i]));
        }
        for ($i = 9; $i <= 35; $i++) {
            $w->raw($this->slotBytes($c->inventory[$i] ?? null));
        }
        for ($i = 36; $i <= 44; $i++) {
            $w->raw($this->slotBytes($c->inventory[$i] ?? null));
        }
        $c->send(self::C_WINDOW_ITEMS, $w);
        $this->log("  {$c->username} 打开工作台");
    }

    private function closeWorkbench(Connection $c): void
    {
        if (!$c->workbench) {
            return;
        }
        $this->returnCraft9($c);
        $c->workbench = false;
        $c->send(0x2E, (new Writer())->byte(2));
    }

    private function returnCraft9(Connection $c): void
    {
        foreach ($c->craft9 as $i => $it) {
            if ($it !== null) {
                $this->giveItem($c, $it['id'], $it['count'], $it['damage']);
                $c->craft9[$i] = null;
            }
        }
        $this->sendWindowSlot($c, 2, 0, null);
        for ($i = 0; $i < 9; $i++) {
            $this->sendWindowSlot($c, 2, $i + 1, null);
        }
    }

    private function updateWorkbenchOutput(Connection $c): void
    {
        $this->sendWindowSlot($c, 2, 0, self::matchCraft3x3($c->craft9));
    }

    private function takeWorkbenchOutput(Connection $c, bool $shift): void
    {
        $out = self::matchCraft3x3($c->craft9);
        if ($out === null) {
            $this->log('  工作台输出为空 grid=' . json_encode($c->craft9) . ' cursor=' . json_encode($c->cursor));
            $this->updateWorkbenchOutput($c);
            $this->sendCursor($c);

            return;
        }

        $this->log("  合成工作台 -> {$out['id']}x{$out['count']} grid=" . json_encode($c->craft9)
            . ' cursor=' . json_encode($c->cursor));

        if ($shift) {
            // Shift+点击：一次性把材料能做的份数全部合成
            $this->craftAll($c, true, $out);

            return;
        } elseif ($c->cursor === null) {
            $c->cursor = $out;
        } elseif ($c->cursor['id'] === $out['id'] && $c->cursor['damage'] === $out['damage']
            && $c->cursor['count'] + $out['count'] <= self::maxStack($out['id'])) {
            $c->cursor['count'] += $out['count'];
        } else {
            // 光标上物品不同/放不下：塞进背包；背包也满就保留输出，不吞掉
            if (!$this->canGive($c, $out['id'], $out['count'], $out['damage'])) {
                $this->log('  工作台背包满，合成输出保留');
                $this->updateWorkbenchOutput($c);
                $this->sendCursor($c);

                return;
            }
            $this->giveItem($c, $out['id'], $out['count'], $out['damage']);
        }

        foreach ($c->craft9 as $i => $it) {
            if ($it === null) {
                continue;
            }
            $it['count']--;
            $c->craft9[$i] = $it['count'] > 0 ? $it : null;
            $this->sendWindowSlot($c, 2, $i + 1, $c->craft9[$i]);
        }
        $this->updateWorkbenchOutput($c);
        $this->sendCursor($c);
    }

    private function clickWorkbench(Connection $c, int $slot, int $button, int $mode, ?array $cursor): void
    {
        if (!$c->workbench || $slot < 0 || $slot > 45) {
            return;
        }
        $this->log("  工作台点击 slot={$slot} button={$button} mode={$mode} cursor=" . json_encode($c->cursor));
        if ($slot === 0) {
            $this->takeWorkbenchOutput($c, $mode === 1);

            return;
        }
        if ($slot >= 1 && $slot <= 9) {
            $i = $slot - 1;
            if ($mode === 1) {
                $it = $c->craft9[$i];
                if ($it !== null) {
                    $c->craft9[$i] = null;
                    $this->sendWindowSlot($c, 2, $slot, null);
                    $this->giveItem($c, $it['id'], $it['count'], $it['damage']);
                    $this->updateWorkbenchOutput($c);
                    $this->sendCursor($c);
                }

                return;
            }
            if ($mode !== 0 || ($button !== 0 && $button !== 1)) {
                $this->sendCursor($c);

                return;
            }
            [$next, $newCursor] = self::clickedStacks($c->craft9[$i], $c->cursor, $button);
            $c->craft9[$i] = $next;
            $c->cursor = $newCursor;
            $this->sendWindowSlot($c, 2, $slot, $next);
            $this->updateWorkbenchOutput($c);
            $this->sendCursor($c);

            return;
        }

        // 10..36 主背包，37..45 快捷栏
        $inv = $slot <= 36 ? 9 + ($slot - 10) : 36 + ($slot - 37);
        $cur = $c->inventory[$inv] ?? null;
        if ($mode === 0 && ($button === 0 || $button === 1)) {
            [$next, $newCursor] = self::clickedStacks($cur, $c->cursor, $button);
            $c->cursor = $newCursor;
            if ($next === null) {
                unset($c->inventory[$inv]);
            } else {
                $c->inventory[$inv] = $next;
            }
            $this->sendWindowSlot($c, 2, $slot, $next);
            $this->sendCursor($c);

            return;
        }

        if ($mode === 1 && $cur !== null) {
            // Shift+点击背包物品：优先快速填入 3x3 合成格
            $moved = $this->quickFillGrid($c, 2, $cur);
            if ($moved > 0) {
                $left = $cur['count'] - $moved;
                if ($left > 0) {
                    $cur['count'] = $left;
                    $c->inventory[$inv] = $cur;
                } else {
                    unset($c->inventory[$inv]);
                }

                return;
            }

            $range = $inv >= 36 ? [9, 35] : [36, 44];
            $remaining = $cur;
            $changed = [];
            for ($i = $range[0]; $i <= $range[1] && $remaining['count'] > 0; $i++) {
                $it = $c->inventory[$i] ?? null;
                if ($it === null || $it['id'] !== $remaining['id']
                    || $it['damage'] !== $remaining['damage']
                    || $it['count'] >= self::maxStack($remaining['id'])) {
                    continue;
                }
                $add = min(self::maxStack($remaining['id']) - $it['count'], $remaining['count']);
                $it['count'] += $add;
                $remaining['count'] -= $add;
                $c->inventory[$i] = $it;
                $changed[$i] = $it;
            }
            if ($remaining['count'] > 0) {
                for ($i = $range[0]; $i <= $range[1]; $i++) {
                    if (isset($c->inventory[$i])) {
                        continue;
                    }
                    $c->inventory[$i] = $remaining;
                    $changed[$i] = $remaining;
                    $remaining = null;
                    break;
                }
            }
            if ($remaining === null) {
                unset($c->inventory[$inv]);
                $changed[$inv] = null;
            } else {
                $c->inventory[$inv] = $remaining;
                if ($remaining['count'] !== $cur['count']) {
                    $changed[$inv] = $remaining;
                }
            }
            foreach ($changed as $invSlot => $it) {
                $slotNo = $invSlot <= 35 ? 10 + ($invSlot - 9) : 37 + ($invSlot - 36);
                $this->sendWindowSlot($c, 2, $slotNo, $it);
            }
            $this->sendCursor($c);

            return;
        }

        $this->sendCursor($c);
    }
}
