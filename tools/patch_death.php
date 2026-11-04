<?php
declare(strict_types=1);

/**
 * 一次性补丁：死亡重生
 *   1. 睡过的床记成重生点（之前只做了「躺下」，床坐标根本没存）
 *   2. 死亡时切回主世界维度 + 重发区块 + 重发背包
 *
 * 用户反馈：「死亡之后到主世界的东西是没有的，必须返回地狱再出来才有」
 * 根因：damage() 掉到 0 只改了坐标发了个 0x07，既不清 sentChunks 也不重发
 * 背包 —— 客户端还在地狱维度、显示的还是旧区块，背包 Widget 里是空的。
 * 跨维度那套逻辑会清缓存重发，所以「回地狱再出来」就恢复了。
 */

$path = __DIR__ . '/../src/Server.php';
$text = file_get_contents($path);
$EOL = str_contains($text, "\r\n") ? "\r\n" : "\n";
$text = str_replace("\r\n", "\n", $text);

$edits = [];

// ---- 1) Connection 加床重生点 ----
$edits[] = [
    "    /** 正在睡的床坐标 [x,y,z] */\n    public ?array \$sleepBed = null;",
    "    /** 正在睡的床坐标 [x,y,z] */\n    public ?array \$sleepBed = null;\n    /** 睡过的床 -> 死亡重生点 [x,y,z]（原版 SpawnChunk 机制） */\n    public ?array \$bedSpawn = null;",
];

// ---- 2) 睡觉时记下重生点 ----
$edits[] = [
    "                    if (\$this->isNight()) {\n                        // 躺下：0x0A Use Bed\n                        \$this->sleeping[\$c->entityId] = microtime(true) + 5.0;",
    "                    if (\$this->isNight()) {\n                        // 躺下：0x0A Use Bed。\n                        // 顺便把这张床记成重生点 —— 之前只做了「躺下」，\n                        // 死了还是回世界出生点，床白睡了。\n                        \$c->bedSpawn = [(float) \$x + 0.5, (float) \$y + 1.0, (float) \$z + 0.5];\n                        \$this->sleeping[\$c->entityId] = microtime(true) + 5.0;",
];

// ---- 3) 死亡重生：切维度 + 重发区块 + 重发背包 ----
$oldDie = <<<'OLD'
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
OLD;
$newDie = <<<'NEW'
        if ($c->health <= 0.0) {
            // 重生点：睡过的床优先，否则世界出生点
            if ($c->bedSpawn !== null) {
                $c->x = (float) $c->bedSpawn[0];
                $c->y = (float) $c->bedSpawn[1];
                $c->z = (float) $c->bedSpawn[2];
            } else {
                [$sx, $sy, $sz] = $this->world->spawnPoint();
                $c->x = (float) $sx + 0.5;
                $c->y = (float) $sy;
                $c->z = (float) $sz + 0.5;
            }

            $c->health = 20.0;
            $c->food = 20;
            $c->saturation = 5.0;
            $c->fallFrom = -1.0;
            $c->portalDim = 0;

            // 客户端收到 0x07 会把世界整个丢掉重建：区块缓存要清，
            // 背包也要重发，否则背包窗口里全是空的，得等他跨一次维度才恢复。
            $c->sentChunks = [];
            $c->chunkQueue = [];

            $c->send(self::C_RESPAWN, (new Writer())
                ->int(0)                       // 主世界
                ->byte(1)                      // 难度 normal
                ->byte($c->gamemode)
                ->string('default'));
            $c->send(self::C_POS_LOOK, (new Writer())
                ->double($c->x)->double($c->y)->double($c->z)
                ->float(0)->float(0)->byte(0));
            $this->queueChunks($c);
            $this->sendHealth($c);
            $this->sendPlayerInventory($c);
            $this->sendCursor($c);

            $this->log(sprintf('  %s 死亡，重生到 %.1f,%.1f,%.1f%s',
                $c->username, $c->x, $c->y, $c->z,
                $c->bedSpawn !== null ? '（床上）' : '（世界出生点）'));
        }
NEW;
$edits[] = [$oldDie, $newDie];

foreach ($edits as $i => [$old, $new]) {
    $n = substr_count($text, $old);
    if ($n !== 1) {
        fwrite(STDERR, "第 " . ($i + 1) . " 处命中 $n 次，中止\n");
        exit(1);
    }
    $text = str_replace($old, $new, $text);
}

$text = str_replace("\n", $EOL, $text);
file_put_contents($path, $text);
echo "死亡重生已修，" . count($edits) . " 处\n";
