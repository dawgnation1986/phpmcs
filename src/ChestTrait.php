<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 箱子：开窗、窗口物品、点击、关闭时存盘。
 *
 * 1.8 的容器要三样东西缺一不可：
 *   Open Window(0x2D) + Window Items(0x30) + 每次点击回 Confirm Transaction(0x32)。
 * 少最后那个，客户端会把后续操作全卡住。
 *
 * 双箱子（两个箱子方块挨着）在原版是一个 54 格的容器：左边 27 格是第一个
 * TileEntity，右边 27 格是第二个。窗口总槽位 = 54 + 36（背包）。
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

    private function chestIdAt(int $x, int $y, int $z): int
    {
        return $this->world->chunk($x >> 4, $z >> 4)
            ->blockState($x & 15, $y, $z & 15) >> 4;
    }

    /**
     * 找可以合并成双箱子的邻居。
     * 只看同一层的四个水平方向；如果那个箱子除了当前箱子外还挨着别的箱子，
     * 说明它已经是一对双箱子的一半，不能再合并（原版 BlockChest 的规则）。
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private function findChestPartner(int $x, int $y, int $z): ?array
    {
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
            $nx = $x + $dx;
            $nz = $z + $dz;
            if ($this->chestIdAt($nx, $y, $nz) !== 54) {
                continue;
            }
            $hasOther = false;
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$ax, $az]) {
                if ($ax === -$dx && $az === -$dz) {
                    continue;   // 这个方向是当前箱子
                }
                if ($this->chestIdAt($nx + $ax, $y, $nz + $az) === 54) {
                    $hasOther = true;
                    break;
                }
            }
            if (!$hasOther) {
                return [$nx, $y, $nz];
            }
        }

        return null;
    }

    /**
     * 双箱子谁在窗口前半段：原版 afs.f 对西/北方向的邻居是「邻居在前」，
     * 其余方向是「自己在前面」。
     */
    private function partnerComesFirst(int $x, int $z, int $px, int $pz): bool
    {
        return $px < $x || $pz < $z;
    }

    /** @param array{x:int,y:int,z:int,size:int,partner:?array,slots:array} $chest */
    private function chestContains(array $chest, int $x, int $y, int $z): bool
    {
        if ($chest['x'] === $x && $chest['y'] === $y && $chest['z'] === $z) {
            return true;
        }
        $p = $chest['partner'] ?? null;

        return $p !== null && $p[0] === $x && $p[1] === $y && $p[2] === $z;
    }

    /**
     * 窗口槽位：0..size-1 箱子，size..size+26 主背包，size+27..size+35 快捷栏。
     */
    private function windowSlots(Connection $c): array
    {
        $size = $c->chest['size'] ?? 27;
        $chest = $c->chest['slots'] ?? array_fill(0, $size, null);
        $out = [];
        for ($i = 0; $i < $size; $i++) {
            $out[$i] = $chest[$i] ?? null;
        }
        for ($i = 9; $i <= 44; $i++) {
            $out[] = $c->inventory[$i] ?? null;
        }

        return $out;
    }

    private function setWindowSlot(Connection $c, int $slot, ?array $item): void
    {
        $size = $c->chest['size'] ?? 27;
        if ($slot < $size) {
            $c->chest['slots'][$slot] = $item;

            return;
        }
        $inv = $slot < $size + 27
            ? 9 + ($slot - $size)
            : 36 + ($slot - $size - 27);
        if ($item === null) {
            unset($c->inventory[$inv]);
        } else {
            $c->inventory[$inv] = $item;
        }
    }

    private function openChest(Connection $c, int $x, int $y, int $z): void
    {
        // 开箱子必须把熔炉状态清掉：两者现在共用 windowId=1，
        // 点击分发按 $c->furnace 判断，熔炉不清就会把箱子的点击当成熔炉的，
        // 表现就是「手指上有物品，点格子放不进去」。
        $c->furnace = null;
        $this->closeChest($c);
        $this->closeWorkbench($c);
        // 2x2 合成格在箱子窗口里看不到，先把里面的东西还回背包
        $this->returnCraftGrid($c);

        $partner = $this->findChestPartner($x, $y, $z);
        $size = 27;
        $slots = $this->chests->load($x, $y, $z);
        if ($partner !== null) {
            $other = $this->chests->load($partner[0], $partner[1], $partner[2]);
            $size = 54;
            $slots = $this->partnerComesFirst($x, $z, $partner[0], $partner[2])
                ? array_merge($other, $slots)
                : array_merge($slots, $other);
        }

        $c->chest = [
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'size' => $size,
            'partner' => $partner,
            'slots' => $slots,
        ];

        $c->send(self::C_OPEN_WINDOW, (new Writer())
            ->byte(1)
            ->string('minecraft:chest')
            ->string(json_encode(['text' => $partner !== null ? '大箱子' : '箱子'], JSON_UNESCAPED_UNICODE) ?: '{"text":"Chest"}')
            ->byte($size));

        $w = (new Writer())->byte(1)->short($size + 36);
        foreach ($this->windowSlots($c) as $item) {
            $w->raw($this->slotBytes($item));
        }
        $c->send(self::C_WINDOW_ITEMS, $w);

        $this->log("  {$c->username} 打开箱子 {$x},{$y},{$z}"
            . ($partner !== null ? "（双箱子，合并 {$partner[0]},{$partner[1]},{$partner[2]}）" : ''));
    }

    /** 把当前窗口的 54/27 格拆回每个箱子独立的 27 格存盘 */
    private function saveChestWindow(Connection $c): void
    {
        $chest = $c->chest;
        if ($chest === null) {
            return;
        }
        $slots = $chest['slots'];
        $partner = $chest['partner'] ?? null;

        if (($chest['size'] ?? 27) === 54 && $partner !== null) {
            $partnerFirst = $this->partnerComesFirst(
                $chest['x'],
                $chest['z'],
                $partner[0],
                $partner[2]
            );
            $first = $partnerFirst
                ? [$partner[0], $partner[1], $partner[2]]
                : [$chest['x'], $chest['y'], $chest['z']];
            $second = $partnerFirst
                ? [$chest['x'], $chest['y'], $chest['z']]
                : [$partner[0], $partner[1], $partner[2]];
            $this->chests->save($first[0], $first[1], $first[2], array_slice($slots, 0, 27));
            $this->world->markChunkDirty($first[0], $first[2]);
            $this->chests->save($second[0], $second[1], $second[2], array_slice($slots, 27, 27));
            $this->world->markChunkDirty($second[0], $second[2]);

            return;
        }

        // 末影箱：不走世界里的 ChestStore，存到玩家自己的末影背包
        if (!empty($chest['ender'])) {
            $this->saveEnderSlots((string) ($chest['owner'] ?? ''), array_slice($slots, 0, 27));
            return;
        }

        $this->chests->save($chest['x'], $chest['y'], $chest['z'], array_slice($slots, 0, 27));
        $this->world->markChunkDirty($chest['x'], $chest['z']);
    }

    private function closeChest(Connection $c, bool $save = true): void
    {
        if ($c->chest === null) {
            return;
        }
        if ($save) {
            $this->saveChestWindow($c);
        }
        // 1.8: 0x2E 才是 Close Window；0x31 是 Window Property（要读两个 short），
        // 发错了客户端会 IndexOutOfBounds 直接崩。
        $c->send(0x2E, (new Writer())->byte(1));
        $c->chest = null;
    }

    /**
     * 打掉箱子：内容掉出来、清掉存档。
     * 正开着窗口时以窗口里的实时内容为准；双箱子只掉被打掉的那半边，
     * 另一半存回去继续用（存盘时也必须拆成两个 27 格，不能整份覆盖）。
     */
    private function breakChestBlock(Connection $c, int $x, int $y, int $z): void
    {
        $partner = $this->findChestPartner($x, $y, $z);
        $drop = null;

        foreach ($this->players() as $p) {
            if ($p->chest === null || !$this->chestContains($p->chest, $x, $y, $z)) {
                continue;
            }
            if ($drop === null) {
                $slots = $p->chest['slots'];
                $other = $partner ?? ($p->chest['partner'] ?? null);
                if (($p->chest['size'] ?? 27) === 54 && $other !== null) {
                    $partnerFirst = $this->partnerComesFirst(
                        $p->chest['x'],
                        $p->chest['z'],
                        $other[0],
                        $other[2]
                    );
                    $drop = $partnerFirst
                        ? array_slice($slots, 27, 27)
                        : array_slice($slots, 0, 27);
                    $keep = $partnerFirst
                        ? array_slice($slots, 0, 27)
                        : array_slice($slots, 27, 27);
                    $this->chests->save($other[0], $other[1], $other[2], $keep);
                    $this->world->markChunkDirty($other[0], $other[2]);
                } else {
                    $drop = array_slice($slots, 0, 27);
                }
            }
            $this->closeChest($p, false);
        }

        if ($drop === null) {
            $drop = $this->chests->load($x, $y, $z);
        }
        $this->chests->remove($x, $y, $z);

        foreach ($drop as $item) {
            if ($item !== null && $item['count'] > 0) {
                $this->spawnDrop($x, $y, $z, $item['id'], $item['count'], $item['damage'], $c);
            }
        }
    }

    /**
     * 箱子窗口点击。跟玩家背包一样，Click Window 包里带的是客户端预测后的
     * 光标物品，服务端必须用自己的 $c->cursor 和当前槽位重新算。
     */
    private function clickChest(Connection $c, int $slot, int $button, int $mode, ?array $cursor): void
    {
        $size = $c->chest['size'] ?? 27;
        if ($c->chest === null || $slot < 0 || $slot > $size + 35) {
            return;
        }

        $slots = $this->windowSlots($c);
        $current = $slots[$slot];

        if ($mode === 1 && $current !== null) {
            $range = $slot < $size ? [$size, $size + 35] : [0, $size - 1];
            for ($i = $range[0]; $i <= $range[1]; $i++) {
                if ($slots[$i] !== null) {
                    continue;
                }
                $this->setWindowSlot($c, $i, $current);
                $this->setWindowSlot($c, $slot, null);
                $this->sendWindowSlot($c, 1, $i, $current);
                $this->sendWindowSlot($c, 1, $slot, null);
                $this->sendCursor($c);

                return;
            }
            $this->sendCursor($c);

            return;
        }
        if ($mode !== 0 || ($button !== 0 && $button !== 1)) {
            $this->sendCursor($c);

            return;
        }

        [$next, $newCursor] = self::clickedStacks($current, $c->cursor, $button);
        $c->cursor = $newCursor;
        $this->setWindowSlot($c, $slot, $next);
        $this->sendWindowSlot($c, 1, $slot, $next);
        $this->sendCursor($c);
    }
}
