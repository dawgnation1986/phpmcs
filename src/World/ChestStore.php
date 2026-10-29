<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 箱子内容。先落在存档目录的 .phpmcs_chests.json 里（和玩家位置一个套路），
 * 键是 "x,y,z"。
 *
 * 没直接写进区块的 TileEntities，是因为 1.8 的物品 NBT 里 id 是**名字字符串**
 * （"minecraft:stone"）而不是数字，要写单机能读的箱子得先有一张 id->名字 的表，
 * 那是另一件事。这里先把「放进去、重启还在」跑通。
 */
final class ChestStore
{
    /** @var array<string, array<int, array{id:int,count:int,damage:int}|null>> */
    private array $data = [];

    public function __construct(private string $file)
    {
        if (is_file($file)) {
            $json = json_decode((string) @file_get_contents($file), true);
            if (is_array($json)) {
                $this->data = $json;
            }
        }
    }

    /** @return array<int, array{id:int,count:int,damage:int}|null> 27 个槽位 */
    public function load(int $x, int $y, int $z): array
    {
        $key = $x . ',' . $y . ',' . $z;
        $slots = $this->data[$key] ?? null;
        if (!is_array($slots)) {
            return array_fill(0, 27, null);
        }

        $out = array_fill(0, 27, null);
        foreach ($slots as $i => $slot) {
            if ($i >= 0 && $i < 27 && is_array($slot) && isset($slot['id'])) {
                $out[$i] = [
                    'id' => (int) $slot['id'],
                    'count' => (int) ($slot['count'] ?? 1),
                    'damage' => (int) ($slot['damage'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /** 这个坐标有没有容器记录（书架容器靠它区分普通书架）。 */
    /** 某个区块里的所有箱子坐标 [[x,y,z], ...] */
    public function positionsInChunk(int $cx, int $cz): array
    {
        $out = [];
        foreach (array_keys($this->data) as $key) {
            $p = explode(',', (string) $key);
            if (count($p) !== 3) {
                continue;
            }
            $x = (int) $p[0];
            $y = (int) $p[1];
            $z = (int) $p[2];
            if (($x >> 4) === $cx && ($z >> 4) === $cz) {
                $out[] = [$x, $y, $z];
            }
        }

        return $out;
    }

    public function has(int $x, int $y, int $z): bool
    {
        return isset($this->data[$x . ',' . $y . ',' . $z]);
    }

    /** 确保该坐标有一条记录（放箱子时调用）。 */
    public function ensure(int $x, int $y, int $z): void
    {
        $key = $x . ',' . $y . ',' . $z;
        if (isset($this->data[$key])) {
            return;
        }
        $this->data[$key] = array_fill(0, 27, null);
        @file_put_contents($this->file, json_encode($this->data), LOCK_EX);
    }

    /** 箱子被打掉时清掉记录（原版会 removeTileEntity） */
    public function remove(int $x, int $y, int $z): void
    {
        $key = $x . ',' . $y . ',' . $z;
        if (!isset($this->data[$key])) {
            return;
        }
        unset($this->data[$key]);
        @file_put_contents($this->file, json_encode($this->data), LOCK_EX);
    }

    /** @param array<int, array{id:int,count:int,damage:int}|null> $slots */
    public function save(int $x, int $y, int $z, array $slots): void
    {
        $this->data[$x . ',' . $y . ',' . $z] = $slots;

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($this->file, json_encode($this->data), LOCK_EX);
    }
}
