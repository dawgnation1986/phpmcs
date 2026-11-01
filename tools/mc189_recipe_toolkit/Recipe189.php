<?php
/**
 * Lightweight runtime reader/matcher for a recipes-1.8.9.json dump.
 * The live server remains PHP-only; this class has no Java dependency.
 *
 * Input stack format: ['id' => int, 'meta' => int, 'count' => int]
 * Grid format: row-major list of stacks or nulls.
 */
final class Recipe189
{
    private array $data;

    public function __construct(string $jsonFile)
    {
        if (!is_file($jsonFile)) {
            throw new RuntimeException('Recipe data file not found: ' . $jsonFile);
        }

        $raw = file_get_contents($jsonFile);
        if ($raw === false) {
            throw new RuntimeException('Unable to read recipe data file: ' . $jsonFile);
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (($data['minecraft_version'] ?? null) !== '1.8.9') {
            throw new RuntimeException('Expected a Minecraft 1.8.9 recipe dump.');
        }
        $this->data = $data;
    }

    public function getCounts(): array
    {
        return [
            'crafting_total' => $this->data['crafting_recipe_count_total'] ?? 0,
            'crafting_static' => count($this->data['crafting'] ?? []),
            'crafting_dynamic' => count($this->data['dynamic_crafting'] ?? []),
            'smelting' => count($this->data['smelting'] ?? []),
        ];
    }

    /**
     * Returns the first static vanilla recipe matching a 2x2 or 3x3 grid.
     * Dynamic recipes (maps, banners, fireworks, repair, etc.) are not matched here.
     */
    public function findCrafting(array $grid, int $gridWidth, int $gridHeight): ?array
    {
        if (!in_array($gridWidth, [2, 3], true) || $gridWidth !== $gridHeight) {
            throw new InvalidArgumentException('Crafting grid must be 2x2 or 3x3.');
        }
        if (count($grid) !== $gridWidth * $gridHeight) {
            throw new InvalidArgumentException('Grid slot count does not match dimensions.');
        }

        foreach (($this->data['crafting'] ?? []) as $recipe) {
            if (($recipe['type'] ?? '') === 'shaped') {
                $match = $this->matchShaped($grid, $gridWidth, $gridHeight, $recipe);
            } elseif (($recipe['type'] ?? '') === 'shapeless') {
                $match = $this->matchShapeless($grid, $recipe);
            } else {
                $match = null;
            }

            if ($match !== null) {
                return [
                    'recipe' => $recipe,
                    'output' => $recipe['output'],
                    'consumed_slots' => $match['consumed_slots'],
                    'remaining' => $match['remaining'],
                ];
            }
        }
        return null;
    }

    public function findSmelting(array $input): ?array
    {
        $wildcard = null;
        foreach (($this->data['smelting'] ?? []) as $recipe) {
            $required = $recipe['input'];
            if ((int)$required['id'] !== (int)$input['id']) {
                continue;
            }
            if ((int)$required['meta'] === (int)($input['meta'] ?? 0)) {
                return $recipe;
            }
            if ((int)$required['meta'] === 32767) {
                $wildcard = $recipe;
            }
        }
        return $wildcard;
    }

    /** Static data only. The caller must implement handlers listed in dynamic_crafting. */
    public function getDynamicRecipes(): array
    {
        return $this->data['dynamic_crafting'] ?? [];
    }

    private function matchShaped(array $grid, int $gw, int $gh, array $recipe): ?array
    {
        $rw = (int)$recipe['width'];
        $rh = (int)$recipe['height'];
        if ($rw < 1 || $rh < 1 || $rw > $gw || $rh > $gh) {
            return null;
        }

        $ingredients = $recipe['ingredients'];
        for ($oy = 0; $oy <= $gh - $rh; $oy++) {
            for ($ox = 0; $ox <= $gw - $rw; $ox++) {
                $flips = !empty($recipe['mirror_horizontal']) ? [false, true] : [false];
                foreach ($flips as $flip) {
                    $consumed = [];
                    $remaining = [];
                    $ok = true;

                    for ($y = 0; $y < $gh && $ok; $y++) {
                        for ($x = 0; $x < $gw; $x++) {
                            $inside = $x >= $ox && $x < $ox + $rw && $y >= $oy && $y < $oy + $rh;
                            $actualIndex = $y * $gw + $x;
                            $actual = $grid[$actualIndex];
                            $required = null;

                            if ($inside) {
                                $rx = $x - $ox;
                                $ry = $y - $oy;
                                if ($flip) {
                                    $rx = $rw - 1 - $rx;
                                }
                                $required = $ingredients[$ry * $rw + $rx] ?? null;
                            }

                            if ($required === null) {
                                if ($actual !== null) {
                                    $ok = false;
                                    break;
                                }
                                continue;
                            }

                            if (!$this->stackMatches($actual, $required)) {
                                $ok = false;
                                break;
                            }

                            $consumed[] = $actualIndex;
                            if (!empty($required['container'])) {
                                $remaining[$actualIndex] = $required['container'];
                            }
                        }
                    }

                    if ($ok) {
                        return ['consumed_slots' => $consumed, 'remaining' => $remaining];
                    }
                }
            }
        }
        return null;
    }

    private function matchShapeless(array $grid, array $recipe): ?array
    {
        $actual = [];
        foreach ($grid as $slot => $stack) {
            if ($stack !== null) {
                $actual[] = ['slot' => $slot, 'stack' => $stack];
            }
        }

        $required = $recipe['ingredients'] ?? [];
        if (count($actual) !== count($required)) {
            return null;
        }

        // Exact metadata requirements are matched before wildcard (32767) requirements.
        usort($required, static function ($a, $b): int {
            $aw = (int)($a['meta'] ?? 0) === 32767;
            $bw = (int)($b['meta'] ?? 0) === 32767;
            return ($aw <=> $bw);
        });

        $used = [];
        $consumed = [];
        $remaining = [];
        foreach ($required as $need) {
            $found = null;
            foreach ($actual as $i => $entry) {
                if (isset($used[$i])) {
                    continue;
                }
                if ($this->stackMatches($entry['stack'], $need)) {
                    $found = $i;
                    break;
                }
            }
            if ($found === null) {
                return null;
            }
            $used[$found] = true;
            $slot = $actual[$found]['slot'];
            $consumed[] = $slot;
            if (!empty($need['container'])) {
                $remaining[$slot] = $need['container'];
            }
        }

        return ['consumed_slots' => $consumed, 'remaining' => $remaining];
    }

    private function stackMatches(?array $actual, ?array $required): bool
    {
        if ($actual === null || $required === null) {
            return false;
        }
        if ((int)($actual['id'] ?? -1) !== (int)($required['id'] ?? -2)) {
            return false;
        }
        $requiredMeta = (int)($required['meta'] ?? 0);
        return $requiredMeta === 32767
            || $requiredMeta === (int)($actual['meta'] ?? 0);
    }
}
