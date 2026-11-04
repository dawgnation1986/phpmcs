<?php
declare(strict_types=1);

/**
 * 一次性补丁：PortalTrait 从「现搭平台」换成「真地狱地形 + 入口房间」。
 * 读一次 → 逐处断言替换 → 只写一次。
 */

$path = __DIR__ . '/../src/PortalTrait.php';
$text = file_get_contents($path);
if ($text === false) {
    fwrite(STDERR, "读不到 $path\n");
    exit(1);
}
$EOL = str_contains($text, "\r\n") ? "\r\n" : "\n";

$edits = [];

// 1) 头注释
$oldDoc = <<<'TXT'
/**
 * 地狱门（A 方案：传送门 + 固定平台，不做真地形）。
 *
 * 做法：
 *   1. 打火石点黑曜石 → 在附近找 4×5 的黑曜石框，内部填传送门方块(90)
 *   2. 站在传送门里 2 秒 → 传送到「地狱平台」（同一世界、远坐标）
 *   3. 平台是现搭的：24×24 地狱岩地面 + 四周围栏 + 一个回程门
 *   4. 在地狱那边站门里 → 传回原来的位置
 *
 * 为什么不去改维度：
 *   1.8 切维度要发 0x07 并重建整个客户端世界，还要给 AnvilWorld 加 DIM-1 目录，
 *   一旦改错主世界也会崩。这里用「远坐标 + 地狱岩地面」模拟，
 *   客户端看起来就是地狱，风险为零。
 */
TXT;
$newDoc = <<<'TXT'
/**
 * 地狱门。
 *
 * 做法：
 *   1. 打火石点黑曜石 → 向四周探查黑曜石框，内部填传送门方块(90)
 *   2. 站在传送门里 2 秒 → 传送到地狱（主世界地图上 (200000,200000) 那块坐标带）
 *   3. 落点周围清一个 7×7×5 的房间，北墙嵌回程门
 *   4. 在地狱那边站门里 → 传回原来的位置
 *
 * 地狱是真地形，不是现搭的平台：
 *   AnvilWorld 在 (12500,12500) 半径 128 区块内改用 NetherGenerator，
 *   有岩浆海、分层洞穴、萤石、灵魂沙、石英矿。
 *   没建 DIM-1 目录 —— 维度靠 0x07 包切（客户端会按 dimension -1 渲染），
 *   方块数据还是同一个 AnvilWorld，只是那块坐标带是地狱地形。
 */
TXT;
$edits[] = [$oldDoc, $newDoc];

// 2) 传送分支：改为真地形 + 入口房间
$oldTp = <<<'TXT'
        if ($atNether) {
            // 回主世界：回到进门前的位置
            [$tx, $ty, $tz] = $c->portalReturn ?? [-63.0, 64.0, -42.0];
        } else {
            $this->ensureNetherPlatform();
            $c->portalReturn = [$c->x, $c->y, $c->z];
            $tx = self::NETHER_X + 0.5;
            $ty = (float) (self::NETHER_Y + 1);
            $tz = self::NETHER_Z + 0.5;
        }
TXT;
$newTp = <<<'TXT'
        if ($atNether) {
            // 回主世界：回到进门前的位置
            [$tx, $ty, $tz] = $c->portalReturn ?? [-63.0, 64.0, -42.0];
        } else {
            $c->portalReturn = [$c->x, $c->y, $c->z];
            // 落点高度由地形决定（地狱的洞穴在不同高度），不能写死
            $standY = $this->ensureNetherEntry();
            $tx = self::NETHER_X + 0.5;
            $ty = (float) $standY;
            $tz = self::NETHER_Z + 0.5;
        }
TXT;
$edits[] = [$oldTp, $newTp];

// 3) atNether 判定改成按区块带
$edits[] = [
    "        \$atNether = abs(\$c->x - self::NETHER_X) < 64 && abs(\$c->z - self::NETHER_Z) < 64;",
    "        // 用区块带判断，不能用「离落点 64 格」—— 玩家在地狱走远了就判错，\n        // 会以为自己在主世界，一站门又给送回地狱。\n        \$atNether = \$this->world->isNetherAt((int) floor(\$c->x), (int) floor(\$c->z));",
];

foreach ($edits as $i => [$old, $new]) {
    $old = str_replace("\n", $EOL, $old);
    $new = str_replace("\n", $EOL, $new);
    $n = substr_count($text, $old);
    if ($n !== 1) {
        fwrite(STDERR, "第 " . ($i + 1) . " 处命中 $n 次，中止\n");
        exit(1);
    }
    $text = str_replace($old, $new, $text);
}

file_put_contents($path, $text);
echo "PortalTrait.php 已更新，" . count($edits) . " 处\n";
