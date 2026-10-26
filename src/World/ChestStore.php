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
