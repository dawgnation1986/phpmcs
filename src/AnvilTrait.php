<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 铁砧：两个输入槽 + 一个产物槽。
 *
 * 1.8.9 的窗口类型是 minecraft:anvil，槽位：
 *   0 左输入 / 1 右输入（材料）/ 2 产物
 *
 * 这里先实现三件事（够用）：
 *   ① 同名工具/装备叠加：耐久相加 + 附魔取高的
 *   ② 单件 + 材料（铁锭/钻石等）：修复 25% 耐久
 *   ③ 只放一件：产物就是它自己（等于重命名前的预览）
 *
 * 没做的：重命名（需要处理 0x17 自定义载荷 MC|ItemName）、附魔书、经验消耗。
 */
trait AnvilTrait
{
    private const ANVIL_BLOCK = 145;

    /** 工具/装备 id => 修复用的材料 id（简化：只列常见的几种） */
    private const ANVIL_MATERIAL = [
        256 => 265, 257 => 265, 258 => 265, 267 => 265, 292 => 265,
        306 => 265, 307 => 265, 308 => 265, 309 => 265,
        273 => 4, 274 => 4, 275 => 4, 272 => 4, 291 => 4,
        276 => 264, 277 => 264, 278 => 264, 279 => 264, 293 => 264,
        310 => 264, 311 => 264, 312 => 264, 313 => 264,
        283 => 266, 284 => 266, 285 => 266, 286 => 266, 294 => 266,
        298 => 334, 299 => 334, 300 => 334, 301 => 334,
    ];

    private function openAnvil(Connection $c, int $x, int $y, int $z): void
    {
        // 一个玩家同时只留一个容器状态
        $c->chest = null;
        $c->furnace = null;
        $c->brewing = null;
        $c->anvil = ['x' => $x, 'y' => $y, 'z' => $z, 'slot0' => null, 'slot1' => null];
        $c->cursor = null;

        // 1.8 里铁砧是「容器 id 8」。之前沿用箱子的 windowId=1，
        // 客户端就按普通容器排版（把 39 个槽摊成 4 行），布局全乱。
        $c->send(self::C_OPEN_WINDOW, (new Writer())
            ->byte(8)
            ->string('minecraft:anvil')
            ->string(json_encode(['text' => '铁砧'], JSON_UNESCAPED_UNICODE))
            // slotCount 给 0：工作台就是这么开的（它也是有 GUI id 的容器）。
            // 给真实槽位数时客户端会按普通容器排版，把铁砧画成 3x9 的格子。
            ->byte(0));

        if ($this->debugLog) {
            $this->log('  [铁砧] 发 Open Window：windowId=8 type=minecraft:anvil slots=0');
        }

        $this->sendAnvilWindow($c);
        $this->log("  {$c->username} 打开铁砧 {$x},{$y},{$z}");
    }

    private function sendAnvilWindow(Connection $c): void
    {
        if ($c->anvil === null) {
            return;
        }
        // 只推铁砧自己的 3 个槽。
        // 之前把 39 个槽（含背包）一起推给 windowId=8，但背包那段编号映射
        // 和客户端的不一致 —— 客户端会用这份数据覆盖背包显示，
        // 表现就是「从铁砧拿出来的东西放进背包后消失了」。
        // 背包统一走 sendPlayerInventory（window 0）。
        $slots = [
            $c->anvil['slot0'],
            $c->anvil['slot1'],
            $this->anvilResult($c),
        ];
        $w = (new Writer())->byte(8)->short(count($slots));
        foreach ($slots as $it) {
            $w->raw($this->slotBytes($it));
        }
        $c->send(self::C_WINDOW_ITEMS, $w);
        $this->sendPlayerInventory($c);
    }

    /** 算产物：叠加耐久 / 合并附魔 / 单件透传 */
    private function anvilResult(Connection $c): ?array
    {
        if ($c->anvil === null) {
            return null;
        }
        $a = $c->anvil['slot0'];
        $b = $c->anvil['slot1'];
        $name = $c->anvil['name'] ?? null;

        if ($a === null) {
            return null;
        }
        if ($b === null) {
            return $this->anvilName($a, $name);   // 只放一件：原样（可能只是改名）
        }

        // ① 同一件物品：耐久相加（上限 100%）+ 附魔取高
        if ((int) $a['id'] === (int) $b['id']) {
            $out = $a;
            if (isset($a['damage']) || isset($b['damage'])) {
                $max = self::TOOL_MAX[(int) $a['id']] ?? 0;
                if ($max > 0) {
                    $dmg = (int) ($a['damage'] ?? 0) + (int) ($b['damage'] ?? 0);
                    $out['damage'] = max(0, $dmg - (int) ($max * 0.12));   // 原版约 12% 奖励
                }
            }
            $ench = [];
            foreach ([$a['ench'] ?? [], $b['ench'] ?? []] as $list) {
                foreach ($list as $e) {
                    $id = (int) $e['id'];
                    $lv = (int) $e['lvl'];
                    if (!isset($ench[$id]) || $ench[$id] < $lv) {
                        $ench[$id] = $lv;
                    }
                }
            }
            if ($ench !== []) {
                $out['ench'] = [];
                foreach ($ench as $id => $lv) {
                    $out['ench'][] = ['id' => $id, 'lvl' => $lv];
                }
            }

            return $this->anvilName($out, $name);
        }

        // ② 工具 + 材料：修复 25%
        $mat = self::ANVIL_MATERIAL[(int) $a['id']] ?? 0;
        if ($mat > 0 && (int) $b['id'] === $mat) {
            $max = self::TOOL_MAX[(int) $a['id']] ?? 0;
            if ($max > 0) {
                $out = $a;
                $out['damage'] = max(0, (int) ($a['damage'] ?? 0) - (int) ($max * 0.25));

                return $this->anvilName($out, $name);
            }
        }

        return null;
    }

    /** 把铁砧里输入的名字贴到产物上（名字以 display.Name 存在物品里） */
    private function anvilName(?array $item, ?string $name): ?array
    {
        if ($item === null || $name === null || $name === '') {
            return $item;
        }
        $item['name'] = $name;

        return $item;
    }

    /** 铁砧里的点击 */
    private function clickAnvil(Connection $c, int $slot, int $button, int $mode, ?array $cursor): void
    {
        if ($c->anvil === null) {
            return;
        }

        // 槽 0/1：放东西
        if ($slot === 0 || $slot === 1) {
            $key = 'slot' . $slot;
            $cur = $c->anvil[$key];
            [$next, $newCursor] = self::clickedStacks($cur, $c->cursor, $button);
            $c->anvil[$key] = $next;
            $c->cursor = $newCursor;
            $this->sendAnvilWindow($c);
            $this->sendCursor($c);
            if ($this->debugLog) {
                $this->log(sprintf('  [铁砧] 槽%d 放入 %s，产物=%s', $slot,
                    $next === null ? '空' : $next['id'] . 'x' . $next['count'],
                    json_encode($this->anvilResult($c))));
            }

            return;
        }

        // 槽 2：取产物（两件都消耗，各 -1）
        if ($slot === 2) {
            $out = $this->anvilResult($c);
            if ($out === null) {
                $this->sendAnvilWindow($c);
                $this->sendCursor($c);

                return;
            }
            // 【只给 1 个】产物里继承了输入槽的 count（比如 14 根线），
            // 如果整组发出去、却只扣每格 1 个，玩家就会「放 14 根、拿回 14 根还扣了 1」——
            // 净赚 13。原版铁砧一次只出一个成品。
            $this->giveItem($c, (int) $out['id'], 1, (int) ($out['damage'] ?? 0),
                empty($out['ench']) ? [] : ['ench' => $out['ench']]);
            $this->consumeAnvilSlot($c, 'slot0');
            $this->consumeAnvilSlot($c, 'slot1');
            $this->log("  {$c->username} 铁砧取出 " . $out['id'] . 'x1');
            $this->sendAnvilWindow($c);
            $this->sendCursor($c);

            return;
        }

        // 背包槽：铁砧窗口的编号是
        //   0/1 输入、2 产物、3~29 主背包、30~38 快捷栏
        // 而 clickInventory 用的是「背包下标」（9~44）。
        // 两套编号差 6，直接传会让点击落到错误的格子上 —— 表现就是「东西放不上去」。
        if ($slot >= 3) {
            $invSlot = $slot + 6;              // 3 -> 9, 30 -> 36 ✓
            $this->clickInventory($c, $invSlot, $button, $mode, $cursor);
        }
        $this->sendAnvilWindow($c);
    }

    private function consumeAnvilSlot(Connection $c, string $key): void
    {
        if ($c->anvil === null) {
            return;
        }
        $it = $c->anvil[$key];
        if ($it === null) {
            return;
        }
        if ((int) $it['count'] > 1) {
            $c->anvil[$key] = self::withCount($it, (int) $it['count'] - 1);
        } else {
            $c->anvil[$key] = null;
        }
    }

    private function closeAnvil(Connection $c): void
    {
        if ($c->anvil === null) {
            return;
        }
        // 把两个槽里的东西还给玩家
        foreach (['slot0', 'slot1'] as $key) {
            $it = $c->anvil[$key];
            if ($it !== null) {
                $this->giveItem($c, (int) $it['id'], (int) $it['count'], (int) ($it['damage'] ?? 0),
                    empty($it['ench']) ? [] : ['ench' => $it['ench']]);
            }
        }
        $c->anvil = null;
        $this->sendPlayerInventory($c);
    }
}
