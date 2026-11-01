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

        $c->send(self::C_OPEN_WINDOW, (new Writer())
            ->byte(1)                                  // 沿用箱子的 windowId=1（实测可开）
            ->string('minecraft:anvil')
            ->string(json_encode(['text' => '铁砧'], JSON_UNESCAPED_UNICODE))
            ->byte(39));                               // 3 个铁砧槽 + 36 背包

        $this->sendAnvilWindow($c);
        $this->log("  {$c->username} 打开铁砧 {$x},{$y},{$z}");
    }

    private function sendAnvilWindow(Connection $c): void
    {
        if ($c->anvil === null) {
            return;
        }
        $slots = [
            $c->anvil['slot0'],
            $c->anvil['slot1'],
            $this->anvilResult($c),
        ];
        for ($i = 9; $i <= 44; $i++) {
            $slots[] = $c->inventory[$i] ?? null;
        }
        $w = (new Writer())->byte(1)->short(count($slots));
        foreach ($slots as $it) {
            $w->raw($this->slotBytes($it));
        }
        $c->send(self::C_WINDOW_ITEMS, $w);
    }

    /** 算产物：叠加耐久 / 合并附魔 / 单件透传 */
    private function anvilResult(Connection $c): ?array
    {
        if ($c->anvil === null) {
            return null;
        }
        $a = $c->anvil['slot0'];
        $b = $c->anvil['slot1'];

        if ($a === null) {
            return null;
        }
        if ($b === null) {
            return $a;                       // 只放一件：原样
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

            return $out;
        }

        // ② 工具 + 材料：修复 25%
        $mat = self::ANVIL_MATERIAL[(int) $a['id']] ?? 0;
        if ($mat > 0 && (int) $b['id'] === $mat) {
            $max = self::TOOL_MAX[(int) $a['id']] ?? 0;
            if ($max > 0) {
                $out = $a;
                $out['damage'] = max(0, (int) ($a['damage'] ?? 0) - (int) ($max * 0.25));

                return $out;
            }
        }

        return null;
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
            $this->giveItem($c, (int) $out['id'], (int) $out['count'], (int) ($out['damage'] ?? 0),
                empty($out['ench']) ? [] : ['ench' => $out['ench']]);
            $this->consumeAnvilSlot($c, 'slot0');
            $this->consumeAnvilSlot($c, 'slot1');
            $this->log("  {$c->username} 铁砧取出 " . $out['id'] . 'x' . $out['count']);
            $this->sendAnvilWindow($c);
            $this->sendCursor($c);

            return;
        }

        // 背包槽（9~44）：交给普通背包点击
        $this->clickInventory($c, $slot, $button, $mode, $cursor);
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
