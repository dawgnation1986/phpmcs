# PHPMCS 1.8.9 Server - Handoff Notes

Date: 2026-10-28（地形生成大修见第 10 节）
Project root: `d:\codextest\bigfish\phpmcs`
Runtime: PHP 8 (CLI), Windows, single-process `stream_select` server

## 1. What this project is

This is a from-scratch PHP implementation of a Minecraft 1.8.9 (protocol 47) server. It is not a Bukkit/Spigot plugin; it speaks the vanilla 1.8.9 protocol directly and reads/writes Anvil `.mca` worlds.

Current features:

- Offline-mode login, keep-alive, chat, basic commands, creative/survival switch.
- Player movement, block break/place, block drops and pickup.
- Player inventory, chests, workbench, 2x2 crafting grid.
- Basic survival crafting (see section 4).
- Perlin-noise terrain generation with climate biomes and rivers.
- Anvil chunk read/write, legacy chunk migration, sky light.
- Skin fetching, a few server commands, `start.bat` launcher.

Not implemented yet: mobs/entity AI, caves/ores/villages/structures, furnace smelting, armor equip, brewing/enchanting, redstone, Nether/End, online-mode encryption, chunk compression.

## 2. How to run

- Double-click `start.bat`.
- It calls `start.ps1`, which:
  - checks port 25565 and force-kills all PHP processes listening on it;
  - waits for the port to be released;
  - starts `php bin/server.php` with the project `world/` directory.
- Edit `$Port`, `$Motd`, `$World`, `$Php` at the top of `start.ps1`.
- PHP loads code at process start. **Any PHP change requires restarting the server.**
- Logs: `logs/server.log`. Crafting debug lines are prefixed with `合成`, `工作台点击`, `拖动`, `背包满`.

## 3. Important files

| File | Purpose |
|---|---|
| `bin/server.php` | Entry point; requires all `src/` files and starts `Server`. |
| `src/Server.php` | Network loop, packet handling, players, blocks, drops, inventory, windows, drag, commands. |
| `src/CraftingTrait.php` | 2x2 and workbench 3x3 crafting, recipe matching, output slot, workbench open/close. |
| `src/ChestTrait.php` | Chest window, chest storage access, chest click handling. |
| `src/Protocol.php` | VarInt, packet reader/writer, packet id constants. |
| `src/World/AnvilWorld.php` | Region/chunk load/save, generator call, legacy migration, dirty chunk saving. |
| `src/World/Chunk.php` | Anvil section/nibble decoding, block access, block payload, light. |
| `src/World/TerrainGenerator.php` | 原版式 3D 密度场地形生成（16 段，y=0..255）+ 表层规则 + 洞穴 + 矿石 + 植被。 |
| `src/World/DensityField.php` | 1.8 `ChunkProviderGenerate.func_147423_a` 的密度网格移植（5×33×5）。 |
| `src/World/Layer/*` | 原版群系层（生物群系与真存档逐块一致）。 |
| `src/World/ChestStore.php` | Persistent chest contents. |
| `src/Nbt/Tag.php`, `src/Nbt/Nbt.php` | NBT read/write. |
| `src/Skin/Skins.php` | Mojang skin/texture fetching. |
| `tools/check_save.py` | Validates an Anvil world read by the server. |
| `start.bat`, `start.ps1` | Launcher and configuration. |
| `HANDOFF.md` | This document. |

## 4. What was changed in this round

### 4.1 Container window IDs (1.8 quirk)

The 1.8 client takes the `windowId` from the Open Window packet and assigns it to `openContainer.windowId`. It also decides between the real GUI and a generic container GUI based on `slotCount`.

Correct values:

- Player inventory: `windowId = 0`, no Open Window packet (implicit).
- Chest: `windowId = 1`, `slotCount = 27` for a single chest, `54` for a double chest; `Window Items` has `slotCount + 36` slots (63 or 90).
- Workbench: `windowId = 2`, **`slotCount = 0`** in Open Window, `Window Items` has 46 slots.

Why workbench `slotCount` must be 0: for GUI-ID containers (`"minecraft:crafting_table"`), the client only takes the real GUI branch when `slotCount <= 0`. If you send 46, it falls back to a generic container GUI that looks like a chest. The real 46 slots are defined by the following `Window Items` packet.

All workbench packets must use `windowId = 2`:

- Open Window / Window Items;
- Set Slot;
- cursor slot (`Set Slot` with slot -1);
- Close Window;
- click routing (`windowId === 2 -> clickWorkbench`).

Using 0 for the workbench collides with the implicit player inventory container and breaks the 3x3 grid.

### 4.2 Full-window resync after clicks

After every real click (and after the end of a drag), the server now sends a full `Window Items` packet for the currently open window:

- Player inventory: 45 slots (0 output, 1-4 2x2 grid, 5-8 armor, 9-44 inventory).
- Chest: 63 slots single / 90 slots double (chest slots first, then 27 main inventory, then 9 hotbar).
- Workbench: 46 slots (0 output, 1-9 3x3 grid, 10-36 main, 37-45 hotbar).

This overwrites the client's local prediction with server state. It fixes:

- Shift-click duplicating stacks;
- items disappearing after crafting;
- items that appear placed on the client but are absent on the server.

The cursor item is still synchronized separately with `Set Slot`.

**1.8 cursor packet:** the cursor is **not** "slot -1 of the current window". It must be sent with `windowId = -1` **and** `slot = -1`. If you use the current window id (0/1/2) with slot -1, the client takes the container branch, calls `List.get(-1)`, and throws `IndexOutOfBoundsException: Index -1 out of bounds for length 45/46/63`. This was visible in the client log on every join and every click, and it broke the inventory/chest/workbench GUI. `Server::sendCursor()` now always sends `windowId = -1`.

### 4.3 Mode 5 mouse drag

1.8 drag packets use mode 5 with this encoding:

- `phase = button & 3`: 0 = start, 1 = add slot, 2 = end.
- `mouse = (button >> 2) & 3`: 0 = left, 1 = right, 2 = middle.
- Start and end packets use `slot = -999`.

The server now accumulates dragged container slots and applies them at the end:

- left drag: distribute the cursor stack evenly (integer division) across slots;
- right drag: place one item per slot until the cursor is empty.

Supported in player inventory, chest, and workbench. This is what makes dragging a stack into the 2x2/3x3 grid work.

### 4.4 Inventory capacity and overflow

`canGive()` checks whether the player inventory can hold a whole stack/craft result.

- Crafted output: if the inventory is full, the output is **kept in the output slot** and the grid is not consumed.
- Ground drops: if the inventory is full, the drop stays on the ground instead of being destroyed and lost.
- Shift-crafting into a full inventory no longer silently eats the result.

If the player already has a full inventory (for example, many 64-stack oak planks duplicated by the earlier bug), they must free slots before crafting. The server cannot distinguish legitimately obtained stacks from previously duplicated ones.

### 4.5 Crafting

Implemented in `src/CraftingTrait.php`:

- Player 2x2 grid:
  - 1 log -> 4 planks (oak/spruce/birch/jungle, acacia/dark oak);
  - 2 planks vertical -> 4 sticks;
  - 4 planks 2x2 -> 1 crafting table;
  - coal/charcoal above stick -> 4 torches.
- Workbench 3x3:
  - all of the above;
  - 8 planks ring -> chest;
  - 8 cobblestone ring -> furnace;
  - wood/stone/iron/diamond/gold tools: sword, pickaxe, axe, shovel, hoe (axe/hoe accept mirrored patterns).
- Output preview is recalculated after every grid change.
- Tool max stack is 1; armor 1; snowball/egg/ender pearl 16; everything else 64.

### 4.5.1 Shift-click behavior (custom / required by the user)

- **Shift + click the crafting output**: crafts **all possible sets at once**, not one set per click. The number of sets is limited by:
  - the smallest material stack in the non-empty grid cells;
  - the free space in the player inventory for the result item.
  Example: 59 logs in one workbench grid cell -> one Shift-click produces 236 planks and empties the grid, if the inventory can hold them.
- **Shift + click an item in the inventory (2x2 or workbench)**: quick-fills the crafting grid.
  - It puts the whole stack into the first empty grid cell, or merges it into a matching cell.
  - If the grid has no room, it falls back to the normal main-inventory/hotbar transfer.
- **Shift + click an item in the crafting grid**: moves it back to the inventory (normal behavior).
- **Shift + click a chest slot**: moves between chest and player inventory (normal behavior).
- The client's local prediction may show a different temporary arrangement; the full-window resync after the click corrects it to the server state.

Recipe matching is a subset of vanilla. The client shows only recipes it knows; the server returns `null` for unsupported recipes, so its result slot is cleared. Extend the recipe tables in `CraftingTrait.php` when adding more items.

### 4.6 Chests: TileEntity rendering and double chests

Two separate bugs were fixed:

1. **Chests invisible after rejoin.** The 1.8 chunk packet (`0x21`) only carries block bytes. The client's `Chunk.fillChunk` writes block states directly and does not create tile entities, so a chest block alone renders nothing. The server never sent `0x35 Update Tile Entity`, so after rejoining, chests were invisible even though the block and contents were still saved. Placing a new chest made it visible because the client's own block prediction creates a `TileEntityChest` locally.
   - `Server::sendChunkTo()` now scans the chunk with `Chunk::blockPositions(54)` and sends one `0x35` packet per chest **immediately after** the chunk data.
   - `Server::changeBlock()` sends `0x35` for a newly placed chest, and re-sends it for adjacent chests so the client recalculates the double-chest model.
   - Packet format: `long` packed BlockPos, `byte action = 0`, NBT compound `{id:"Chest", x, y, z}`.
   - **Do not send an air Block Change at a chest position to “force” a rebuild.** That removes the chest block from the client; the follow-up Block Change may be treated as unchanged or arrive after the render rebuild, leaving a permanent hole. Likewise, `action` is not 4 for chests: 4 is the skull branch (`alo`), and chests have no special action. Keep `action = 0` and send the TE immediately.
   - As a safety net, the server re-sends the whole **chunk section** containing each chest at +2 s and +5 s after the chunk (`groundUp = false`, no air, no block changes). This forces `RenderChunk` to rebuild after the client has fully loaded the chunk; the rebuild creates `TileEntityChest` from the block itself. It covers the race where the immediate `0x35` is processed before the client marks the chunk as loaded and is discarded.
2. **Double chest was two separate 27-slot inventories.** `ChestTrait` used a hardcoded 27-slot window, so the left half looked empty and the right half kept its items.
   - `findChestPartner()` checks the 4 horizontal neighbors and refuses a neighbor that is already paired with another chest.
   - `openChest()` merges both 27-slot arrays into 54 slots and sends `slotCount = 54`; `Window Items` has 90 slots.
   - Slot order follows vanilla: if the partner is west or north, it occupies slots 0-26; otherwise the opened chest does.
   - `saveChestWindow()` splits the 54 slots back into two 27-slot arrays and saves both positions. It is used on close, on disconnect, and when a chest is broken.
   - Breaking one half drops only that half's items; the other half is saved and remains usable as a single chest.
   - Drag slot ranges and shift-click ranges are now based on `$c->chest['size']` instead of 63/27 constants.

### 4.7 Ghost blocks and unopenable upper chests

Symptom reported after the double-chest fix: chests on the upper row could not be opened, and right-clicking them produced placement packets instead of `Open Window`. The server-side blocks were correct (single chests at `-2,67,676` and `2,67,676`), but the client was clicking **ghost blocks**: after a rejected placement the 1.8 client does not undo its local prediction, so an invisible (or visible) chest block stays in the client's world while the server has air.

Two fixes:

- `Server::changeBlock()` now sends a `Block Change` with the **real server block** whenever a placement is refused because the target is occupied (and when `setBlock` fails). This removes the ghost immediately and also logs `放 方块... 被拒`.
- The `0x08 Use Item` handler now handles a click on air (`hitId === 0`) that is next to a real chest: it sends air for the ghost position to clear it, then opens the adjacent real chest (`chestNextTo()`, preferring the face the client clicked). This makes clicking the ghost half of a chest open the real chest instead of trying to place another one.

Vanilla limitation worth remembering: 1.8's `BlockChest.canPlaceBlockAt` refuses to place a chest that would touch **two** chests, or whose neighbour already touches another chest. That is why a single chest cannot be inserted into the one-block gap between two double chests (for example `0,66,676`). This is client-side vanilla behaviour, not a server bug. Remove one side first if a single chest is needed there.

### 4.8 Earlier fixes still relevant

- Block drop entities: `Spawn Object` uses `data = 1` so the client reads the velocity and draws a parabola; server also sends periodic `Entity Teleport` to align item positions.
- Anvil nibble order: even indices are in the low nibble; fixed in `Chunk.php`. Legacy generated chunks with wrong order are migrated once based on `LastUpdate`, then marked `phpMcGenerated`.
- Terrain: 早期版本是自写的 value/Perlin 高度图 + 气候群系；现在已改为原版 1.8 的 3D 密度场（见第 10 节），群系换成原版群系层。旧的高度图代码（`terrainHeightAt()/biomeAt()/fbm()/perlin2()`）已不再被调用，属于死代码，保留未删。
- Newly generated chunks are marked dirty and saved; old chunks are not regenerated. New biomes appear only in chunks generated after this change.

## 5. Known issues / limitations

1. Existing inventory may already contain many duplicated oak-plank stacks from older bugs. Full-window resync prevents **new** duplication but does not delete existing stacks. The player should throw away/clear them, or the world inventory JSON can be edited manually while the server is stopped.
2. No furnace smelting UI or recipes; furnace block is placeable but not usable yet.
3. Armor slots are sent but equip logic is not implemented.
4. No full vanilla recipe set, no brewing/enchanting/anvil.
5. Terrain 已按原版密度场重写，但仍是“近似”：没有村庄/要塞/矿道/地牢/群系混合平滑，1.8 的 `ChunkProviderGenerate` 也还有地表石块噪声等细节没移植。见第 10 节。
6. No entity/mob AI; only item entities and other players.
7. Chunks are uncompressed; network use is high; zlib support is a future optimization.
8. No online-mode encryption; offline mode only.
9. `Set Slot`/`Window Items` are chatty because of the full resync; acceptable for a small server, but worth optimizing later.

## 6. Protocol gotchas for future work

- `Spawn Object` velocity is only present when `data != 0` in 1.8; use `data = 1` for item entities and include 3 velocity shorts.
- Open Window: player 0, chest 1 (27 or 54 slots), workbench 2. Workbench `slotCount` must be 0; Window Items still sends 46.
- The 1.8 chunk packet does **not** create tile entities. A chest needs `0x35 Update Tile Entity` (`long` BlockPos, `byte action = 0`, NBT `{id:"Chest", x, y, z}`) or it will not render. The client's handler creates the TE from the block and then applies the NBT.
- If the server refuses a placement, it **must** send a `Block Change` with the real server state. The client already predicted the block locally and will keep a ghost block otherwise.
- Vanilla chest adjacency: `BlockChest.canPlaceBlockAt` refuses a chest that touches two chests or a chest that already touches another chest. The client enforces this before sending the packet; do not expect a place packet for those positions.
- Click Window's `clickedItem` field is the **client's new cursor item**, not the item to put in the slot. The server must keep its own cursor (`Connection::$cursor`) and recompute slot results.
- To synchronize the cursor, `Set Slot` must use `windowId = -1`, `slot = -1`. Using the current window id with slot -1 crashes the client with `IndexOutOfBoundsException` (`List.get(-1)`).
- Every real click should be followed by a full window resync (or carefully matched Set Slots) to avoid client/server divergence.
- Nibble arrays: even index -> low nibble, odd -> high.
- `Window Items` packet length can be a multi-byte VarInt; use the existing `Writer`/`Reader` helpers, do not assume a single byte.
- Mode 5 drag phases and mouse buttons are packed into one byte as described above.

## 7. Verification and testing

Already performed:

- `php -l` on all modified PHP files.
- Offline reflection tests for:
  - 2x2 log -> planks, 4 planks -> crafting table;
  - workbench 4 planks -> crafting table, wooden pickaxe, stone pickaxe;
  - window 2 / slotCount 0 Open Window packet;
  - mode 5 drag distribution in workbench and 2x2;
  - full inventory `canGive` and retained output;
  - drop spawn packet data=1 + velocity, teleport packet;
  - two adjacent chests -> `size = 54`, left half at slots 0-26, right half at 27-53;
  - Open Window `slotCount = 54` and Window Items has 90 slots;
  - closing a double chest writes two correct 27-slot arrays back to `ChestStore`;
  - breaking one half keeps the other half's items;
  - `0x35` payload has the packed BlockPos, action 0, and NBT `id = "Chest"`;
  - `sendCursor()` emits `Set Slot` with `windowId = -1`, `slot = -1`;
  - a chunk send emits `0x21` first, then one `0x35` per chest, and never an air `Block Change` at a chest position;
  - clicking air next to a chest sends an air block correction and opens the real chest;
  - placing into an occupied position sends the real block state back (ghost cleanup).
- Generated 100 chunks and ran `python tools/check_save.py <world>` successfully.
- Biome/height samples over a wide area.

Recommended next tests (WorkBuddy):

1. Start server with `start.bat`, join with a 1.8.9 client.
2. Right-click crafting table: confirm the GUI is the real 3x3 workbench, not a chest.
3. Drag 4 planks into a 2x2 area of the 3x3; click output; confirm one crafting table is produced and the grid is consumed.
4. Shift-click output with 59 logs -> confirm 236 planks are produced in one action and the grid empties; repeat with a nearly full inventory and confirm only the number that fits is crafted and the rest of the output stays in the result slot.
5. Shift-click an inventory log/plank -> confirm it quick-fills the 3x3 or 2x2 crafting grid; shift-click the grid item -> confirm it returns to the inventory.
6. Drag in chest/player inventory; confirm counts match after closing and reopening.
7. Place two chests side by side, put items in both, close, quit and rejoin: both chests must render (no need to re-place them) and both halves must appear in one 54-slot window.
8. Stack chests vertically and open the upper one: it must open normally. If the client still shows a ghost half, clicking it must now open the real chest and clear the ghost (check the log for `幽灵方块 ... -> 打开真箱子`).
9. Try to place a single chest in the gap between two double chests (for example `0,66,676`): vanilla refuses this client-side. Remove one side first; this is expected behaviour.
10. Break one half of a double chest: only that half's items should drop; the remaining half must keep its own items and still open as a 27-slot chest.
11. Break blocks; confirm drops show a parabola, land, can be picked up, and stay on the ground if the inventory is full.
12. Check `logs/server.log` for `工作台点击`, `拖动`, `合成...`, `背包满`, `打开箱子 ... （双箱子`, `幽灵方块`, `被拒`.

## 8. Suggested next steps (priority)

1. Clean up the existing duplicated inventory; add a `/clear` or admin inventory sanitizer command if needed.
2. Furnace UI + smelting recipes.
3. Armor equip/unequip and armor slot logic.
4. Expand crafting recipes to the full 1.8 set (or load a recipe data file).
5. Entity/mob AI and more item entities.
6. Replace approximate terrain with a closer vanilla 1.8 noise/biome implementation if exact terrain is required.
7. zlib chunk compression and bandwidth optimization.

## 9. Notes for the next agent

- The server is single-process and blocking; avoid long loops in packet handlers.
- All server state is in memory; `AnvilWorld` saves dirty chunks every 2 seconds.
- The `world/` directory is the live save; back it up before large changes.
- The user starts the server themselves via `start.bat`; do not leave a server process running.
- The decompiled 1.8.9 client/server sources are in `.cache/mcsrv/out` and `.cache/mcsrv/client-out`; use them for protocol/container behavior.
- The MCP/Searge mapping files are in `.cache/mcsrv/mappings`; `joined.tsrg` there is a 404 placeholder. A real `joined.srg` can be obtained from the MCP 1.8.9 archive if needed.

## 10. 2026-10-28 地形生成大修：进服崩溃 + 大平顶

这一节是本次会话的完整记录：现象、排查思路、根因、改动、试过又回滚的方案、验证结果、遗留风险。下次接手先看这节。

### 10.1 现象一：一进服务器就 disconnect（服务端 Fatal）

日志：

```
PHP Fatal error: Uncaught TypeError: ord(): Argument #1 ($character)
must be of type string, null given in .../src/World/TerrainGenerator.php:190
```

调用链：`TerrainGenerator.php:190` ← `CaveGenerator.php:181` ← `CaveGenerator.php:139` ← `:86` ← `:55`。

### 10.2 崩溃根因

当时 `generate()` 只建了 **8 个 section**（y=0..127）：

```php
$sections = [];
for ($sy = 0; $sy < 8; $sy++) { ... }
```

而洞穴闭包里的 y 上界写的是 255：

```php
$getBlock = function (int $wx, int $wy, int $wz) use (&$sections, ...): int {
    ...
    return ord($sections[$wy >> 4]['blocks'][...]);
};
```

`CaveGenerator` 在扫描 17×17 邻居区块的洞穴时会探测到 y≥128（它按 0..255 的整列世界高度算），于是 `$wy >> 4 >= 8`，`$sections[8]` 不存在 → 数组下标未定义 → `ord(null)` → 致命错误 → 客户端掉线。

**结论：不是洞穴算法本身错，是“生成的段数和探测的高度范围不一致”。**

第一版修法是把 `$getBlock/$setBlock` 的上界收窄到 127 并加 `isset()` 兜底。这确实止住了崩溃，但把问题变成了下面的“大平顶”。

### 10.3 现象二：大平顶 / 刀切断崖 / 密集竖柱

用户截图（种子 `-2127652111`）里出现：

- 大片平坦到不自然的顶面，顶部是一刀切的石头；
- 侧面是密集竖条纹（每列高度差异极大）；
- 悬空高台、千层饼。

### 10.4 大平顶的两个根因

**根因 A：整列填充把 3D 密度当成了高度图。**

旧流程是：

1. `DensityField::noiseField()` 算出 5×33×5 的密度网格（这一步是对的，移植自原版 `func_147423_a`）；
2. `DensityField::columnHeights()` 把网格插值成 16×16 的“每列高度”，取法是**这一列最高的正密度格**；
3. `TerrainGenerator` 从 y=1 一路填到该高度。

原版不是这样：原版把密度网格**三线性插值到每一个方块**，`密度 > 0` 就是石头，`< 0` 就是空气。一整列中间完全可能出现“石头—空气—石头”（悬垂、洞顶、上层孤岛）。用“最高正密度格”当高度再整列填满，等于把所有悬垂和空气层全部填实，形状就变成方方正正的大块。

**根因 B（决定性）：只生成 8 个 section，y>127 的地形被静默丢弃。**

`$set()` 里 `if ($y < 0 || $y > 127) return;`，而密度场在 1.8 的世界高度下经常会给出 128~184 的地表。这些列的高处全被丢掉，剩下的正好停在 y=127 —— 于是每个高地都变成一个**在 y=127 处的绝对水平平顶**。用户截图里的“大平顶”主要就是这个。

实测用户种子 `-2127652111`、7×7 区块：

- 有 **7.0%** 的列地表高度 > 127；
- 最高 184。

也就是说旧代码把 7% 的地形直接削平了。

### 10.5 最终改动（`src/World/TerrainGenerator.php`）

1. **段数 8 → 16**（y=0..255），和原版 1.8 一致：

```php
for ($sy = 0; $sy < 16; $sy++) {
    $sections[$sy] = [
        'blocks'     => str_repeat("\x00", 4096),
        'data'       => str_repeat("\x00", 2048),
        'blockLight' => str_repeat("\x00", 2048),
        'skyLight'   => str_repeat("\x00", 2048),
    ];
}
```

`$set / $get / $setSky / $setBlock` 的 y 上界统一改为 255；洞穴 `$getBlock` 恢复 0..255，并保留 `isset($sections[$sy]['blocks'][$i])` 兜底（即使将来再改段数也不会 Fatal）。

2. **基础地形改成原版式 3D 逐格填充**，不再用 `columnHeights()`：

```php
$field = $this->density->noiseField($cx, $cz, $this->layers->generationBiomes($cx, $cz));

// 每列先做 33 层水平双线性插值（y 方向本来就是线性插值，逐格只剩一次 lerp）
for each ($x, $z):
    $fx = $x / 4.0; $fz = $z / 4.0;
    $x0 = (int) floor($fx); $z0 = (int) floor($fz);
    $tx = $fx - $x0;        $tz = $fz - $z0;
    for ($y = 0; $y < 33; $y++):
        $i00 = ($x0 * 5 + $z0) * 33 + $y;          // 与 NoiseGeneratorImproved
        $i10 = (($x0+1) * 5 + $z0) * 33 + $y;      // 的写入顺序一致：
        $i01 = ($x0 * 5 + $z0+1) * 33 + $y;        // 索引 = (x*5+z)*33+y
        $i11 = (($x0+1) * 5 + $z0+1) * 33 + $y;
        $a0 = lerp(field[i00], field[i10], tx);
        $a1 = lerp(field[i01], field[i11], tx);
        $vals[$y] = lerp($a0, $a1, tz);
    $profiles[$z*16+$x] = $vals;
```

填充时：

```php
// 最高正密度层只作为“上界”，避免空转
for ($y = 32; $y >= 0; $y--) if ($vals[$y] > 0.0) { $h = $y*8 + 8; break; }
if ($h > 255) $h = 255;

// 逐格判断密度：d = lerp(vals[y>>3], vals[(y>>3)+1], (y&7)/8)
for ($y = 1; $y <= $h; $y++) {
    if ($d > 0.0) { $solid[$y] = true; $set($x, $y, $z, 1); }
}

// 真正的地表 = 这一列最高的实心格（可能远低于 $h，因为中间有空气）
$topSolid = 最高的 isset($solid[$y]) 的 y;
```

3. **表层替换只动实心格**。旧代码直接 `$set()`，会把本该是空气的格子填成泥土/沙子（悬垂下方、洞顶上方全被补实）。现在统一走：

```php
$put = function (int $y, int $id, int $meta = 0) use (&$solid, $set, $x, $z): void {
    if (isset($solid[$y])) $set($x, $y, $z, $id, $meta);
};
```

水只填 `!isset($solid[$y])` 的格子，所以水下洞穴、崖壁不会被水灌满。

4. **所有上限同步到 255 / 16**：

| 位置 | 改动 |
|---|---|
| 雪层 | `if ($snow && $h < 255)` |
| 树 | `if ($h + $th + 2 > 255) continue;` |
| HeightMap 扫描 | `for ($y = 255; $y >= 0; $y--)` |
| 天空光 | `for ($y = $heights[...]; $y < 256; $y++) $setSky(..., 15);` |
| 输出 section | `for ($sy = 0; $sy < 16; $sy++)` |

5. **`DensityField.php` 回滚为原状**：`noiseField()` 保持原版公式，`columnHeights()` 保留但 `generate()` 已不再调用（死代码，未删，避免影响其它地方引用）。

### 10.6 试过但回滚的方案（别再来一遍）

1. **`columnHeights()` 改成“从下往上第一个 正→负 界面”**
   - 想法：第一个界面通常就是真正的地表，可以避开上层悬垂。
   - 结果：chunk(0,0) 出现 17 格的人造断崖，比原来更糟。原因是一列里“第一个界面”可能是洞穴顶而不是地表。
2. **混合算法：`top >= 16` 时回退到“最高正格”**
   - 结果：低处仍然有孤岛，且高处的平顶照旧。既没有统一逻辑，也没有解决问题。
3. **把 `$set` 上限从 127 放到 255 但保留整列填充**
   - 结果：大平顶消失，但变成 128~184 的“实心巨柱”，悬垂全没，形状更假。

**最终结论：唯一正确的做法是按原版逐格判断密度，高度图那条路走不通。** 因此所有基于 `columnHeights()` 的修补都被丢弃。

### 10.7 验证结果

- `php -l src/World/TerrainGenerator.php` 等修改文件全部通过。
- 种子 `-2127652111` 连续生成 7×7 = 49 个区块，无任何 PHP 错误/警告：
  - 约 **191 ms/区块**，总计 9.36 s；
  - chunk(0,0) 输出包含 sections 0..8（section 8 的存在证明 >127 的地形不再被丢弃）；
  - 高度分布：7.0% 的列 > 127，最高 184，与密度场一致。
- 临时测试脚本已全部清理（`%TEMP%\mc*.php`）。
- `Chunk::payload()` 本身支持 `0..15` 段（`if ($sy < 0 || $sy > 15) continue;`），16 段可以直接发送；`AnvilWorld` 里没有 8/127 的硬编码，存盘/重载不需要改。

### 10.8 遗留风险 / 未验证项

1. **用户尚未用新世界实测**。必须**新建世界**才看得到效果 —— 旧存档的区块已经写盘，不会重新生成。
2. 陡壁和竖条纹有一部分来自密度场本身的水平梯度（chunk(0,0) 相邻列高差可达 35 格）。原版同一位置也会有陡崖，但本实现没有 1.8 的“地表石块噪声”和群系混合平滑，观感仍会比原版锐利。要彻底对齐需逐块与原版同种子比对。
3. 尚未在真实客户端验证：16 段区块的 `0x21` 发送、光照、以及重新登录后从 `.mca` 读回。
4. 性能：191 ms/区块偏慢。瓶颈在密度场采样（`NoiseGeneratorOctaves` 的 5×33×5×多倍频），不在逐格 lerp。若要优化，可先做 `NoiseGeneratorImproved` 的逐块缓存或整块生成。
5. 已确认但本轮未动的问题（另开任务）：
   - `src/Server.php` L3052-3055 的 `47 << 4` 书架 hack；
   - `src/MobTrait.php` 发送 `S0F` 空 DataWatcher 导致客户端 NPE；
   - 箱子重进不渲染（应改用同状态 `0x23` 强制重建，而不是发空气 Block Change）。

End of handoff.

## 11. 2026-10-29 箱子终于能看见了（根因 + 修法）

用户反馈：「放置出来还是书架」。之前的记录把原因归到「1.8 客户端 TE 渲染问题」，
其实不是 —— **是服务端自己在三个地方把箱子当书架处理**。

### 11.1 三个错处（按发现顺序）

1. **放置时写的是书架**（真正的主因）
   `src/Server.php` 的放置分支：
   ```php
   } elseif ($blockId === 54) {
       $this->changeBlock($tx, $ty, $tz, 47, 0);   // 47 = 书架
       $this->chests->ensure($tx, $ty, $tz);
       $blockId = 47;
   }
   ```
   世界里存的本来就是书架，客户端当然画书架；右键能开箱是因为服务端按坐标查 ChestStore。

2. **重进时补发的方块状态也是书架**
   `sendChunkTo()` 里的「补发」逻辑发 `47 << 4`。已改成发世界里的真实
   blockState（54 + 朝向 meta）。

3. **`migrateChests()` 是死代码**
   注释说「读区块时把 54 换成 47」，但它从来没被调用。留着无害，别再当它是原因。

### 11.2 正确做法

```php
$di = ((int) floor($c->yaw * 4.0 / 360.0 + 0.5)) & 3;   // 原版 BlockChest 算法
$chestMeta = [2, 5, 3, 4][$di];                          // 北/东/南/西
$this->changeBlock($tx, $ty, $tz, 54, $chestMeta);
$this->chests->ensure($tx, $ty, $tz);
$this->sendTileEntityToAll($tx, $ty, $tz);                // 0x35
```

### 11.3 顺带把 TE 存进区块 NBT

按 ChatGPT 那份《箱子 TE 数据讨论》的核心结论改的：
**方块状态和 Tile Entity 必须一起保存**。

原来箱子方块在 `.mca` 的 Blocks 里、物品在 `cache/chests-*.json` 里，两份独立。
区块一旦被重写，方块没了但 JSON 还在 → 「箱子消失、重放一个旧物品又回来」。

现在：
- `AnvilWorld::injectChestTiles()` 写区块前生成 `Level.TileEntities`
  （id/x/y/z/Items，原版格式）
- `importChestTiles()` 读区块后把 TE 物品导入 ChestStore（已有则跳过）
- `markChunkDirty()` 箱子内容变了标脏（ChestTrait 4 处 save 都补了）

验证：换全新的空 JSON，只靠 `.mca` 能恢复「方块 54 + 槽0 石头x64 + 槽3 钻石x7」。

### 11.4 地形：密度场用错了群系层（同日修复）

`generationBiomes()` 原来是 `voronoi->getInts($cx*4-2, ...)` —— **坐标按 1:4 算、
层却是 1:1 的 voronoi**，等于在完全不同的区域取群系。原版是：

| 层 | 用途 | 比例 |
|---|---|---|
| `genBiomes` = riverMix | 地形密度加权 | 1 格 = 4 方块 |
| `biomeIndexLayer` = voronoi | 客户端显示 / 存盘 Biomes | 1 格 = 1 方块 |

改对之后海里的浮空陆地、森林里的石壁全消失。

### 11.5 还没解决的

- 和 `world_real`（真 1.8 存档，seed 5822312952726424627）比高度：
  平均差 4.9 格（chunk 0,0）/ 6.9 格（chunk -1,0）。密度场和填充循环都验证过
  和 Java 逐值一致，所以差在**喂进去的群系数组**或某个还没移植的细节。
- 偶发「1 格悬空土柱」：比如 chunk(-8,-9) 的 (0,0) 列，
  0-58 实心 → 58-164 全空 → 164-169 悬空一块。Java 用同样的输入也算出 169，
  说明不是填充逻辑错，而是输入（群系/噪声参数）导致密度在那一列有正密度口袋。

### 11.6 可复用的验证工具

| 工具 | 用途 |
|---|---|
| `.cache/jtest/Field.java` | 用原版噪声类 + 原版填充循环算 16×16 高度（基准） |
| `.cache/jtest/Field2.java` | 同上，可指定区块 `java Field2 -8 -9` |
| `.cache/jtest/mccls/` | 编译好的原版噪声类 |
| `.cache/pmmp/` | PocketMine-MP 源码（对照 tile 生命周期用） |
