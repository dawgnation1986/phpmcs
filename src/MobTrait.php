<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 生物：按群系生成、追人、被打、掉东西、远走消失。
 * 移动是最朴素的「直线追」——不寻路、不绕障碍。
 */
trait MobTrait
{
    /** 类型 => [名字, 敌对, 血量, 掉落物 id, 掉落数量] */
    private const MOB_TYPES = [
        50 => ['creeper', true, 20, 289, 1],
        51 => ['skeleton', true, 20, 262, 2],
        52 => ['spider', true, 16, 287, 2],
        54 => ['zombie', true, 20, 367, 1],
        57 => ['pigman', true, 20, 371, 1],
        58 => ['enderman', true, 40, 368, 1],
        65 => ['bat', false, 6, 0, 0],
        90 => ['pig', false, 10, 319, 2],
        91 => ['sheep', false, 8, 35, 1],
        92 => ['cow', false, 10, 334, 2],
        93 => ['chicken', false, 4, 365, 1],
        95 => ['wolf', false, 8, 0, 0],
        96 => ['mooshroom', false, 10, 334, 2],
        98 => ['ocelot', false, 10, 0, 0],
        100 => ['horse', false, 20, 0, 0],
        101 => ['rabbit', false, 10, 411, 1],
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

        [, , , $dropId, $dropCount] = self::MOB_TYPES[$m['type']];
        $this->removeMob($eid);
        if ($dropId > 0 && $dropCount > 0) {
            for ($i = 0; $i < $dropCount; $i++) {
                $this->spawnDrop((int) floor($m['x']), (int) floor($m['y']), (int) floor($m['z']), $dropId, 1, 0, $attacker);
            }
        }
        $this->log("  {$attacker->username} 打死了 " . self::MOB_TYPES[$m['type']][0]);
        return true;
    }

    private function tickMobs(float $now): void
    {
        if ($now - $this->lastMobTick >= 0.2) {
            $this->lastMobTick = $now;
            foreach ($this->mobs as $eid => $m) {
                // 注意：不能用 nearestPlayer()，那个是捡东西的近距离判定
                // （横向只认 ±1.3 格），生物离你 4 格就被判「附近没人」直接销毁。
                $near = null;
                $bestD = PHP_FLOAT_MAX;
                foreach ($this->players() as $pp) {
                    $d = sqrt(($pp->x - $m['x']) ** 2 + ($pp->y - $m['y']) ** 2 + ($pp->z - $m['z']) ** 2);
                    if ($d < $bestD) {
                        $bestD = $d;
                        $near = $pp;
                    }
                }
                if ($near === null || $bestD > 64.0) {
                    $this->removeMob($eid);
                    continue;
                }

                $hostile = self::MOB_TYPES[$m['type']][1];
                $nx = $m['x'];
                $nz = $m['z'];

                if ($hostile) {
                    $dx = $near->x - $m['x'];
                    $dz = $near->z - $m['z'];
                    $dist = sqrt($dx * $dx + $dz * $dz);
                    if ($dist > 1.6 && $dist < 16.0) {
                        $nx = $m['x'] + $dx / $dist * 0.7;
                        $nz = $m['z'] + $dz / $dist * 0.7;
                    } elseif ($dist <= 2.2 && $now - $m['lastHit'] > 1.5) {
                        $this->damage($near, 1);
                        $this->mobs[$eid]['lastHit'] = $now;
                    }
                } else {
                    $nx = $m['x'] + random_int(-7, 7) / 10;
                    $nz = $m['z'] + random_int(-7, 7) / 10;
                }

                if (abs($nx - $m['x']) > 0.001 || abs($nz - $m['z']) > 0.001) {
                    $ny = $this->groundY((int) floor($nx), (int) floor($nz), (int) $m['y'] + 1);
                    if ($ny <= 0) {
                        $ny = $m['y'];
                    }
                    $this->mobs[$eid]['x'] = $nx;
                    $this->mobs[$eid]['y'] = $ny;
                    $this->mobs[$eid]['z'] = $nz;
                    // 朝向按移动方向算，1.8 的 yaw 是 atan2(-dx, dz)
                    $yawByte = self::angle(rad2deg(atan2(-($nx - $m['x']), $nz - $m['z'])));
                    foreach ($this->players() as $p) {
                        $p->send(self::C_ENTITY_TELEPORT, (new Writer())
                            ->varInt($eid)
                            ->int((int) ($nx * 32))->int((int) ($ny * 32))->int((int) ($nz * 32))
                            ->byte($yawByte)->byte(0)->bool(true));
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
        $this->spawnMob($type, $x + 0.5, (float) $y, $z + 0.5);
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
