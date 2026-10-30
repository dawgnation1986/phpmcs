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
        // 和开箱子一样：先把别的容器状态清掉，否则点击会被分发错
        $c->furnace = null;
        $this->closeChest($c);
        $this->closeWorkbench($c);
        $this->returnCraftGrid($c);

        $slots = $this->enderSlots($c->username);
        $c->chest = [
            'x' => 0, 'y' => 0, 'z' => 0,
            'size' => 27,
            'partner' => null,
            'slots' => $slots,
            'ender' => true,
            'owner' => $c->username,
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

        $this->log("  {$c->username} 打开末影箱");
    }
}
