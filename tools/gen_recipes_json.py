#!/usr/bin/env python3
"""从 1.8.9 反编译源码生成 recipes-1.8.9.json（格式对齐 tools/mc189_recipe_toolkit/README.md）。

不跑 Java：直接解析 .cache/mc189/*.java 的 CraftingManager 注册调用。
输出格式与 RecipeDump189.java 一致，可直接给 Recipe189.php 用。
"""
import json
import os
import re
import sys
from collections import OrderedDict

BASE = os.path.join(os.path.dirname(__file__), '..', '.cache', 'mc189')
OUT = os.path.join(os.path.dirname(__file__), '..', 'data', 'recipes-1.8.9.json')


def read(name):
    with open(os.path.join(BASE, name), encoding='utf-8', errors='ignore') as f:
        return f.read()


def build_maps():
    # 方块字段 -> 名字
    block_field_name = dict(
        (m.group(1), m.group(2))
        for m in re.finditer(r'(\w+) = (?:\([A-Za-z]+\))?a\("([^"]+)"\)', read('afi.java'))
    )
    # 名字 -> 方块 id
    name_block_id = dict(
        (m.group(2), int(m.group(1)))
        for m in re.finditer(r'a\((\d+), "([^"]+)"', read('afh.java'))
    )
    blocks = {f'afi.{k}': name_block_id[v] for k, v in block_field_name.items() if v in name_block_id}

    # 物品：按 bjh.java 注册顺序从 256 编号（别名不占号）
    items = {}
    next_id = 256
    for line in read('bjh.java').splitlines():
        for pat in (r'this\.a\((zy\.\w+), "[^"]+"\)', r'this\.a\((zy\.\w+), 0, "'):
            m = re.search(pat, line)
            if m:
                items.setdefault(m.group(1), next_id)
                next_id += 1
                break
    # 名字兜底（给 JSON 里的 name 字段用）
    item_field_name = dict(
        (m.group(1), m.group(2))
        for m in re.finditer(r'this\.a\((zy\.\w+), "([^"]+)"\)', read('bjh.java'))
    )
    return blocks, items, item_field_name, block_field_name


BLOCKS, ITEMS, ITEM_NAMES, BLOCK_NAMES = build_maps()
NAMES = {}
for k, v in BLOCK_NAMES.items():
    NAMES['afi.' + k] = v
for k, v in ITEM_NAMES.items():
    NAMES['zy.' + k] = v


def ref_id(txt):
    """'afi.f' -> 5 ；返回 (id, name) 或 None"""
    t = txt.strip()
    m = re.fullmatch(r'(afi|zy)\.(\w+)', t)
    if not m:
        return None
    key = f'{m.group(1)}.{m.group(2)}'
    if key in BLOCKS:
        return BLOCKS[key], NAMES.get(key, key)
    if key in ITEMS:
        return ITEMS[key], NAMES.get(key, key)
    return None


PLANKS = {'a': 0, 'b': 1, 'c': 2, 'd': 3, 'e': 4, 'f': 5}


def parse_output(txt):
    """new zx(afi.X, 4) / new zx(zy.X) / new zx(aio.a.b) ..."""
    t = txt.strip()
    m = re.fullmatch(r'new zx\((afi\.\w+|zy\.\w+)(?:,\s*(\d+))?(?:,\s*(.*?))?\)', t)
    if not m:
        return None
    base = ref_id(m.group(1))
    if base is None:
        return None
    count = int(m.group(2)) if m.group(2) else 1
    meta = 0
    if m.group(3):
        g = m.group(3).strip()
        got = _meta_expr(g)
        if got is None:
            return None
        meta = got
    return {'id': base[0], 'count': count, 'meta': meta, 'name': base[1]}


def parse_stack_arg(txt):
    """'afi.f' 或 'new zx(afi.f, 1, aio.a.a.a())' -> {'id','meta','name'}"""
    t = txt.strip()
    if t.startswith('new zx('):
        o = parse_output(t)
        if o is None:
            return None
        return {'id': o['id'], 'meta': o['meta'], 'name': o['name']}
    r = ref_id(t)
    return None if r is None else {'id': r[0], 'meta': 0, 'name': r[1]}


def _meta_expr(g):
    """aio.a.a.a() -> 0 ；akl.a.b.a() -> 1 ；纯数字直接返回"""
    g = g.strip()
    if re.fullmatch(r'\d+', g):
        return int(g)
    m = re.search(r'aio\.a\.([a-f])\.a\(\)', g)
    if m:
        return PLANKS[m.group(1)]
    m = re.search(r'akl\.a\.([a-g])\.a\(\)', g)
    if m:
        return ord(m.group(1)) - ord('a')
    m = re.search(r'4 \+ aio\.a\.([a-f])\.a\(\) - 4', g)
    if m:
        return PLANKS[m.group(1)]
    return None


def split_args(body):
    out, depth, cur, quote = [], 0, '', None
    for ch in body:
        if quote:
            cur += ch
            if ch == quote:
                quote = None
            continue
        if ch in '"\'':
            quote = ch
            cur += ch
            continue
        if ch in '([{':
            depth += 1
        elif ch in ')]}':
            depth -= 1
        if ch == ',' and depth == 0:
            out.append(cur.strip())
            cur = ''
            continue
        cur += ch
    if cur.strip():
        out.append(cur.strip())
    return out


def main():
    crafting = []
    skipped = []
    files = ['abt.java']
    for fn in files:
        for line in read(fn).splitlines():
            s = line.strip()
            if not (s.startswith('this.a(') or s.startswith('this.b(')) or ', "' not in s:
                continue
            is_shaped = s.startswith('this.a(')
            body = s[len('this.a(' if is_shaped else 'this.b('):-2]
            parts = split_args(body)
            out = parse_output(parts[0])
            if out is None:
                skipped.append(('bad output', s[:100]))
                continue

            if is_shaped:
                pats, i = [], 1
                while i < len(parts) and re.fullmatch(r'"[^"]*"', parts[i]):
                    pats.append(parts[i][1:-1])
                    i += 1
                if not pats:
                    skipped.append(('no pattern', s[:100]))
                    continue
                keys, ok = {}, True
                while i + 1 < len(parts):
                    if not re.fullmatch(r"'.'", parts[i]):
                        ok = False
                        break
                    st = parse_stack_arg(parts[i + 1])
                    if st is None:
                        ok = False
                        break
                    keys[parts[i][1]] = st
                    i += 2
                if not ok or not keys:
                    skipped.append(('bad keys', s[:100]))
                    continue

                w = max(len(p) for p in pats)
                h = len(pats)
                grid, bad = [], None
                for row in pats:
                    row = row.ljust(w)
                    for ch in row:
                        if ch == ' ':
                            grid.append(None)
                        elif ch in keys:
                            grid.append(dict(keys[ch]))
                        else:
                            bad = ch
                if bad:
                    skipped.append((f'char {bad!r} not in keys', s[:100]))
                    continue
                crafting.append({
                    'type': 'shaped',
                    'width': w,
                    'height': h,
                    'mirror_horizontal': True,
                    'ingredients': grid,
                    'output': out,
                })
            else:
                ins, ok = [], True
                for p in parts[1:]:
                    st = parse_stack_arg(p)
                    if st is None:
                        ok = False
                        break
                    ins.append(st)
                if not ok or not ins:
                    skipped.append(('bad shapeless', s[:100]))
                    continue
                crafting.append({
                    'type': 'shapeless',
                    'ingredients': ins,
                    'output': out,
                })

    # 熔炉（abx.java：this.a(输入, new zx(输出), 经验)）
    smelting = []
    for line in read('abx.java').splitlines():
        s = line.strip()
        if not s.startswith('this.a('):
            continue
        m = re.match(r'this\.a\((afi\.\w+|zy\.\w+), new zx\((afi\.\w+|zy\.\w+)(?:, (\d+))?\), ([\d.]+)F?\)', s)
        if not m:
            continue
        src = ref_id(m.group(1))
        dst = ref_id(m.group(2))
        if src is None or dst is None:
            continue
        smelting.append({
            'input': {'id': src[0], 'name': src[1], 'meta': 0},
            'output': {'id': dst[0], 'count': int(m.group(3)) if m.group(3) else 1,
                       'meta': 0, 'name': dst[1]},
            'experience': float(m.group(4)),
        })

    data = OrderedDict([
        ('minecraft_version', '1.8.9'),
        ('crafting_recipe_count_total', len(crafting)),
        ('crafting', crafting),
        ('dynamic_crafting', ['RecipeArmorDyes', 'RecipeBookCloning', 'RecipeMapCloning',
                              'RecipeFireworks', 'RecipeRepairItem']),
        ('smelting', smelting),
    ])

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, separators=(',', ':'))

    shaped = sum(1 for r in crafting if r['type'] == 'shaped')
    print(f'有形状 {shaped}  无序 {len(crafting) - shaped}  熔炉 {len(smelting)}')
    print(f'跳过 {len(skipped)}')
    for reason, line in skipped[:8]:
        print('  ', reason, '|', line)
    print('写入', os.path.normpath(OUT))


if __name__ == '__main__':
    main()
