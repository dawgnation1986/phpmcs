<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 末影箱（方块 130）。
 *
 * 每个玩家一份 27 格，跨所有末影箱共享，按玩家名存到 cache/ender.json。
 * 界面直接复用箱子的窗口（windowId=1 / minecraft:chest / 27+36 格），
 * 区别只在数据来源：$c->chest['ender'] = true 时读写玩家的末影背包。
 */
trait EnderChestTrait
{
    /** @var array<string, array> 小写玩家名 => 27 格 */
    private array $enderInvs = [];

    /**
     * 哪些「黑曜石」其实是末影箱。
     *
     * 1.8 客户端不肯渲染 TE 方块（箱子当初就被换成了书架 47），
     * 末影箱同理 —— 服务端发 130 它也不画。所以世界数据里存 49（黑曜石），
     * 用这张表记住哪些位置是末影箱。
     */
    private array $enderPositions = [];
    private bool $enderPosLoaded = false;

    private function enderPosFile(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'ender-positions.json';
    }

    private function loadEnderPositions(): void
    {
        if ($this->enderPosLoaded) {
            return;
        }
        $this->enderPosLoaded = true;
        $f = $this->enderPosFile();
        if (is_file($f)) {
            $j = json_decode((string) @file_get_contents($f), true);
            if (is_array($j)) {
                $this->enderPositions = $j;
            }
        }
    }

    private function saveEnderPositions(): void
    {
        $dir = dirname($this->enderPosFile());
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($this->enderPosFile(), json_encode($this->enderPositions), LOCK_EX);
    }

    public function markEnderChest(int $x, int $y, int $z): void
    {
        $this->loadEnderPositions();
        $this->enderPositions[$x . ',' . $y . ',' . $z] = 1;
        $this->saveEnderPositions();
    }

    public function isEnderChest(int $x, int $y, int $z): bool
    {
        $this->loadEnderPositions();

        return isset($this->enderPositions[$x . ',' . $y . ',' . $z]);
    }

    public function unmarkEnderChest(int $x, int $y, int $z): void
    {
        $this->loadEnderPositions();
        unset($this->enderPositions[$x . ',' . $y . ',' . $z]);
        $this->saveEnderPositions();
    }

    /** @var array 玩家名 => ChestStore（末影箱专用） */
    private array $enderStores = [];
    private bool $enderLoaded = false;

    private function enderFile(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'ender.json';
    }

    private function loadEnder(): void
    {
        if ($this->enderLoaded) {
            return;
        }
        $this->enderLoaded = true;
        $path = $this->enderFile();
        if (is_file($path)) {
            $json = json_decode((string) @file_get_contents($path), true);
            if (is_array($json)) {
                $this->enderInvs = $json;
            }
        }
    }

    /** 取某个玩家的末影背包（27 格，缺的补 null） */
    private function enderSlots(string $name): array
    {
        $this->loadEnder();
        $k = strtolower($name);
        $slots = $this->enderInvs[$k] ?? [];
        $out = [];
        for ($i = 0; $i < 27; $i++) {
            $it = $slots[$i] ?? null;
            $out[] = (is_array($it) && isset($it['id']) && (int) ($it['count'] ?? 0) > 0) ? $it : null;
        }

        return $out;
    }

    private function saveEnderSlots(string $name, array $slots): void
    {
        $this->loadEnder();
        $clean = [];
        for ($i = 0; $i < 27; $i++) {
            $it = $slots[$i] ?? null;
            if (is_array($it) && isset($it['id']) && (int) ($it['count'] ?? 0) > 0) {
                $clean[$i] = [
                    'id' => (int) $it['id'],
                    'count' => (int) $it['count'],
                    'damage' => (int) ($it['damage'] ?? 0),
                ];
            }
        }
        $this->enderInvs[strtolower($name)] = $clean;
        $this->log(sprintf('  末影箱落盘 user=%s 有 %d 格 -> %s', $name, count($clean), $this->enderFile()));
        $dir = dirname($this->enderFile());
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents(
            $this->enderFile(),
            json_encode($this->enderInvs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    /** 打开末影箱界面 */
    private function openEnderChest(Connection $c): void
    {
        // 完全复用「普通箱子」那条已经跑通的路径：
        // 每个玩家一个独立的 ChestStore 文件，坐标固定 (8,4,8)，
        // $c->chest 的形状和 openChest 一模一样，只多带一个 store。
        $store = $this->enderStore($c->username);
        $store->ensure(8, 4, 8);
        $slots = $store->load(8, 4, 8);

        $c->furnace = null;
        $this->closeChest($c);
        $this->closeWorkbench($c);
        $this->returnCraftGrid($c);

        $c->chest = [
            'x' => 8, 'y' => 4, 'z' => 8,
            'size' => 27,
            'partner' => null,
            'slots' => $slots,
            'ender' => true,
            'owner' => $c->username,
            'store' => $store,
        ];

        $c->send(self::C_OPEN_WINDOW, (new Writer())
            ->byte(1)
            ->string('minecraft:chest')
            ->string(json_encode(['text' => '末影箱'], JSON_UNESCAPED_UNICODE) ?: '{"text":"Ender Chest"}')
            ->byte(27));

        $w = (new Writer())->byte(1)->short(27 + 36);
        foreach ($this->windowSlots($c) as $item) {
            $w->raw($this->slotBytes($item));
        }
        $c->send(self::C_WINDOW_ITEMS, $w);

        $this->log("  {$c->username} 打开末影箱（" . count(array_filter($slots, 'is_array')) . " 格有物品）");
    }

    /** 每个玩家一个 ChestStore 文件 */
    private function enderStore(string $name): \PhpMc\World\ChestStore
    {
        $key = strtolower($name);
        if (!isset($this->enderStores[$key])) {
            $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $this->enderStores[$key] = new \PhpMc\World\ChestStore(
                $dir . DIRECTORY_SEPARATOR . 'ender-' . preg_replace('/[^a-z0-9_]/', '_', $key) . '.json'
            );
        }

        return $this->enderStores[$key];
    }
}
