<?php
declare(strict_types=1);

namespace PhpMc;

use PhpMc\Nbt\Nbt;
use PhpMc\Nbt\Tag;

/**
 * 酿造台（方块 117）。
 *
 * 槽位：0/1/2 = 药水瓶，3 = 材料，4 = 燃料（烈焰粉）。
 * 一次酿造 400 tick（20 秒）。
 *
 * 只做最常用的一条链：水瓶 + 地狱疣 → 粗制的药水，
 * 再往下支持几种常见材料。药水的 damage 编码（1.8）：
 *   damage = 类型 | (等级 << 5) | (延长 << 6) | (喷溅 << 14)
 */
trait BrewingTrait
{
    /** @var array<string,array> "x,y,z" => ['slots'=>[5格], 'brew'=>int, 'fuel'=>int] */
    private array $brews = [];
    private bool $brewsLoaded = false;
    private float $lastBrewTick = 0.0;

    /** 材料 id => [结果 damage, 消耗燃料] */
    private const BREW_RECIPES = [
        372 => 16,   // 地狱疣 -> 粗制的药水(16)
        353 => 1,    // 糖 -> 迅捷(1)
        348 => 2,    // 闪烁的西瓜 -> 治疗(2)（近似）
        382 => 3,    // 河豚 -> 水下呼吸(3)
        378 => 4,    // 岩浆膏 -> 抗火(4)
        375 => 5,    // 蜘蛛眼 -> 剧毒(5)
        377 => 6,    // 烈焰粉 -> 力量(6)
        370 => 7,    // 恶魂之泪 -> 再生(7)
        373 => 0,    // 药水（还原）
    ];

    private function brewFile(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'brewing.json';
    }

    private function loadBrews(): void
    {
        if ($this->brewsLoaded) {
            return;
        }
        $this->brewsLoaded = true;
        $f = $this->brewFile();
        if (is_file($f)) {
            $json = json_decode((string) @file_get_contents($f), true);
            if (is_array($json)) {
                $this->brews = $json;
            }
        }
    }

    private function saveBrews(): void
    {
        $dir = dirname($this->brewFile());
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($this->brewFile(), json_encode($this->brews), LOCK_EX);
    }

    private function ensureBrew(int $x, int $y, int $z): void
    {
        $this->loadBrews();
        $k = $x . ',' . $y . ',' . $z;
        if (!isset($this->brews[$k])) {
            $this->brews[$k] = ['slots' => [null, null, null, null, null], 'brew' => 0, 'fuel' => 0];
            $this->saveBrews();
        }
    }

    private function openBrewingStand(Connection $c, int $x, int $y, int $z): void
    {
        $this->ensureBrew($x, $y, $z);
        $c->furnace = null;
        $this->closeChest($c);
        $this->closeWorkbench($c);
        $this->returnCraftGrid($c);
        $c->brewing = ['x' => $x, 'y' => $y, 'z' => $z, 'cursor' => $c->cursor];
        $c->cursor = null;

        $c->send(self::C_OPEN_WINDOW, (new Writer())
            ->byte(5)                                  // 酿造台用 windowId=5，避开箱子/熔炉
            ->string('minecraft:brewing_stand')
            ->string(json_encode(['text' => '酿造台'], JSON_UNESCAPED_UNICODE))
            ->byte(41));                               // 5 格 + 36 背包

        $this->sendBrewWindow($c);
    }

    private function brewSlots(Connection $c): array
    {
        if ($c->brewing === null) {
            return [];
        }
        $k = $c->brewing['x'] . ',' . $c->brewing['y'] . ',' . $c->brewing['z'];
        $b = $this->brews[$k] ?? ['slots' => array_fill(0, 5, null)];
        $out = [];
        foreach ($b['slots'] as $it) {
            $out[] = $it;
        }
        for ($i = 9; $i <= 44; $i++) {
            $out[] = $c->inventory[$i] ?? null;
        }

        return $out;
    }

    private function sendBrewWindow(Connection $c): void
    {
        if ($c->brewing === null) {
            return;
        }
        $slots = $this->brewSlots($c);
        $w = (new Writer())->byte(5)->short(count($slots));
        foreach ($slots as $it) {
            $w->raw($this->slotBytes($it));
        }
        $c->send(self::C_WINDOW_ITEMS, $w);
        $this->sendBrewProgress($c);
    }

    private function sendBrewProgress(Connection $c): void
    {
        if ($c->brewing === null) {
            return;
        }
        $k = $c->brewing['x'] . ',' . $c->brewing['y'] . ',' . $c->brewing['z'];
        $b = $this->brews[$k] ?? ['brew' => 0, 'fuel' => 0];
        // 0 = 已酿时长，1 = 燃料
        $c->send(self::C_WINDOW_PROPERTY, (new Writer())->byte(5)->short(0)->short(min(400, (int) $b['brew'])));
        $c->send(self::C_WINDOW_PROPERTY, (new Writer())->byte(5)->short(1)->short((int) $b['fuel']));
    }

    private function clickBrewing(Connection $c, int $slot, int $button, int $mode, ?array $cursor): void
    {
        if ($c->brewing === null) {
            return;
        }
        $this->log("  酿造点击 slot={$slot} button={$button} mode={$mode} cursor=" . json_encode($c->cursor));
        $k = $c->brewing['x'] . ',' . $c->brewing['y'] . ',' . $c->brewing['z'];
        if (!isset($this->brews[$k])) {
            $this->ensureBrew($c->brewing['x'], $c->brewing['y'], $c->brewing['z']);
        }
        $get = function (int $s) use ($c, $k) {
            if ($s < 5) {
                return $this->brews[$k]['slots'][$s] ?? null;
            }
            return $c->inventory[9 + ($s - 5)] ?? null;
        };
        $put = function (int $s, ?array $item) use ($c, $k): void {
            if ($s < 5) {
                $this->brews[$k]['slots'][$s] = $item;
                return;
            }
            $inv = 9 + ($s - 5);
            if ($item === null) {
                unset($c->inventory[$inv]);
            } else {
                $c->inventory[$inv] = $item;
            }
        };

        $current = $get($slot);
        if ($mode === 3) {
            if ($slot >= 0 && $slot <= 40 && $current !== null) {
                $c->cursor = $current;
                $this->sendBrewWindow($c);
                $this->sendPlayerInventory($c);
            }
            return;
        }
        if ($mode === 1 && $current !== null) {
            $range = $slot < 5 ? [5, 40] : [0, 4];
            for ($i = $range[0]; $i <= $range[1]; $i++) {
                if ($get($i) !== null) {
                    continue;
                }
                $put($i, $current);
                $put($slot, null);
                $this->saveBrews();
                $this->sendBrewWindow($c);
                $this->sendPlayerInventory($c);
                return;
            }
            $this->sendBrewWindow($c);
            return;
        }
        if ($mode !== 0 || ($button !== 0 && $button !== 1)) {
            return;
        }
        [$next, $newCursor] = self::clickedStacks($current, $c->cursor, $button);
        $c->cursor = $newCursor;
        $put($slot, $next);
        $this->saveBrews();
        $this->sendBrewWindow($c);
        $this->sendPlayerInventory($c);
    }

    /** 每 2 秒推进一次酿造进度 */
    private function tickBrewing(float $now): void
    {
        if ($now - $this->lastBrewTick < 2.0) {
            return;
        }
        $elapsed = $now - ($this->lastBrewTick ?: $now);
        $this->lastBrewTick = $now;
        $this->loadBrews();
        if ($this->brews === []) {
            return;
        }
        $ticks = (int) round($elapsed * 20);

        foreach ($this->brews as $k => &$b) {
            $ing = $b['slots'][3] ?? null;
            if ($ing === null || !isset(self::BREW_RECIPES[$ing['id']])) {
                continue;
            }
            // 三个槽里至少要有一个水瓶
            $any = false;
            for ($i = 0; $i < 3; $i++) {
                $it = $b['slots'][$i] ?? null;
                if (is_array($it) && (int) $it['id'] === 373) {
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                continue;
            }
            if ($b['fuel'] <= 0) {
                $fuel = $b['slots'][4] ?? null;
                if (is_array($fuel) && (int) $fuel['id'] === 377) {   // 烈焰粉
                    $b['fuel'] = 20;
                    $b['slots'][4] = ((int) $fuel['count'] > 1)
                        ? ['id' => 377, 'count' => (int) $fuel['count'] - 1, 'damage' => 0]
                        : null;
                } else {
                    continue;
                }
            }

            $b['brew'] = (int) $b['brew'] + $ticks;
            if ($b['brew'] >= 400) {
                $b['brew'] = 0;
                $b['fuel'] = max(0, (int) $b['fuel'] - 1);
                $outDamage = self::BREW_RECIPES[$ing['id']];
                for ($i = 0; $i < 3; $i++) {
                    $it = $b['slots'][$i] ?? null;
                    if (is_array($it) && (int) $it['id'] === 373) {
                        $b['slots'][$i] = ['id' => 373, 'count' => (int) $it['count'], 'damage' => $outDamage];
                    }
                }
                $b['slots'][3] = ((int) $ing['count'] > 1)
                    ? ['id' => $ing['id'], 'count' => (int) $ing['count'] - 1, 'damage' => (int) ($ing['damage'] ?? 0)]
                    : null;
            }
        }
        unset($b);
        $this->saveBrews();

        foreach ($this->players() as $p) {
            if ($p->brewing !== null) {
                $this->sendBrewWindow($p);
            }
        }
    }
}
