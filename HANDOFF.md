# PHPMCS 1.8.9 Server - Handoff Notes

Date: 2026-10-27
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
| `src/World/TerrainGenerator.php` | Perlin noise + climate-biome terrain generation. |
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

The cursor item is still synchronized separately with `Set Slot` slot -1.

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
- Terrain: value noise replaced by Perlin noise + temperature/humidity biomes (ocean, frozen ocean, beach, plains, forest, desert, taiga, ice plains, swamp, savanna, mountains), rivers, biome-specific trees/cacti/snow/sugar cane.
- Newly generated chunks are marked dirty and saved; old chunks are not regenerated. New biomes appear only in chunks generated after this change.

## 5. Known issues / limitations

1. Existing inventory may already contain many duplicated oak-plank stacks from older bugs. Full-window resync prevents **new** duplication but does not delete existing stacks. The player should throw away/clear them, or the world inventory JSON can be edited manually while the server is stopped.
2. No furnace smelting UI or recipes; furnace block is placeable but not usable yet.
3. Armor slots are sent but equip logic is not implemented.
4. No full vanilla recipe set, no brewing/enchanting/anvil.
5. Terrain is approximate; no caves, ores, villages, strongholds, structures, biome blending.
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

End of handoff.
