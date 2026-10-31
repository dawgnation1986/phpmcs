<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 生物：按群系生成、追人、被打、掉东西、远走消失。
 * 移动是最朴素的「直线追」——不寻路、不绕障碍。
 */
trait MobTrait
{
    /** 类型 => [名字, 敌对, 血量, [[掉落物, 最少, 最多], ...]] */
    private const MOB_TYPES = [
        50 => ['creeper', true, 20, [[289, 0, 2]]],                    // 火药
        51 => ['skeleton', true, 20, [[352, 0, 2], [262, 0, 2]]],      // 骨头 + 箭
        52 => ['spider', true, 16, [[287, 0, 2], [375, 0, 1]]],        // 线 + 蛛眼
        54 => ['zombie', true, 20, [[367, 0, 2]]],                     // 腐肉
        57 => ['pigman', true, 20, [[371, 0, 1], [370, 0, 1]]],        // 金粒 + 腐肉
        58 => ['enderman', true, 40, [[368, 0, 1]]],                   // 末影珍珠
        65 => ['bat', false, 6, []],
        90 => ['pig', false, 10, [[319, 1, 3]]],                       // 生猪排
        91 => ['sheep', false, 8, [[423, 1, 2], [35, 1, 1]]],          // 生羊肉 + 羊毛
        92 => ['cow', false, 10, [[363, 1, 3], [334, 0, 2]]],          // 生牛肉 + 皮革
        93 => ['chicken', false, 4, [[365, 1, 1], [288, 0, 2]]],       // 生鸡肉 + 羽毛
        95 => ['wolf', false, 8, []],
        96 => ['mooshroom', false, 10, [[363, 1, 3], [334, 0, 2]]],
        98 => ['ocelot', false, 10, []],
        100 => ['horse', false, 20, [[334, 0, 2]]],                    // 皮革
        101 => ['rabbit', false, 10, [[411, 0, 1], [415, 0, 1], [414, 0, 1]]], // 兔肉/皮/脚
    ];

    private const BIOME_PASSIVE = [
        1 => [90, 91, 92, 93, 100], 129 => [90, 91, 92, 93, 100],
        4 => [95, 91, 92, 93], 27 => [95, 91, 92, 93], 28 => [95, 91, 92, 93],
        3 => [95, 91, 92], 5 => [95, 91, 101], 19 => [95, 91, 101],
        29 => [95, 91, 101], 30 => [95, 91, 101],
        21 => [98, 93], 23 => [98, 93], 22 => [98, 93],
        32 => [100, 92], 33 => [100, 92], 14 => [96], 2 => [101], 17 => [101],
    ];

    private const MOB_HOSTILE = [54, 51, 50, 52];

    /** @var array<int, array{type:int,x:float,y:float,z:float,hp:int,life:float,lastHit:float}> */
    private array $mobs = [];

    private float $lastMobSpawn = 0.0;
    private float $lastMobTick = 0.0;
    private float $lastTimeSync = 0.0;

    private function spawnMob(int $type, float $x, float $y, float $z): int
    {
        if (!isset(self::MOB_TYPES[$type])) {
            return 0;
        }
        $eid = $this->nextEntityId++;
        $this->mobs[$eid] = [
            'type' => $type, 'x' => $x, 'y' => $y, 'z' => $z,
            'hp' => self::MOB_TYPES[$type][2], 'life' => 0.0, 'lastHit' => 0.0,
            'target' => 0, 'angryUntil' => 0.0, 'burnAt' => 0.0,
        ];
        foreach ($this->players() as $p) {
            $this->sendMobSpawn($p, $eid, $type, $x, $y, $z);
        }
        return $eid;
    }

    private function sendMobSpawn(Connection $p, int $eid, int $type, float $x, float $y, float $z): void
    {
        $p->send(self::C_SPAWN_MOB, (new Writer())
            ->varInt($eid)->byte($type)
            ->int((int) ($x * 32))->int((int) ($y * 32))->int((int) ($z * 32))
            ->byte(0)->byte(0)->byte(0)
            ->short(0)->short(0)->short(0)
            // DataWatcher 不能只写终止符：客户端 S0F 会 new DataWatcher(null)，
            // 空列表直接 NullPointerException，等于生物没生成。
            ->unsignedByte(0x00)->unsignedByte(0x00)
            ->unsignedByte(0x7F));
    }

    private function removeMob(int $eid): void
    {
        if (!isset($this->mobs[$eid])) {
            return;
        }
        unset($this->mobs[$eid]);
        foreach ($this->players() as $p) {
            $p->send(self::C_DESTROY_ENTITIES, (new Writer())->varInt(1)->varInt($eid));
        }
    }

    /** 打一只生物。返回 true = 这个 eid 是生物，已经处理。 */
    private function damageMob(int $eid, int $dmg, Connection $attacker): bool
    {
        if (!isset($this->mobs[$eid])) {
            return false;
        }
        $this->mobs[$eid]['hp'] -= $dmg;
        $this->mobs[$eid]['lastHit'] = microtime(true);
        // 记仇：谁打的就盯谁，10 秒内优先追他（哪怕别人更近）
        $this->mobs[$eid]['target'] = $attacker->entityId;
        $this->mobs[$eid]['angryUntil'] = microtime(true) + 10.0;

        // 受击动画：0x1A Entity Status，status=2 是「受伤」
        foreach ($this->players() as $pp) {
            $pp->send(self::C_ENTITY_STATUS, (new Writer())->int($eid)->byte(2));
        }

        $m = $this->mobs[$eid];
        $dx = $m['x'] - $attacker->x;
        $dz = $m['z'] - $attacker->z;
        $len = sqrt($dx * $dx + $dz * $dz) ?: 1.0;
        $nx = $m['x'] + ($dx / $len) * 0.6;
        $nz = $m['z'] + ($dz / $len) * 0.6;
        $ny = $this->groundY((int) floor($nx), (int) floor($nz), (int) $m['y']);
        if ($ny <= 0) {
            $ny = $m['y'];
        }
        $this->mobs[$eid]['x'] = $nx;
        $this->mobs[$eid]['y'] = $ny;
        $this->mobs[$eid]['z'] = $nz;
        foreach ($this->players() as $p) {
            $p->send(self::C_ENTITY_TELEPORT, (new Writer())
                ->varInt($eid)
                ->int((int) ($nx * 32))->int((int) ($ny * 32))->int((int) ($nz * 32))
                ->byte(0)->byte(0)->bool(true));
        }

        if ($this->mobs[$eid]['hp'] > 0) {
            return true;
        }

        [, , , $drops] = self::MOB_TYPES[$m['type']];
        // 击杀经验：敌对 5 点，被动 1~3 点
        $xp = self::MOB_TYPES[$m['type']][1] ? 5 : random_int(1, 3);
        // 1.8 的 Spawn Object 里没有「经验球」这种类型（1 是船、2 是掉落物），
        // 发什么都会画错，所以直接结算给击杀者。
        $this->spawnXpOrb($m['x'], $m['y'] + 0.3, $m['z'], $xp);
        $this->removeMob($eid);
        // 抢夺（21）：每级额外 0~lvl 个
        $looting = 0;
        $wpn = $attacker->inventory[36 + $attacker->heldSlot] ?? null;
        if (is_array($wpn) && !empty($wpn['ench'])) {
            foreach ($wpn['ench'] as $le) {
                if ((int) $le['id'] === 21) {
                    $looting = (int) $le['lvl'];
                    break;
                }
            }
        }
        foreach ($drops as [$dropId, $dmin, $dmax]) {
            $n = random_int($dmin, $dmax) + ($looting > 0 ? random_int(0, $looting) : 0);
            for ($i = 0; $i < $n; $i++) {
                $this->spawnDrop((int) floor($m['x']), (int) floor($m['y']), (int) floor($m['z']), $dropId, 1, 0, $attacker);
            }
        }
        $this->log("  {$attacker->username} 打死了 " . self::MOB_TYPES[$m['type']][0]);
        return true;
    }

    private function tickMobs(float $now): void
    {
        if ($now - $this->lastMobTick >= 0.1) {
            $this->lastMobTick = $now;
            foreach ($this->mobs as $eid => $m) {
                // 注意：不能用 nearestPlayer()，那个是捡东西的近距离判定
                // （横向只认 ±1.3 格），生物离你 4 格就被判「附近没人」直接销毁。
                $near = null;
                $bestD = PHP_FLOAT_MAX;
                // 先看有没有仇恨对象还没过期
                if (($m['target'] ?? 0) > 0 && $now < ($m['angryUntil'] ?? 0)) {
                    foreach ($this->players() as $pp) {
                        if ($pp->entityId !== $m['target']) {
                            continue;
                        }
                        $d = sqrt(($pp->x - $m['x']) ** 2 + ($pp->z - $m['z']) ** 2);
                        if ($d < 32.0) {
                            $near = $pp;
                            $bestD = $d;
                        }
                        break;
                    }
                }
                if ($near === null) {
                    foreach ($this->players() as $pp) {
                        $d = sqrt(($pp->x - $m['x']) ** 2 + ($pp->y - $m['y']) ** 2 + ($pp->z - $m['z']) ** 2);
                        if ($d < $bestD) {
                            $bestD = $d;
                            $near = $pp;
                        }
                    }
                }
                if ($near === null || $bestD > 64.0) {
                    $this->removeMob($eid);
                    continue;
                }

                // 亡灵白天在露天会烧起来
                if (($m['type'] === 54 || $m['type'] === 51) && !$this->isNight()
                    && $now - ($m['burnAt'] ?? 0) >= 1.0) {
                    $chk2 = $this->world->chunk(((int) floor($m['x'])) >> 4, ((int) floor($m['z'])) >> 4);
                    $sky2 = $chk2->skyLight(((int) floor($m['x'])) & 15, (int) floor($m['y']), ((int) floor($m['z'])) & 15);
                    $blk2 = $chk2->blockLight(((int) floor($m['x'])) & 15, (int) floor($m['y']), ((int) floor($m['z'])) & 15);
                    if ($sky2 >= 15 && $blk2 < 8) {
                        $this->mobs[$eid]['burnAt'] = $now;
                        $this->mobs[$eid]['hp']--;
                        foreach ($this->players() as $pp) {
                            $pp->send(self::C_ENTITY_STATUS, (new Writer())->int($eid)->byte(2));
                        }
                        if ($this->mobs[$eid]['hp'] <= 0) {
                            [, , , $drops2] = self::MOB_TYPES[$m['type']];
                            $this->removeMob($eid);
                            foreach ($drops2 as [$dId, $dMin, $dMax]) {
                                $cnt2 = random_int($dMin, $dMax);
                                for ($q = 0; $q < $cnt2; $q++) {
                                    $this->spawnDrop((int) floor($m['x']), (int) floor($m['y']),
                                        (int) floor($m['z']), $dId, 1, 0, $near);
                                }
                            }
                            continue;
                        }
                    }
                }

                $hostile = self::MOB_TYPES[$m['type']][1];
                $nx = $m['x'];
                $nz = $m['z'];

                if ($hostile) {
                    $dx = $near->x - $m['x'];
                    $dz = $near->z - $m['z'];
                    $dist = sqrt($dx * $dx + $dz * $dz);
                    if ($dist > 1.6 && $dist < 16.0) {
                        // 八方向试探：正对目标优先，其次斜向，
                        // 目标格必须能站（脚下实、身上空），且地面落差不超过 1
                        $step = 0.15;
                        $dirs = [[$dx, $dz]];
                        foreach ([[1, 1], [1, -1], [-1, 1], [-1, -1]] as [$sx, $sz]) {
                            $dirs[] = [$dx + $sx * 1.2, $dz + $sz * 1.2];
                        }
                        foreach ([[$dz, -$dx], [-$dz, $dx]] as $perp) {
                            $dirs[] = [$perp[0], $perp[1]];
                        }
                        $moved = false;
                        foreach ($dirs as [$tx, $tz]) {
                            $len = sqrt($tx * $tx + $tz * $tz) ?: 1.0;
                            $cx2 = $m['x'] + $tx / $len * $step;
                            $cz2 = $m['z'] + $tz / $len * $step;
                            [$ok2, $gy2] = $this->canStand($cx2, $cz2, (int) floor($m['y']));
                            if (!$ok2) {
                                continue;
                            }
                            // 不跳崖：目标地面不能比现在低超过 1 格
                            if ($gy2 < (int) floor($m['y']) - 1) {
                                continue;
                            }
                            $nx = $cx2;
                            $nz = $cz2;
                            $moved = true;
                            break;
                        }
                        if (!$moved) {
                            $nx = $m['x'];
                            $nz = $m['z'];
                        }
                    } elseif ($dist <= 2.2 && $now - $m['lastHit'] > 1.5) {
                        $this->damage($near, 1);
                        $this->mobs[$eid]['lastHit'] = $now;
                    }
                } else {
                    $nx = $m['x'] + random_int(-2, 2) / 10;
                    $nz = $m['z'] + random_int(-2, 2) / 10;
                }

                // 别穿墙：目标格得是能站的地方（脚下实、身上空）。
                // 以前直接 groundY 传送，生物会从墙里穿过去。
                if (abs($nx - $m['x']) > 0.001 || abs($nz - $m['z']) > 0.001) {
                    $probe = $this->world->chunk(((int) floor($nx)) >> 4, ((int) floor($nz)) >> 4);
                    $bx2 = ((int) floor($nx)) & 15;
                    $bz2 = ((int) floor($nz)) & 15;
                    $by2 = (int) floor($m['y']);
                    $feet = $probe->blockState($bx2, $by2, $bz2) >> 4;
                    $head = $probe->blockState($bx2, $by2 + 1, $bz2) >> 4;
                    $walkable = static fn (int $id): bool => $id === 0 || in_array($id, [31, 32, 37, 38, 39, 40, 50, 51, 59, 78, 83, 106, 111, 175], true);
                    if (!$walkable($feet) || !$walkable($head)) {
                        // 撞墙：往侧面挪一点，做出「绕一下」的观感
                        $nx = $m['x'] + ($dz ?? 0) * 0.15;
                        $nz = $m['z'] - ($dx ?? 0) * 0.15;
                    }
                    $ny = $this->groundY((int) floor($nx), (int) floor($nz), (int) $m['y'] + 1);
                    if ($ny <= 0) {
                        $ny = $m['y'];
                    }
                    $this->mobs[$eid]['x'] = $nx;
                    $this->mobs[$eid]['y'] = $ny;
                    $this->mobs[$eid]['z'] = $nz;
                    // 朝向按移动方向算，1.8 的 yaw 是 atan2(-dx, dz)
                    $yawByte = self::angle(rad2deg(atan2(-($nx - $m['x']), $nz - $m['z'])));
                    // 小位移用「相对移动」包，客户端会插值，不再一卡一卡。
                    // 位移超过 4 格才退回传送包。
                    $ddx = (int) round(($nx - $m['x']) * 32);
                    $ddy = (int) round(($ny - $m['y']) * 32);
                    $ddz = (int) round(($nz - $m['z']) * 32);
                    // 1.8 的 S15 位移是 **byte**（1/32 格），不是 short。
                    // 发成 short 会让客户端多读 3 个字节，直接断线（Packet 0x21 found 3 bytes extra）。
                    $small = abs($ddx) < 128 && abs($ddy) < 128 && abs($ddz) < 128;
                    foreach ($this->players() as $p) {
                        if ($small) {
                            $p->send(self::C_ENTITY_MOVE, (new Writer())
                                ->varInt($eid)->byte($ddx)->byte($ddy)->byte($ddz)->bool(true));
                        } else {
                            $p->send(self::C_ENTITY_TELEPORT, (new Writer())
                                ->varInt($eid)
                                ->int((int) ($nx * 32))->int((int) ($ny * 32))->int((int) ($nz * 32))
                                ->byte($yawByte)->byte(0)->bool(true));
                        }
                    }
                }

                $this->mobs[$eid]['life'] += 1.0;
                if ($this->mobs[$eid]['life'] > 300) {
                    $this->removeMob($eid);
                }
            }
        }

        if ($now - $this->lastMobSpawn >= 5.0) {
            $this->lastMobSpawn = $now;
            foreach ($this->players() as $p) {
                if (count($this->mobs) >= 60) {
                    break;
                }
                $this->tryNaturalSpawn($p);
            }
        }
    }

    private function tryNaturalSpawn(Connection $p): void
    {
        // 数量上限：每人 10 只，最多 40。以前没有上限，夜里一会儿就刷一片。
        $players = max(1, count($this->players()));
        if (count($this->mobs) >= min(40, 10 * $players)) {
            return;
        }

        $x = (int) floor($p->x) + random_int(-16, 16);
        $z = (int) floor($p->z) + random_int(-16, 16);
        if (abs($x - $p->x) < 4 && abs($z - $p->z) < 4) {
            return;
        }
        $y = $this->groundY($x, $z, (int) floor($p->y) + 8);
        if ($y <= 0) {
            return;
        }
        $biome = $this->biomeAt($x, $z);

        // 海洋/河流/沙滩/深海这些群系表里没有对应生物，以前就「什么都不刷」。
        // 现在兜底成最常见的四种家畜，保证任何地形都看得到被动生物。
        $passive = self::BIOME_PASSIVE[$biome] ?? [90, 91, 92, 93];

        if ($this->isNight()) {
            // 夜里主要是敌对生物，但留三成给被动生物（原版夜里也会有）
            $types = random_int(1, 10) <= 3 ? $passive : self::MOB_HOSTILE;
        } else {
            $types = $passive;
        }
        if ($types === []) {
            return;
        }
        $type = $types[random_int(0, count($types) - 1)];

        // 光照门槛（原版规则）：
        //   敌对 需要亮度 <= 7 —— 所以火把周围、白天露天都不刷
        //   被动 需要亮度 >= 9
        $light = $this->lightAt($x, $y, $z);
        $hostile = in_array($type, self::MOB_HOSTILE, true);
        if ($hostile && $light > 7) {
            return;
        }
        if (!$hostile && $light < 9) {
            return;
        }

        $this->spawnMob($type, $x + 0.5, (float) $y, $z + 0.5);
    }

    /**
     * 该位置的实际亮度（取方块光和天光的大者）。
     * 夜里天光要减 11 —— 原版就是这么算的，所以夜里露天亮度是 4，会刷怪。
     */
    private function lightAt(int $x, int $y, int $z): int
    {
        $chunk = $this->world->chunk($x >> 4, $z >> 4);
        $block = $chunk->blockLight($x & 15, $y, $z & 15);
        $sky = $chunk->skyLight($x & 15, $y, $z & 15);
        if ($sky > 0 && $this->isNight()) {
            $sky = max(0, $sky - 11);
        }

        return max($block, $sky);
    }

    /**
     * 这格能不能站：返回 [能站, 地面 y]。
     * 从 fromY+1 往下找 3 层实心块，且上面两格是空的才算。
     */
    private function canStand(float $x, float $z, int $fromY): array
    {
        $bx = (int) floor($x);
        $bz = (int) floor($z);
        $chunk = $this->world->chunk($bx >> 4, $bz >> 4);
        $solid = static fn (int $id): bool => $id !== 0 && !in_array($id, [
            6, 8, 9, 10, 11, 31, 32, 37, 38, 39, 40, 50, 51, 59, 78, 83, 106, 111, 175,
        ], true);
        for ($gy = $fromY + 1; $gy >= $fromY - 2; $gy--) {
            $here = $chunk->blockState($bx & 15, $gy, $bz & 15) >> 4;
            if (!$solid($here)) {
                continue;
            }
            $up1 = $chunk->blockState($bx & 15, $gy + 1, $bz & 15) >> 4;
            $up2 = $chunk->blockState($bx & 15, $gy + 2, $bz & 15) >> 4;
            if (!$solid($up1) && !$solid($up2)) {
                return [true, $gy + 1];
            }

            return [false, $gy + 1];
        }

        return [false, $fromY];
    }

    private function groundY(int $x, int $z, int $startY): int
    {
        $chunk = $this->world->chunk($x >> 4, $z >> 4);
        for ($y = min(255, $startY); $y > 1; $y--) {
            $id = $chunk->blockState($x & 15, $y, $z & 15) >> 4;
            if ($id !== 0 && !in_array($id, [8, 9, 31, 32, 37, 38, 175], true)) {
                return $y + 1;
            }
        }
        return 0;
    }

    private function biomeAt(int $x, int $z): int
    {
        $chunk = $this->world->chunk($x >> 4, $z >> 4);
        $biomes = $chunk->biomes();
        if ($biomes === null || strlen($biomes) !== 256) {
            return 1;
        }
        return ord($biomes[($z & 15) * 16 + ($x & 15)]);
    }

    private function isNight(): bool
    {
        $time = $this->world->timeOfDay();
        return $time >= 13000 && $time <= 23000;
    }
}
