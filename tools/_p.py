"""加：掉落伤害 + 光照段更新（只发光照，不重发整块）。"""
import sys

SERVER = r"D:/codextest/bigfish/phpmcs/src/Server.php"
CHUNK = r"D:/codextest/bigfish/phpmcs/src/World/Chunk.php"

s = open(SERVER, encoding="utf-8", newline="").read()
c = open(CHUNK, encoding="utf-8", newline="").read()

CP = [(
    "sectionPayload",
    "    /** 一段的方块数据：4096 个小端 ushort，(id << 4) | meta */",
    "    /**\n"
    "     * 单独一段的负载：方块 + 方块光 + 天空光。\n"
    "     * 只有一段时，这个顺序和整块的分组布局是一样的。\n"
    "     */\n"
    "    public function sectionPayload(int $sy): ?string\n"
    "    {\n"
    "        $sec = $this->sectionFor($sy);\n"
    "        if ($sec === null) {\n"
    "            return null;\n"
    "        }\n"
    "\n"
    "        return $this->blocksBytes($sec)\n"
    "            . (Nbt::bytes($sec, 'BlockLight') ?? str_repeat(\"\\x00\", 2048))\n"
    "            . (Nbt::bytes($sec, 'SkyLight') ?? str_repeat(\"\\xff\", 2048));\n"
    "    }\n"
    "\n"
    "    /** 一段的方块数据：4096 个小端 ushort，(id << 4) | meta */",
)]

SP = [
    (
        "连接里加血量",
        "    public int $heldSlot = 0;",
        "    public int $heldSlot = 0;\n"
        "    public float $health = 20.0;\n"
        "    public int $food = 20;\n"
        "    /** 开始下落时的高度，落地时用来算摔伤 */\n"
        "    public float $fallFrom = 0.0;",
    ),
    (
        "markSection 记段",
        "    private function markSection(int $x, int $y, int $z): void\n"
        "    {\n"
        "        // 故意什么都不做。\n"
        "        // 原版改方块只发 Block Change，从不重发区块数据。\n"
        "        // 之前这里改方块后整块重发（groundUp=true），客户端会重新加载那个 chunk，\n"
        "        // 顺手把刚生成掉落物实体清掉 —— 表现就是「东西能捡但不显示」。\n"
        "        // 分段更新（groundUp=false）也不能发，会把客户端的 chunk 读串。\n"
        "    }",
        "    private function markSection(int $x, int $y, int $z): void\n"
        "    {\n"
        "        // 只补发「这一段」，不重发整块。\n"
        "        // 整块（groundUp=true）会让客户端重载 chunk，把刚生成的掉落物实体清掉，\n"
        "        // 所以只用 groundUp=false 的段更新，专门用来把新光照送过去。\n"
        "        $this->dirtySections[$x >> 4 . ',' . $z >> 4 . ',' . $y >> 4]\n"
        "            = [$x >> 4, $z >> 4, $y >> 4];\n"
        "    }",
    ),
    (
        "flushSections 只发段",
        "    /** 每拍最多重发两个区块，别把带宽打爆；同一个区块 0.15 秒内不重复发。 */\n"
        "    private function flushSections(): void\n"
        "    {\n"
        "        if ($this->dirtySections === []) {\n"
        "            return;\n"
        "        }\n"
        "        $now = microtime(true);\n"
        "        $sent = 0;\n"
        "        foreach ($this->dirtySections as $key => [$cx, $cz]) {\n"
        "            if (($this->lastChunkPush[$key] ?? 0.0) > $now - 0.15) {\n"
        "                continue;\n"
        "            }\n"
        "            unset($this->dirtySections[$key]);\n"
        "            $this->lastChunkPush[$key] = $now;\n"
        "\n"
        "            [$mask, $data] = $this->world->chunkPayload($cx, $cz);\n"
        "            $packet = (new Writer())\n"
        "                ->int($cx)\n"
        "                ->int($cz)\n"
        "                ->bool(true)                 // 整块发，客户端才认\n"
        "                ->short($mask)\n"
        "                ->varInt(strlen($data))\n"
        "                ->raw($data);\n"
        "            foreach ($this->players() as $p) {\n"
        "                $p->send(self::C_CHUNK_DATA, $packet);\n"
        "            }\n"
        "            if (++$sent >= 2) {\n"
        "                break;\n"
        "            }\n"
        "        }\n"
        "    }",
        "    /**\n"
        "     * 把改过的段补发一次（groundUp=false）。\n"
        "     * 1.8 没有单独的光照包，客户端也不会自己重算天空光，\n"
        "     * 所以挖开的洞要么全黑、要么靠这一步把新光照送过去。\n"
        "     */\n"
        "    private function flushSections(): void\n"
        "    {\n"
        "        if ($this->dirtySections === []) {\n"
        "            return;\n"
        "        }\n"
        "        $now = microtime(true);\n"
        "        $sent = 0;\n"
        "        foreach ($this->dirtySections as $key => [$cx, $cz, $sy]) {\n"
        "            if (($this->lastSectionPush[$key] ?? 0.0) > $now - 0.12) {\n"
        "                continue;\n"
        "            }\n"
        "            unset($this->dirtySections[$key]);\n"
        "            $this->lastSectionPush[$key] = $now;\n"
        "\n"
        "            $data = $this->world->chunk($cx, $cz)->sectionPayload($sy);\n"
        "            if ($data === null) {\n"
        "                continue;\n"
        "            }\n"
        "            $packet = (new Writer())\n"
        "                ->int($cx)\n"
        "                ->int($cz)\n"
        "                ->bool(false)                // 只更新这一段\n"
        "                ->short(1 << $sy)\n"
        "                ->varInt(strlen($data))\n"
        "                ->raw($data);\n"
        "            foreach ($this->players() as $p) {\n"
        "                $p->send(self::C_CHUNK_DATA, $packet);\n"
        "            }\n"
        "            if (++$sent >= 4) {\n"
        "                break;\n"
        "            }\n"
        "        }\n"
        "    }",
    ),
    (
        "属性改名",
        "    /** @var array<string, float> 上次整块重发的时间，用来做节流 */\n"
        "    private array $lastChunkPush = [];",
        "    /** @var array<string, float> 上次补发段的时间，用来做节流 */\n"
        "    private array $lastSectionPush = [];",
    ),
    (
        "tick 里恢复补发",
        "        $this->processDrops();",
        "        $this->processDrops();\n        $this->flushSections();",
    ),
    (
        "进服发满血",
        "        // 3) 能力：不发这个，创造模式等于没开（不能飞、不是无敌）\n"
        "        $this->sendAbilities($c);",
        "        // 3) 能力：不发这个，创造模式等于没开（不能飞、不是无敌）\n"
        "        $this->sendAbilities($c);\n"
        "\n"
        "        // 血量（不发病客户端默认满血，但要让它知道是服务端在管）\n"
        "        $c->health = 20.0;\n"
        "        $this->sendHealth($c);",
    ),
]

SP.append((
    "血量包常量",
    "    private const C_BLOCK_CHANGE = 0x23;",
    "    private const C_UPDATE_HEALTH = 0x06;\n    private const C_BLOCK_CHANGE = 0x23;",
))

SP.append((
    "sendHealth + damage",
    "    private function sendChat(Connection $c, array $component, int $position = 1): void",
    """    private function sendHealth(Connection $c): void
    {
        $c->send(self::C_UPDATE_HEALTH, (new Writer())
            ->float($c->health)
            ->varInt($c->food)
            ->float(5.0));
    }

    /** 掉血；掉到 0 就回出生点（不做死亡界面，先把机制跑通）。 */
    private function damage(Connection $c, int $dmg): void
    {
        if ($dmg <= 0 || $c->gamemode === 1) {
            return;
        }
        $c->health = max(0.0, $c->health - $dmg);
        $c->send(self::C_UPDATE_HEALTH, (new Writer())
            ->float($c->health)->varInt($c->food)->float(5.0));
        $this->log("  {$c->username} 掉血 {$dmg}，剩 {$c->health}");

        if ($c->health <= 0.0) {
            [$sx, $sy, $sz] = $this->world->spawnPoint();
            $c->x = $sx + 0.5;
            $c->y = (float) $sy;
            $c->z = $sz + 0.5;
            $c->health = 20.0;
            $c->fallFrom = -1.0;
            $c->send(self::C_POS_LOOK, (new Writer())
                ->double($c->x)->double($c->y)->double($c->z)
                ->float(0)->float(0)->byte(0));
            $this->sendHealth($c);
        }
    }

    private function sendChat(Connection $c, array $component, int $position = 1): void""",
))

SP.append((
    "掉落伤害",
    "        if ($yaw !== null) {\n            $c->yaw = $yaw;\n            $c->pitch = (float) $pitch;\n        }\n        $c->onGround = $onGround;",
    """        if ($yaw !== null) {
            $c->yaw = $yaw;
            $c->pitch = (float) $pitch;
        }

        // 掉落伤害：离地时记住高度，落地时按 (高度差 - 3) 算。
        // 原版 EntityPlayer.fall() 是 ceil(距离 - 3)，创造模式不摔伤。
        if (! $onGround && $moved) {
            $c->fallFrom = $c->fallFrom < 0 ? $c->y : max($c->fallFrom, $c->y);
        } elseif ($onGround && $c->fallFrom >= 0) {
            $fall = $c->fallFrom - $c->y;
            $c->fallFrom = -1.0;
            if ($fall > 3.0) {
                $this->damage($c, (int) ceil($fall - 3.0));
            }
        }

        $c->onGround = $onGround;""",
))

for label, old, new in CP:
    if c.count(old) != 1:
        sys.exit(f"✗ [Chunk/{label}] 命中 {c.count(old)}")
    c = c.replace(old, new)
    print(f"✓ Chunk: {label}")

for label, old, new in SP:
    if s.count(old) != 1:
        sys.exit(f"✗ [Server/{label}] 命中 {s.count(old)}")
    s = s.replace(old, new)
    print(f"✓ Server: {label}")

open(CHUNK, "w", encoding="utf-8", newline="").write(c)
open(SERVER, "w", encoding="utf-8", newline="").write(s)
print("done")
