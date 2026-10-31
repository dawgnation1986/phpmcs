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
        if ($arrowSlot === null && $c->gamemode !== 1) {
            return;   // 生存没箭射不出去；创造不用箭
        }

        // 创造模式：不耗耐久、不耗箭（原版行为）
        if ($c->gamemode !== 1) {
            // 弓扣 1 点耐久，384 用坏（有耐久附魔另算，暂未实现）
            $bowSlot = 36 + $c->heldSlot;
            $bow = $c->inventory[$bowSlot];
            $bow['damage'] = (int) ($bow['damage'] ?? 0) + 1;
            if ($bow['damage'] >= 384) {
                unset($c->inventory[$bowSlot]);
            } else {
                $c->inventory[$bowSlot] = $bow;
            }

            $left = (int) $c->inventory[$arrowSlot]['count'] - 1;
            if ($left <= 0) {
                unset($c->inventory[$arrowSlot]);
            } else {
                $c->inventory[$arrowSlot]['count'] = $left;
            }
            $this->sendPlayerInventory($c);
        }

        // 从眼睛高度、朝视线方向射出
        $yaw = deg2rad($c->yaw);
        $pitch = deg2rad($c->pitch);
        $dirX = -sin($yaw) * cos($pitch);
        $dirY = -sin($pitch);
        $dirZ = cos($yaw) * cos($pitch);

        // 原版满蓄力射程约 60 格，对应初速 1.8 格/拍（之前 2.6 能飞 130 格）
        $speed = 1.8;
        $x = $c->x - sin($yaw) * 0.8;
        $y = $c->y + 1.62;
        $z = $c->z + cos($yaw) * 0.8;

        $eid = $this->nextEntityId++;
        $this->arrows[$eid] = [
            $x, $y, $z,
            $dirX * $speed, $dirY * $speed, $dirZ * $speed,
            $c->entityId,
            0,                                  // [7] 已推进拍数
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
        $chk = $this->world->chunk(((int) floor($x)) >> 4, ((int) floor($z)) >> 4);
        $bidNow = $chk->blockState(((int) floor($x)) & 15, (int) floor($y), ((int) floor($z)) & 15) >> 4;
        $this->log(sprintf('  %s 射箭 eid=%d @ %.2f,%.2f,%.2f 方向 %.2f,%.2f,%.2f 该格方块=%d',
            $c->username, $eid, $x, $y, $z, $dirX, $dirY, $dirZ, $bidNow));
    }

    /** 每拍推进所有箭 */
    private float $lastArrowTick = 0.0;

    private function tickArrows(float $now): void
    {
        if ($this->arrows === []) {
            return;
        }
        // 必须限速！主循环一秒能跑上千次，不限速的话箭在 1 毫秒内就飞完 6 格
        // 然后销毁，客户端一帧都没画到 —— 这就是「箭看不见」的原因。
        if ($now - $this->lastArrowTick < 0.05) {
            return;
        }
        $this->lastArrowTick = $now;
        foreach ($this->arrows as $eid => $a) {
            [$x, $y, $z, $vx, $vy, $vz, $owner] = $a;
            // 插住的箭：不动，5 秒后消失
            if (isset($a[8]) && $a[8] > 0) {
                if (microtime(true) >= $a[8]) {
                    foreach ($this->players() as $p) {
                        $p->send(self::C_DESTROY_ENTITIES, (new Writer())->varInt(1)->varInt($eid));
                    }
                    unset($this->arrows[$eid]);
                }
                continue;
            }
            $steps = 4;                       // 每拍分 4 小步，避免穿模
            $hit = false;
            $this->arrows[$eid][7] = ($a[7] ?? 0) + 1;
            $noCollide = $this->arrows[$eid][7] <= 2;   // 刚出生的两拍不判碰撞
            for ($i = 0; $i < $steps && !$hit; $i++) {
                $x += $vx / $steps;
                $y += $vy / $steps;
                $z += $vz / $steps;
                $vy -= 0.05 / $steps;
                // 空气阻力 0.99/拍（原版 motion *= 0.99）。少了这个，
                // 箭会一直飞：朝天上打能升 70 格、飞 130 格才落地。
                $drag = 0.99 ** (1 / $steps);
                $vx *= $drag;
                $vy *= $drag;
                $vz *= $drag;
                if ($y < 0) {
                    $hit = true;
                    break;
                }
                $chunk = $this->world->chunk(((int) floor($x)) >> 4, ((int) floor($z)) >> 4);
                $bid = $chunk->blockState(((int) floor($x)) & 15, (int) floor($y), ((int) floor($z)) & 15) >> 4;
                if (!$noCollide && $bid !== 0
                    && !in_array($bid, [8, 9, 31, 32, 37, 38, 39, 40, 50, 51, 78, 83, 106, 175], true)) {
                    // 插在方块上停 5 秒（原版行为），顺便让玩家看得到箭
                    $this->arrows[$eid] = [$x, $y, $z, 0.0, 0.0, 0.0, $owner, $this->arrows[$eid][7], microtime(true) + 5.0];
                    $hit = true;
                    $stuck = true;
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
                        $this->log("  箭 eid={$eid} 命中生物 {$meid}");
                        if ($shooter !== null) {
                            $this->damageMob($meid, 6, $shooter);
                        }
                        $hit = true;
                        break;
                    }
                }
            }

            if ($this->arrows[$eid][7] <= 3) {
                $this->log(sprintf('  箭 eid=%d 第%d拍 @ %.2f,%.2f,%.2f 速度 %.2f,%.2f,%.2f',
                    $eid, $this->arrows[$eid][7], $x, $y, $z, $vx, $vy, $vz));
            }

            if ($hit) {
                if (!empty($stuck)) {
                    continue;      // 已经改成插住状态，保留实体
                }
                foreach ($this->players() as $p) {
                    $p->send(self::C_DESTROY_ENTITIES, (new Writer())->varInt(1)->varInt($eid));
                }
                unset($this->arrows[$eid]);
                continue;
            }

            $this->arrows[$eid] = [$x, $y, $z, $vx, $vy, $vz, $owner, $this->arrows[$eid][7]];
            // 朝向必须按速度算，否则箭一直水平飞，看起来像「没有重力」
            $horiz = sqrt($vx * $vx + $vz * $vz);
            $yawDeg = rad2deg(atan2(-$vx, $vz));
            $pitchDeg = rad2deg(atan2(-$vy, $horiz));
            foreach ($this->players() as $p) {
                $p->send(self::C_ENTITY_TELEPORT, (new Writer())
                    ->varInt($eid)
                    ->int((int) ($x * 32))->int((int) ($y * 32))->int((int) ($z * 32))
                    ->byte(self::angle($yawDeg))->byte(self::angle($pitchDeg))->bool(false));
            }
        }
    }
}
