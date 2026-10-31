<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 弓箭：拉弓松手 → 生成箭实体 → 飞行 → 命中生物或方块。
 *
 * 1.8 里「松开使用中的物品」走 0x07 Player Digging 的 status=5。
 * 箭是 Spawn Object 的 type 60，data 填射手 eid（客户端用来算是谁射的），
 * data > 0 时后面必须再跟 3 个 short 的速度。
 */
trait ArrowTrait
{
    /** @var array 箭 eid => [x,y,z,vx,vy,vz,owner] */
    private array $arrows = [];

    private const ARROW_ID = 262;
    private const BOW_ID = 261;

    /** 松手：有弓有箭就射一支出去 */
    private function shootArrow(Connection $c): void
    {
        $held = $c->inventory[36 + $c->heldSlot] ?? null;
        if (!is_array($held) || (int) $held['id'] !== self::BOW_ID) {
            return;
        }

        // 找箭：快捷栏优先，其次背包
        $arrowSlot = null;
        foreach (array_merge(range(36, 44), range(9, 35)) as $s) {
            $it = $c->inventory[$s] ?? null;
            if (is_array($it) && (int) $it['id'] === self::ARROW_ID) {
                $arrowSlot = $s;
                break;
            }
        }
        if ($arrowSlot === null) {
            return;   // 没箭，射不出去（原版也一样）
        }

        $left = (int) $c->inventory[$arrowSlot]['count'] - 1;
        if ($left <= 0) {
            unset($c->inventory[$arrowSlot]);
        } else {
            $c->inventory[$arrowSlot]['count'] = $left;
        }
        $this->sendPlayerInventory($c);

        // 从眼睛高度、朝视线方向射出
        $yaw = deg2rad($c->yaw);
        $pitch = deg2rad($c->pitch);
        $dirX = -sin($yaw) * cos($pitch);
        $dirY = -sin($pitch);
        $dirZ = cos($yaw) * cos($pitch);

        $speed = 2.6;
        $x = $c->x - sin($yaw) * 0.3;
        $y = $c->y + 1.5;
        $z = $c->z + cos($yaw) * 0.3;

        $eid = $this->nextEntityId++;
        $this->arrows[$eid] = [
            $x, $y, $z,
            $dirX * $speed, $dirY * $speed, $dirZ * $speed,
            $c->entityId,
        ];

        foreach ($this->players() as $p) {
            $p->send(self::C_SPAWN_OBJECT, (new Writer())
                ->varInt($eid)
                ->byte(60)                                  // 60 = 箭
                ->int((int) ($x * 32))->int((int) ($y * 32))->int((int) ($z * 32))
                ->byte(self::angle($c->yaw))
                ->byte(self::angle($c->pitch))
                ->int($c->entityId)                         // data = 射手（>0 才带速度）
                ->short((int) ($dirX * $speed * 8000))
                ->short((int) ($dirY * $speed * 8000))
                ->short((int) ($dirZ * $speed * 8000)));
        }
        $this->log(sprintf('  %s 射箭 eid=%d 方向 %.2f,%.2f,%.2f', $c->username, $eid, $dirX, $dirY, $dirZ));
    }

    /** 每拍推进所有箭 */
    private function tickArrows(float $now): void
    {
        if ($this->arrows === []) {
            return;
        }
        foreach ($this->arrows as $eid => $a) {
            [$x, $y, $z, $vx, $vy, $vz, $owner] = $a;
            $steps = 4;                       // 每拍分 4 小步，避免穿模
            $hit = false;
            for ($i = 0; $i < $steps && !$hit; $i++) {
                $x += $vx / $steps;
                $y += $vy / $steps;
                $z += $vz / $steps;
                $vy -= 0.05 / $steps;
                if ($y < 0) {
                    $hit = true;
                    break;
                }
                $chunk = $this->world->chunk(((int) floor($x)) >> 4, ((int) floor($z)) >> 4);
                $bid = $chunk->blockState(((int) floor($x)) & 15, (int) floor($y), ((int) floor($z)) & 15) >> 4;
                if ($bid !== 0 && !in_array($bid, [8, 9, 31, 32, 37, 38, 39, 40, 50, 51, 78, 83, 106, 175], true)) {
                    $hit = true;
                    break;
                }
                // 命中生物
                foreach ($this->mobs as $meid => $m) {
                    if (!empty($m['dead'])) {
                        continue;
                    }
                    $d = sqrt(($m['x'] - $x) ** 2 + ($m['y'] + 0.9 - $y) ** 2 + ($m['z'] - $z) ** 2);
                    if ($d < 0.9) {
                        $shooter = null;
                        foreach ($this->players() as $p) {
                            if ($p->entityId === $owner) {
                                $shooter = $p;
                                break;
                            }
                        }
                        // 弓的伤害：满蓄力约 9 点，这里按固定 6 算
                        if ($shooter !== null) {
                            $this->damageMob($meid, 6, $shooter);
                        }
                        $hit = true;
                        break;
                    }
                }
            }

            if ($hit) {
                foreach ($this->players() as $p) {
                    $p->send(self::C_DESTROY_ENTITIES, (new Writer())->varInt(1)->varInt($eid));
                }
                unset($this->arrows[$eid]);
                continue;
            }

            $this->arrows[$eid] = [$x, $y, $z, $vx, $vy, $vz, $owner];
            foreach ($this->players() as $p) {
                $p->send(self::C_ENTITY_TELEPORT, (new Writer())
                    ->varInt($eid)
                    ->int((int) ($x * 32))->int((int) ($y * 32))->int((int) ($z * 32))
                    ->byte(0)->byte(0)->bool(false));
            }
        }
    }
}
