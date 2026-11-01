# Minecraft Java 1.8.9 -> PHP recipe export toolkit

This toolkit separates a one-time vanilla data export from the live PHP server:

- `RecipeDump189.java` runs inside a deobfuscated MCP 1.8.9 source workspace and exports the actual runtime registries.
- `Recipe189.php` loads the generated JSON and matches static shaped/shapeless crafting recipes (2x2 and 3x3) plus furnace smelting recipes.
- The production server remains PHP-only. Java is only needed once to produce the version-pinned JSON data file.

## What is exported

1. Static shaped crafting recipes: exact ingredient item ID, registry name, metadata (including `32767` wildcard), output, dimensions, horizontal mirroring, and container-item remainder metadata.
2. Static shapeless crafting recipes: ingredients, output, metadata wildcards, and container-item remainder metadata.
3. Furnace recipes: input, output, XP per smelt, and wildcard metadata.
4. Dynamic crafting recipe class names: these are listed separately and are NOT falsely flattened into static patterns.

2x2 inventory crafting and 3x3 crafting-table crafting use the same vanilla recipe registry. A 2x2 grid simply cannot match recipes wider or taller than two cells.

## What is not a normal static recipe

Minecraft 1.8.9 also registers dynamic recipe handlers, including (depending on registry entry):

- armor dyeing;
- book copying;
- map cloning and map scaling;
- fireworks;
- damaged-tool repair.

These depend on input NBT, map state, damage values, colors, or custom validation. The exporter records their class names and order, but the PHP server must implement their behavior as dedicated handlers. Brewing-stand potion mixing is a separate system (`PotionHelper` / brewing-stand logic), and anvil/enchantment behavior is not part of `CraftingManager` static crafting recipes.

## Generate `recipes-1.8.9.json`

1. Use a deobfuscated MCP 1.8.9 workspace, such as the MCP 1.8.9 source repository linked below.
2. Put `RecipeDump189.java` under the workspace's `src/minecraft/` source root (default package is intentional).
3. Refresh/rebuild the workspace so it compiles against the project's Minecraft and library classes.
4. Run `RecipeDump189` as a Java application from that workspace.
5. The program writes `recipes-1.8.9.json` in the process working directory and prints static/dynamic/smelting counts.
6. Copy the JSON into the PHP server's data directory. Do not put the exporter class or Java runtime into the live server.

The exporter uses reflection only for two private vanilla recipe fields (`recipeItems`, `recipeWidth`, `recipeHeight`, and `copyIngredientNBT`) that are not exposed by the 1.8.9 API. It reads the live vanilla registries rather than retyping recipe tables.

## PHP usage

```php
require_once __DIR__ . '/Recipe189.php';

$recipes = new Recipe189(__DIR__ . '/data/recipes-1.8.9.json');

// Row-major 2x2 inventory grid: 4 entries; 3x3 workbench grid: 9 entries.
$grid = [
    ['id' => 5, 'meta' => 0, 'count' => 1],
    ['id' => 5, 'meta' => 0, 'count' => 1],
    null,
    null,
];

$result = $recipes->findCrafting($grid, 2, 2);
if ($result !== null) {
    $output = $result['output'];
    $consumedSlots = $result['consumed_slots'];
    $containerRemainders = $result['remaining'];
    // Commit consumption/remainders only when the player takes the result.
}

$smelt = $recipes->findSmelting(['id' => 15, 'meta' => 0, 'count' => 1]);
if ($smelt !== null) {
    $output = $smelt['output'];
    $experience = $smelt['experience'];
}

print_r($recipes->getCounts());
print_r($recipes->getDynamicRecipes());
```

The PHP matcher returns a recipe preview; it does not mutate inventory. Your existing transaction/crafting code should atomically consume one item from each `consumed_slots` entry, place any `remaining` container items back into inventory, and then give the output when the result slot is taken.

## Important implementation notes

- Recipe ingredient `meta = 32767` means any metadata value for that item; it is not a real item damage value.
- Ingredient stack count is not used by vanilla shaped/shapeless matching; each occupied crafting slot contributes one ingredient item.
- Shaped recipes allow horizontal mirroring and can be offset within the 2x2/3x3 grid.
- Shapeless recipes ignore positions but require exactly the same number of occupied slots as recipe ingredients.
- Keep item ID and metadata separate. Do not turn `id` and `meta` into a block state value (`(id << 4) | meta`) for item recipe matching.
- Use the numeric legacy item ID for your current PHP inventory implementation; preserve the registry `name` as a debugging/stability aid.
- Do not assume dynamic recipes are covered by the static matcher.

## Sources

- MCP 1.8.9 decompiled source: https://github.com/W1tcherGaming/MCP-for-1.8.9
- `CraftingManager`: https://mc-packet-reference.netlify.app/1.8.9/source/net/minecraft/item/crafting/craftingmanager/
- `ShapedRecipes`: https://mc-packet-reference.netlify.app/1.8.9/source/net/minecraft/item/crafting/shapedrecipes/
- `ShapelessRecipes`: https://mc-packet-reference.netlify.app/1.8.9/source/net/minecraft/item/crafting/shapelessrecipes/
- `FurnaceRecipes`: https://mc-packet-reference.netlify.app/1.8.9/source/net/minecraft/item/crafting/furnacerecipes/

The generated file is pinned to Minecraft 1.8.9 / protocol 47. Do not mix it with recipe data from 1.12+ JSON data packs or later game versions.
