<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 箱子：开窗、窗口物品、点击、关闭时存盘。
 *
 * 1.8 的容器要三样东西缺一不可：
 *   Open Window(0x2D) + Window Items(0x30) + 每次点击回 Confirm Transaction(0x32)。
 * 少最后那个，客户端会把后续操作全卡住。
 */
trait ChestTrait
{
    private function slotBytes(?array $item): string
    {
        if ($item === null || ($item['count'] ?? 0) <= 0) {
            return pack('n', 0xFFFF);   // short -1 = 空槽
        }

        return pack('n', $item['id'] & 0xFFFF)
            . chr($item['count'] & 0xFF)
            . pack('n', $item['damage'] & 0xFFFF)
            . "\x00";
    }

    private function readSlotItem(Reader $r): ?array
    {
        $id = $r->readShort();
        if ($id < 0) {
            return null;
        }
        $count = $r->readUnsignedByte();
        $damage = $r->readShort();
        $r->readUnsignedByte();   // NBT 有没有

        return ['id' => $id, 'count' => $count, 'damage' => $damage];
    }

    /** 窗口 63 格：0-26 箱子 / 27-53 背包主区 / 54-62 快捷栏 */
    private function windowSlots(Connection $c): array
    {
        $chest = $c->chest['slots'] ?? array_fill(0, 27, null);
        $out = [];
        for ($i = 0; $i < 27; $i++) {
            $out[$i] = $chest[$i] ?? null;
        }
        for ($i = 9; $i <= 35; $i++) {
            $out[] = $c->inventory[$i] ?? null;
        }
        for ($i = 36; $i <= 44; $i++) {
            $out[] = $c->inventory[$i] ?? null;
        }

        return $out;
    }

    private function setWindowSlot(Connection $c, int $slot, ?array $item): void
    {
        if ($slot < 27) {
            $c->chest['slots'][$slot] = $item;

            return;
        }
        $inv = $slot <= 53 ? 9 + ($slot - 27) : 36 + ($slot - 54);
        if ($item === null) {
            unset($c->inventory[$inv]);
        } else {
            $c->inventory[$inv] = $item;
        }
    }

    private function openChest(Connection $c, int $x, int $y, int $z): void
    {
        $this->closeChest($c);

        $c->chest = [
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'slots' => $this->chests->load($x, $y, $z),
        ];

        $c->send(self::C_OPEN_WINDOW, (new Writer())
            ->byte(1)
            ->string('minecraft:chest')
            ->string(json_encode(['text' => '箱子'], JSON_UNESCAPED_UNICODE) ?: '{"text":"Chest"}')
            ->byte(27));

        $w = (new Writer())->byte(1)->short(63);
        foreach ($this->windowSlots($c) as $item) {
            $w->raw($this->slotBytes($item));
        }
        $c->send(self::C_WINDOW_ITEMS, $w);

        $this->log("  {$c->username} 打开箱子 {$x},{$y},{$z}");
    }

    private function closeChest(Connection $c): void
    {
        if ($c->chest === null) {
            return;
        }
        $this->chests->save($c->chest['x'], $c->chest['y'], $c->chest['z'], $c->chest['slots']);
        $c->send(0x31, (new Writer())->byte(1));   // Close Window
        $c->chest = null;
    }

    /**
     * 只做三种最常见操作：左键换手、右键取半/放一、Shift 整堆搬运。
     * 其它模式原样不动 —— 宁可没反应，也不能把东西吞了。
     */
    private function clickChest(Connection $c, int $slot, int $button, int $mode, ?array $cursor): void
    {
        if ($c->chest === null || $slot < 0 || $slot > 62) {
            return;
        }

        $current = $this->windowSlots($c)[$slot];
        $next = $current;

        if ($mode === 0 && $button === 0) {
            $next = $cursor;                                   // 左键互换
        } elseif ($mode === 0 && $button === 1 && $cursor === null && $current !== null) {
            $half = (int) ceil($current['count'] / 2);          // 右键拿一半
            $rest = $current['count'] - $half;
            $next = $rest > 0
                ? ['id' => $current['id'], 'count' => $rest, 'damage' => $current['damage']]
                : null;
        } elseif ($mode === 0 && $button === 1 && $cursor !== null && $current === null) {
            $next = ['id' => $cursor['id'], 'count' => 1, 'damage' => $cursor['damage']];
        } elseif ($mode === 0 && $button === 1 && $cursor !== null && $current !== null
            && $current['id'] === $cursor['id'] && $current['damage'] === $cursor['damage']
            && $current['count'] < 64) {
            $next = ['id' => $current['id'], 'count' => $current['count'] + 1, 'damage' => $current['damage']];
        } elseif ($mode === 1 && $current !== null) {
            $range = $slot < 27 ? [27, 62] : [0, 26];
            for ($i = $range[0]; $i <= $range[1]; $i++) {
                if ($this->windowSlots($c)[$i] !== null) {
                    continue;
                }
                $this->setWindowSlot($c, $i, $current);
                $this->setWindowSlot($c, $slot, null);
                $c->send(self::C_SET_SLOT, (new Writer())
                    ->byte(1)->short($i)->raw($this->slotBytes($current)));
                $c->send(self::C_SET_SLOT, (new Writer())
                    ->byte(1)->short($slot)->raw($this->slotBytes(null)));

                return;
            }

            return;
        } else {
            return;
        }

        $this->setWindowSlot($c, $slot, $next);
        $c->send(self::C_SET_SLOT, (new Writer())
            ->byte(1)->short($slot)->raw($this->slotBytes($next)));
    }
}
