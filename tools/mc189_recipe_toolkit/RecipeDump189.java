import java.io.FileOutputStream;
import java.io.OutputStreamWriter;
import java.io.Writer;
import java.lang.reflect.Field;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import net.minecraft.init.Bootstrap;
import net.minecraft.item.Item;
import net.minecraft.item.ItemStack;
import net.minecraft.item.crafting.CraftingManager;
import net.minecraft.item.crafting.FurnaceRecipes;
import net.minecraft.item.crafting.IRecipe;
import net.minecraft.item.crafting.ShapedRecipes;
import net.minecraft.item.crafting.ShapelessRecipes;

/**
 * One-time exporter for the vanilla Minecraft 1.8.9 MCP workspace.
 * This is NOT part of the PHP server and does not need to run in production.
 *
 * Output: recipes-1.8.9.json in the current working directory.
 */
public final class RecipeDump189 {
    private RecipeDump189() {}

    public static void main(String[] args) throws Exception {
        // Initialize vanilla item/block registries before reading recipe entries.
        Bootstrap.register();

        CraftingManager craftingManager = CraftingManager.getInstance();
        FurnaceRecipes furnaceRecipes = FurnaceRecipes.instance();

        List<Object> crafting = new ArrayList<Object>();
        List<Object> dynamic = new ArrayList<Object>();
        List<IRecipe> allRecipes = craftingManager.getRecipeList();

        int index = 0;
        for (IRecipe recipe : allRecipes) {
            String className = recipe.getClass().getSimpleName();

            if (recipe instanceof ShapedRecipes) {
                ShapedRecipes shaped = (ShapedRecipes) recipe;
                int width = ((Integer) getField(recipe, "recipeWidth")).intValue();
                int height = ((Integer) getField(recipe, "recipeHeight")).intValue();
                ItemStack[] items = (ItemStack[]) getField(recipe, "recipeItems");

                Map<String, Object> row = baseRecipe(index, "shaped", className, recipe.getRecipeOutput());
                row.put("width", Integer.valueOf(width));
                row.put("height", Integer.valueOf(height));
                row.put("mirror_horizontal", Boolean.TRUE);
                row.put("copy_ingredient_nbt", getBooleanField(recipe, "copyIngredientNBT"));

                List<Object> ingredients = new ArrayList<Object>();
                for (ItemStack stack : items) {
                    ingredients.add(stackToMap(stack, true));
                }
                row.put("ingredients", ingredients);
                crafting.add(row);
            } else if (recipe instanceof ShapelessRecipes) {
                @SuppressWarnings("unchecked")
                List<ItemStack> items = (List<ItemStack>) getField(recipe, "recipeItems");
                Map<String, Object> row = baseRecipe(index, "shapeless", className, recipe.getRecipeOutput());
                List<Object> ingredients = new ArrayList<Object>();
                for (ItemStack stack : items) {
                    ingredients.add(stackToMap(stack, true));
                }
                row.put("ingredients", ingredients);
                crafting.add(row);
            } else {
                Map<String, Object> row = new LinkedHashMap<String, Object>();
                row.put("index", Integer.valueOf(index));
                row.put("class", className);
                row.put("recipe_size", Integer.valueOf(recipe.getRecipeSize()));
                row.put("output", stackToMap(recipe.getRecipeOutput(), false));
                row.put("requires_custom_handler", Boolean.TRUE);
                dynamic.add(row);
            }
            index++;
        }

        List<Object> smelting = new ArrayList<Object>();
        for (Map.Entry<ItemStack, ItemStack> entry : furnaceRecipes.getSmeltingList().entrySet()) {
            ItemStack input = entry.getKey();
            ItemStack output = entry.getValue();
            Map<String, Object> row = new LinkedHashMap<String, Object>();
            row.put("input", stackToMap(input, false));
            row.put("output", stackToMap(output, false));
            row.put("experience", Float.valueOf(furnaceRecipes.getSmeltingExperience(output)));
            smelting.add(row);
        }

        Map<String, Object> root = new LinkedHashMap<String, Object>();
        root.put("format", "phpmc-recipe-dump");
        root.put("format_version", Integer.valueOf(1));
        root.put("minecraft_version", "1.8.9");
        root.put("protocol", Integer.valueOf(47));
        root.put("source", "Vanilla MCP 1.8.9 CraftingManager#getRecipeList and FurnaceRecipes#getSmeltingList");
        root.put("crafting_recipe_count_total", Integer.valueOf(allRecipes.size()));
        root.put("crafting_static_count", Integer.valueOf(crafting.size()));
        root.put("crafting_dynamic_count", Integer.valueOf(dynamic.size()));
        root.put("smelting_count", Integer.valueOf(smelting.size()));
        root.put("crafting", crafting);
        root.put("dynamic_crafting", dynamic);
        root.put("smelting", smelting);

        String json = toJson(root);
        Writer writer = new OutputStreamWriter(new FileOutputStream("recipes-1.8.9.json"), StandardCharsets.UTF_8);
        try {
            writer.write(json);
            writer.write("\n");
        } finally {
            writer.close();
        }

        System.out.println("Exported recipes-1.8.9.json");
        System.out.println("Crafting total: " + allRecipes.size());
        System.out.println("Static crafting: " + crafting.size());
        System.out.println("Dynamic crafting: " + dynamic.size());
        System.out.println("Smelting: " + smelting.size());
        System.out.println("Dynamic handlers required:");
        for (Object item : dynamic) {
            @SuppressWarnings("unchecked") Map<String, Object> row = (Map<String, Object>) item;
            System.out.println(" - " + row.get("class"));
        }
    }

    private static Map<String, Object> baseRecipe(int index, String type, String className, ItemStack output) {
        Map<String, Object> row = new LinkedHashMap<String, Object>();
        row.put("index", Integer.valueOf(index));
        row.put("type", type);
        row.put("class", className);
        row.put("output", stackToMap(output, false));
        return row;
    }

    private static Object stackToMap(ItemStack stack, boolean includeContainer) {
        if (stack == null) return null;

        Map<String, Object> result = new LinkedHashMap<String, Object>();
        Item item = stack.getItem();
        Object registryName = Item.itemRegistry.getNameForObject(item);
        result.put("id", Integer.valueOf(Item.getIdFromItem(item)));
        result.put("name", registryName == null ? null : registryName.toString());
        result.put("meta", Integer.valueOf(stack.getMetadata()));
        result.put("count", Integer.valueOf(stack.stackSize));

        if (includeContainer) {
            if (item != null && item.hasContainerItem()) {
                Item container = item.getContainerItem();
                Object containerName = Item.itemRegistry.getNameForObject(container);
                Map<String, Object> remainder = new LinkedHashMap<String, Object>();
                remainder.put("id", Integer.valueOf(Item.getIdFromItem(container)));
                remainder.put("name", containerName == null ? null : containerName.toString());
                remainder.put("meta", Integer.valueOf(0));
                remainder.put("count", Integer.valueOf(1));
                result.put("container", remainder);
            } else {
                result.put("container", null);
            }
        }
        return result;
    }

    private static Object getField(Object target, String name) throws Exception {
        Field field = target.getClass().getDeclaredField(name);
        field.setAccessible(true);
        return field.get(target);
    }

    private static boolean getBooleanField(Object target, String name) {
        try {
            Object value = getField(target, name);
            return value instanceof Boolean && ((Boolean) value).booleanValue();
        } catch (Exception ignored) {
            return false;
        }
    }

    @SuppressWarnings("unchecked")
    private static String toJson(Object value) {
        if (value == null) return "null";
        if (value instanceof String) return quote((String) value);
        if (value instanceof Number || value instanceof Boolean) return String.valueOf(value);
        if (value instanceof Map) {
            StringBuilder out = new StringBuilder("{");
            boolean first = true;
            for (Map.Entry<Object, Object> entry : ((Map<Object, Object>) value).entrySet()) {
                if (!first) out.append(',');
                first = false;
                out.append(quote(String.valueOf(entry.getKey()))).append(':').append(toJson(entry.getValue()));
            }
            return out.append('}').toString();
        }
        if (value instanceof Iterable) {
            StringBuilder out = new StringBuilder("[");
            boolean first = true;
            for (Object item : (Iterable<?>) value) {
                if (!first) out.append(',');
                first = false;
                out.append(toJson(item));
            }
            return out.append(']').toString();
        }
        return quote(String.valueOf(value));
    }

    private static String quote(String value) {
        StringBuilder out = new StringBuilder("\"");
        for (int i = 0; i < value.length(); i++) {
            char c = value.charAt(i);
            switch (c) {
                case '"': out.append("\\\""); break;
                case '\\': out.append("\\\\"); break;
                case '\b': out.append("\\b"); break;
                case '\f': out.append("\\f"); break;
                case '\n': out.append("\\n"); break;
                case '\r': out.append("\\r"); break;
                case '\t': out.append("\\t"); break;
                default:
                    if (c < 0x20) {
                        String hex = Integer.toHexString(c);
                        out.append("\\u");
                        for (int p = hex.length(); p < 4; p++) out.append('0');
                        out.append(hex);
                    } else {
                        out.append(c);
                    }
            }
        }
        return out.append('"').toString();
    }
}
