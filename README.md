# phpmcs

PHP 写的 Minecraft Java 1.8.9 服务端（协议 47）。单进程，`stream_select` 事件循环，没有扩展依赖。

```
php bin/server.php 25565 "PHP 写的 1.8.9" world
```

存档目录不给就用项目下的 `world/`。目录里没有 `level.dat` 会按 Anvil 格式新建一份，新建出来是标准存档，单机能打开。

## 现在能跑通什么

登录（离线模式）、区块收发、聊天和几个命令、创造/生存切换、挖方块放方块、
掉落物和拾取、两个玩家互相看见、皮肤（走 Mojang 的签名纹理）、Anvil 存档读写、
箱子（单箱/双箱合并、TileEntity 同步、幽灵方块纠正）、
近似原版地形生成（Perlin 噪声、河流、海洋/沙滩/平原/森林/沙漠/针叶林/冰原/
沼泽/热带草原/山地，树、仙人掌、雪、水边甘蔗）、
2x2 和工作台合成（木板/木棍/工作台/火把/箱子/熔炉/木石铁钻金工具）。

命令：`/gm /spawn /who /help /block x y z /save /reload`

## 还差什么

生存模式的挖掘计时、生物和实体 AI、红石、下界和末地、熔炉冶炼、盔甲/更多配方。
地形只是近似生成，没有洞穴、矿脉、村庄和结构；已有区块和新生成区块的交界可能有接缝。
区块不压缩，49 个区块约 3.7 MB，公网跑得动但很浪费，下一步该上 zlib。

## 测试

`tools/` 下是几个按协议逐字段验的脚本，不依赖真客户端：

```
python tools/play_test.py     登录 / 区块 / 聊天 / 两人互见
python tools/dig_test.py      挖放方块、创造不掉落
python tools/drop_test.py     掉落物 + 拾取 + 区块段更新
python tools/action_test.py   Q 丢物品、挥手广播
python tools/skin_test.py     标签列表里的皮肤属性
python tools/check_save.py    用独立实现解析 .mca 和 level.dat
```

## 两个 1.8 的坑

实体元数据是**一个字节**：高 3 位类型、低 5 位索引（1.9 才拆成两个字节）。
`Spawn Object` 只有 `data > 0` 时才跟速度三个 short；给 0 客户端会把物品钉在出生点。
这两条写错都不报错，只是画面不对或者客户端崩掉。
