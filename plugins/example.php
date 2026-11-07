<?php
declare(strict_types=1);

/**
 * 示例插件 —— 这个文件同时也是格式说明。
 *
 * 返回一个数组：
 *   name          插件名（只用于日志）
 *   onXxx         事件处理函数，第一个参数永远是 Server 实例
 *
 * 能拿到的东西都走 $server->api*()，因为 Server 的内部方法几乎都是 private。
 * 现在开放了：
 *
 *   apiGiveItem($player, $itemId, $count, $extra)   给物品
 *   apiChat($player, $text)                        给某人发系统消息
 *   apiBroadcast($text)                            全服广播
 *   apiPlayers()                                   所有在线 Connection
 *   apiBlock($x, $y, $z)                           读方块 id
 *   apiSetBlock($x, $y, $z, $id, $meta)            改方块（会广播给所有人）
 *   apiLog($msg)                                   写日志
 *   apiWorld()                                     底层 AnvilWorld
 *
 * 现在支持的事件：
 *
 *   onJoin($server, $player)
 *   onChat($server, $player, $text)
 *   onBlockBreak($server, $player, $x, $y, $z, $blockId)
 *
 * 插件抛异常只会写进 error_log，不会把服务端带崩。
 */

return [
    'name' => '示例',

    'onJoin' => function ($server, $player) {
        $server->apiChat($player, '欢迎回来，' . $player->username);
    },

    'onChat' => function ($server, $player, $text) {
        if ($text === '!在线') {
            $names = [];
            foreach ($server->apiPlayers() as $p) {
                $names[] = $p->username;
            }
            $server->apiChat($player, count($names) . ' 人在线：' . implode('、', $names));
        }
    },

    'onBlockBreak' => function ($server, $player, $x, $y, $z, $id) {
        // 地狱岩有 1/8 概率额外掉一颗钻石
        if ($id === 87 && random_int(1, 8) === 1) {
            $server->apiGiveItem($player, 264, 1);
            $server->apiChat($player, '这块地狱岩里居然有钻石');
        }
    },
];
