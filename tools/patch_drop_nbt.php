<?php
declare(strict_types=1);

/**
 * 一次性补丁：掉落物带 NBT（附魔/改名）。
 *
 * 原来 throwItem 的实体元数据里 NBT 标记写死 byte(0)，drops 数组也只记
 * id/count/meta。所以丢出去的附魔剑是白板，捡回来也是白板，
 * 重新进服当然还是白板 —— NBT 从来没离开过背包。
 */

$path = __DIR__ . '/../src/Server.php';
$text = file_get_contents($path);
$EOL = str_contains($text, "\r\n") ? "\r\n" : "\n";
$text = str_replace("\r\n", "\n", $text);

$edits = [];

// ---- 1) throwItem 签名加 $extra ----
$edits[] = [
    "        int \$vz,\n        float \$delay = 2.0\n    ): void {",
    "        int \$vz,\n        float \$delay = 2.0,\n        array \$extra = []\n    ): void {",
];

// ---- 2) 实体元数据用 slotBytes 拼（带 NBT）----
$edits[] = [
    "            \$p->send(self::C_ENTITY_METADATA, (new Writer())\n                ->varInt(\$eid)\n                ->byte((5 << 5) | 10)\n                ->short(\$id)->byte(\$count)->short(\$meta)->byte(0)\n                ->unsignedByte(0x7F));",
    "            // 用 slotBytes 拼物品栈：它会把 ench / display.Name 一起写进 NBT。\n            // 之前这里写死 byte(0)，丢出去的附魔装备就是白板。\n            \$p->send(self::C_ENTITY_METADATA, (new Writer())\n                ->varInt(\$eid)\n                ->byte((5 << 5) | 10)\n                ->raw(\$this->slotBytes(['id' => \$id, 'count' => \$count, 'damage' => \$meta] + \$extra))\n                ->unsignedByte(0x7F));",
];

// ---- 3) drops 存 extra ----
$edits[] = [
    "            'id' => \$id,\n            'count' => \$count,\n            'meta' => \$meta,",
    "            'id' => \$id,\n            'count' => \$count,\n            'meta' => \$meta,\n            'extra' => \$extra,",
];

// ---- 4) toss 加 $extra 并透传 ----
$edits[] = [
    "    private function toss(Connection \$c, int \$id, int \$count, int \$damage): void\n    {",
    "    private function toss(Connection \$c, int \$id, int \$count, int \$damage, array \$extra = []): void\n    {",
];
$edits[] = [
    "        \$this->throwItem(\$px, \$py, \$pz, \$id, \$count, \$damage,\n            (int) (\$dx * 2400), (int) (\$dy * 2400 + 600), (int) (\$dz * 2400));",
    "        \$this->throwItem(\$px, \$py, \$pz, \$id, \$count, \$damage,\n            (int) (\$dx * 2400), (int) (\$dy * 2400 + 600), (int) (\$dz * 2400), 2.0, \$extra);",
];

// ---- 5) dropFromSlot 把整件物品的附加字段带上 ----
$edits[] = [
    "        \$this->toss(\$c, \$it['id'], \$n, \$it['damage']);\n    }\n\n    /** 朝视线方向扔出一个物品实体",
    "        \$this->toss(\$c, \$it['id'], \$n, \$it['damage'],\n            array_diff_key(\$it, ['id' => 0, 'count' => 0, 'damage' => 0]));\n    }\n\n    /** 朝视线方向扔出一个物品实体",
];

// ---- 6) 捡起时恢复 NBT ----
$edits[] = [
    "            \$this->giveItem(\$picker, \$d['id'], \$d['count'], \$d['meta']);",
    "            \$this->giveItem(\$picker, \$d['id'], \$d['count'], \$d['meta'], \$d['extra'] ?? []);",
];

// ---- 7) 补发地上掉落物时也带 NBT ----
$edits[] = [
    "        \$p->send(self::C_ENTITY_METADATA, (new Writer())\n            ->varInt(\$eid)\n            ->byte((5 << 5) | 10)\n            ->short(\$d['id'])->byte(\$d['count'])->short(\$d['meta'])->byte(0)\n            ->unsignedByte(0x7F));",
    "        \$p->send(self::C_ENTITY_METADATA, (new Writer())\n            ->varInt(\$eid)\n            ->byte((5 << 5) | 10)\n            ->raw(\$this->slotBytes([\n                'id' => \$d['id'],\n                'count' => \$d['count'],\n                'damage' => \$d['meta'],\n            ] + (\$d['extra'] ?? [])))\n            ->unsignedByte(0x7F));",
];

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
echo "掉落物 NBT 已修，" . count($edits) . " 处\n";
