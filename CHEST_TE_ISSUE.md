# 求助：PHP 从零实现的 1.8.9 服务端，箱子重进后客户端不渲染（TE 问题）

> 本文件可直接整段复制给别人。最后更新：2026-10-27

## 0. 我们是什么

- **语言：PHP 8（CLI）**，不是 Bukkit/Spigot/Paper 插件，也不跑 Java。
- 从零实现 **Minecraft 1.8.9 / 协议 47** 的服务端，直接读写 Anvil `.mca` 存档。
- 单进程 `stream_select` 事件循环，手写协议编解码。
- 已实现：离线登录、移动、方块破坏/放置、掉落物、背包/箱子/工作台容器、
  2x2+3x3 合成、地形生成、群系、生物生成与简单 AI、昼夜循环、少量指令。
- 客户端：官方 **1.8.9 vanilla**，离线模式，Windows。

**这意味着：我们没有任何 Java 侧 API**（没有 `world.setBlockState(pos, state, 3)`、
没有 `Chunk.markDirty()`），所有效果只能靠**协议包**达成。

## 1. 现象（稳定复现）

1. 玩家放置箱子（方块 id 54，`state = (54<<4)|meta = 864~869`）：
   方块可见、能打开、能存取。
2. 退出到标题再进来（或换客户端进）：
   **箱子所在格子看不见**，但——
   - 服务端内部查询该坐标仍是 54；
   - 右键该位置仍能正常打开、内容完整；
   - 客户端 F3 该位置显示为**空气**。
3. 在**同一坐标重新放一个箱子**，立刻恢复正常。
4. 服务端已发送 `0x35 Update Tile Entity`，payload 为
   `long`(packed BlockPos) + `byte action` + NBT `{id:"Chest", x, y, z}`。

## 2. 我们已经自查、确认没问题的部分

### 2.1 区块数据（0x21）里那个坐标确实是箱子

以示例坐标 `(-220, 71, 40)` 为例，实测：

```
chunk        = (-14, 2)
section Y    = 4
local        = (4, 7, 8)
索引 index   = (7<<8) | (8<<4) | 4 = 1924

chunkPayload(-14, 2):
  mask     = 0x001f（5 个段）
  负载长度 = 61696 字节
  段内偏移 = (4 * 4096 + 1924) * 2 = 36616
  解出        state = 864 (id 54, meta 0)   ✓
```

61696 = 5×4096×2（方块） + 5×(2048+2048)（方块光+天空光），结构正确。

### 2.2 NBT 的负数编码正确

```
x = -220  ->  ff ff ff 24    ✓
y =  71   ->  00 00 47       ✓（实际是 00 00 00 47）
```

> ⚠️ 早前口头给出的示例里写成 `03 00 01 78 00 00 00 dc`（= +220），
> 那是手写示例算错了。**实际代码输出是 `ff ff ff 24`，是对的。**

### 2.3 坐标打包正确

```
hi32 = ((x & 0x3FFFFFF) << 6) | ((y & 0xFFF) >> 6)
lo32 = (((y & 0xFFF) & 0x3F) << 26) | (z & 0x3FFFFFF)
bytes = pack('N2', hi32, lo32)          // 大端 8 字节
```

## 3. 客户端侧（反编译 1.8.9 核对到的事实）

- `handleUpdateTileEntity`：先 `world.isBlockLoaded(pos)`，再
  `world.getTileEntity(pos)`，然后按 `action` 决定是否 `readFromNBT`。
- `World.getTileEntity` → `Chunk.getTileEntity(pos, EnumCreateEntityType.IMMEDIATE)`
  （枚举 `a=IMMEDIATE b=QUEUED c=CHECK`），IMMEDIATE 会调用
  `createNewTileEntity`。
- 但 `createNewTileEntity` **不是无条件创建**：它先读该位置的方块状态，
  再判断 `block.hasTileEntity()`。**如果客户端那一格是空气，创建直接返回 null。**
- 箱子 `Block.getRenderType() == -1`，没有方块模型，完全由
  `TileEntityChestRenderer` 绘制；`RenderGlobal` 每帧遍历的是
  `CompiledChunk` 里的 TE 列表，该列表由 `RenderChunk.rebuild` 填充，
  而 `rebuild` 是**整段全量扫描**（并非只扫新变化的方块）。
- `handleBlockChange` 走 `invalidateRegionAndSetBlock`，同状态也会触发渲染范围更新。
- `Chunk.setBlockState`：`if (old == newState) return null;`

## 4. 已试过、确认无效的方案

| # | 方案 | 结果 |
|---|---|---|
| 1 | 进服后延迟 1.2s 补发一次 0x35 | 无效 |
| 2 | 15 轮补发 0x35（0.4s 起，间隔 1.5s，共 21s） | 无效 |
| 3 | 0x35 + "0x23 空气 → 0x23 方块" 强制造状态变化 | 闪一下，随后箱子被空气打没，变成永久的洞 |
| 4 | 0x35 后再发**同状态** 0x23（依赖 `markBlockRangeForRenderUpdate` 无条件执行） | 抓包确认包已发出、客户端日志无异常，箱子仍不出现 |
| 5 | 服务端把该格在客户端画成别的方块（书架 47 / 棕色羊毛 35:12） | 能看见，但那是伪装，不是箱子 |

## 5. 目前最想不通的一点

服务端 `0x21` 里那格确实是 864，但客户端 F3 显示空气。

也就是说，**要么那个状态没落地，要么落地之后被别的东西覆盖成空气了**。
我们暂时没有手段在客户端侧确认「此刻它认为那一格是什么」。

## 6. 想请教的具体问题

1. 客户端 F3 显示空气、但服务端 0x21 负载确实是箱子，这种组合常见于哪些情况？
   （坐标解码？后续 0x23 覆盖？区块被丢弃重下？）
2. 1.8.9 里，有没有**协议层**的办法强制让客户端把某个区块的方块状态
   重新读取一遍？（我们试过重发同状态 0x23、重发单段 groundUp=false，
   后者会带来"存档旧光照覆盖客户端"的副作用。）
3. 官方服务端在玩家进入视距时，`0x21`/`0x26` 与 `0x35` 的先后与间隔是怎样的？
   是否必须在客户端把该区块标脏之前发出？
4. 如果你手上有能正常显示的 1.8.9 服务端（原版/Spigot/Glowstone/任意实现），
   能不能抓一个"退出→重进"的包序列？我们想看：
   - `0x21` 与 `0x35` 的相对顺序与 tick 间隔；
   - 期间有没有 `0x23`，state 是原值还是改过的值；
   - 有没有用到 `0x26`（bulk）。

## 7. 希望你们怎么帮

任一条都很有价值：

1. **抓包**（最有用）：任意能正常显示箱子的 1.8.9 服务端的进服包序列。
2. **贴一段你那边"给新玩家补发箱子"的实现**（Java 或其他语言都行），
   尤其是调用了哪些内部方法（`markDirty` / `markBlockForUpdate` /
   `notifyBlockUpdate` …）——我们想反推"必须做但协议包里做不到"的那一步。
3. 直接说"这条路走不通"也对我们有帮助——我们现在用书架伪装兜底
   （服务端方块仍是 54，右键照常开箱存取，进服提示"这些书架其实是箱子"）。

## 8. 附：0x35 实际字节

```
示例坐标 (-220, 71, 40)

packet id : 0x35
payload   : [8 字节 packed pos][action=0x00][NBT]

NBT(40 字节)：
  0a 00 00                      // TAG_Compound, name len = 0
  08 00 02 69 64                // TAG_String "id"
  00 05 43 68 65 73 74          // "Chest"
  03 00 01 78 ff ff ff 24       // TAG_Int "x" = -220   ← 注意是 ffffff24
  03 00 01 79 00 00 00 47       // TAG_Int "y" = 71
  03 00 01 7a 00 00 00 28       // TAG_Int "z" = 40
  00                            // TAG_End
```

`0x23 Block Change` payload：`[8 字节 packed pos][VarInt state]`，
state = `(id << 4) | meta`，箱子为 `864~869`。
