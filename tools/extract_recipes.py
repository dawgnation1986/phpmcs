#!/usr/bin/env python3
# 从反编译的 1.8.9 客户端里提取合成配方，生成 PHP 表。
#
# 依据：
#   afi.java  方块字段 = a("名字")        -> 字段 -> 名字
#   afh.java  a(id, "名字", ...)          -> 名字 -> id
#   bjh.java  this.a(zy.X, "名字") 按顺序  -> 物品字段 -> id（从 256 起）
#   abt.java  this.a(new zx(产出), "图案"...)/this.b(产出, 材料...)  -> 配方
import re, sys, os

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__))) + "/.cache/mc189"


def read(name):
    with open(f"{BASE}/{name}", encoding="utf-8", errors="ignore") as f:
        return f.read()


# 1) 方块 字段 -> 名字 -> id
block_field_name = {}
for m in re.finditer(r"(\w+) = a\(\"([^\"]+)\"\)", read("afi.java")):
    block_field_name[m.group(1)] = m.group(2)

name_id = {}
for m in re.finditer(r"a\((\d+), \"([^\"]+)\"", read("afh.java")):
    name_id[m.group(2)] = int(m.group(1))

block_field_id = {}
for fld, nm in block_field_name.items():
    if nm in name_id:
        block_field_id[f"afi.{fld}"] = name_id[nm]

# 2) 物品 字段 -> id（注册顺序从 256 起）
item_field_id = {}
next_id = 256
for line in read("bjh.java").splitlines():
    # 只有「本名」注册才占用一个新 id：
    #   this.a(zy.X, "name")        两根参数
    #   this.a(zy.X, 0, "name")     显式 0
    # 形如 this.a(zy.f, 1, "bow_pulling_0") 是同一物品的别名，不再占号。
    m2 = re.search(r"this\.a\((zy\.\w+), \"[^\"]+\"\)", line)
    if m2:
        if m2.group(1) not in item_field_id:
            item_field_id[m2.group(1)] = next_id
            next_id += 1
        continue
    m3 = re.search(r"this\.a\((zy\.\w+), 0, \"", line)
    if m3:
        if m3.group(1) not in item_field_id:
            item_field_id[m3.group(1)] = next_id
            next_id += 1

# 3) 木板变体：aio.a.<字母>().a() 表示木板 meta
PLANK_LETTER = {"a": 0, "b": 1, "c": 2, "d": 3, "e": 4, "f": 5}

# 引用 -> (id, meta) ；解析不了返回 None
def resolve_ref(txt):
    txt = txt.strip()
    m = re.fullmatch(r"(afi|zy)\.(\w+)", txt)
    if m:
        key = f"{m.group(1)}.{m.group(2)}"
        if key in block_field_id:
            return (block_field_id[key], 0)
        if key in item_field_id:
            return (item_field_id[key], 0)
        return None
    m = re.fullmatch(r"new zx\((afi\.\w+|zy\.\w+), 1, (.*)\)", txt)
    if m:
        base = resolve_ref(m.group(1))
        if base is None:
            return None
        meta_txt = m.group(2)
        lm = re.search(r"aio\.a\.([a-f])", meta_txt)
        if lm:
            return (base[0], PLANK_LETTER[lm.group(1)])
        if re.fullmatch(r"\d+", meta_txt):
            return (base[0], int(meta_txt))
        return None
    if re.fullmatch(r"\d+", txt):
        return None
    return None


# 产出 -> [id, count, meta]
def resolve_out(txt):
    txt = txt.strip()
    m = re.fullmatch(r"new zx\((afi\.\w+|zy\.\w+), (\d+)(?:, (.*))?\)", txt)
    if not m:
        return None
    base = resolve_ref(m.group(1))
    if base is None:
        return None
    cnt = int(m.group(2))
    meta = base[1]
    if m.group(3):
        mt = m.group(3).strip()
        lm = re.search(r"aio\.a\.([a-f])", mt)
        if lm:
            meta = PLANK_LETTER[lm.group(1)]
        elif re.fullmatch(r"\d+", mt):
            meta = int(mt)
        else:
            meta = 0   # 其它枚举（羊毛颜色等）先归 0
    return [base[0], cnt, meta]



def split_args(body):
    """按顶层逗号切分，跳过字符串和括号内部。"""
    out, depth, cur, quote = [], 0, "", None
    for ch in body:
        if quote:
            cur += ch
            if ch == quote:
                quote = None
            continue
        if ch in "\"'":
            quote = ch
            cur += ch
            continue
        if ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
        if ch == "," and depth == 0:
            out.append(cur.strip())
            cur = ""
            continue
        cur += ch
    if cur.strip():
        out.append(cur.strip())
    return out

src = read("abt.java")
shaped = []
shapeless = []
skipped = 0
dbg = []

for line in src.splitlines():
    line = line.strip()
    if not line.startswith("this.a(") and not line.startswith("this.b("):
        continue
    # 取括号内内容
    if line.startswith("this.a("):
        body = line[len("this.a("):-2]
        parts = split_args(body)
        out = resolve_out(parts[0])
        if out is None:
            skipped += 1
            continue
        pats = []
        i = 1
        while i < len(parts) and re.fullmatch(r'"[^"]*"', parts[i]):
            pats.append(parts[i][1:-1])
            i += 1
        keymap = {}
        ok = True
        while i + 1 < len(parts):
            kc = parts[i]
            if not re.fullmatch(r"'.'", kc):
                ok = False
                break
            ref = resolve_ref(parts[i + 1])
            if ref is None:
                ok = False
                break
            keymap[kc[1]] = [ref[0], ref[1]]
            i += 2
        if not ok or not pats or not keymap:
            skipped += 1
            continue
        shaped.append({"out": out, "pat": pats, "keys": keymap})
    else:
        body = line[len("this.b("):-2]
        parts = split_args(body)
        out = resolve_out(parts[0])
        if out is None:
            skipped += 1
            continue
        ins = []
        ok = True
        for p in parts[1:]:
            r = resolve_ref(p)
            if r is None:
                ok = False
                break
            ins.append([r[0], r[1]])
        if not ok or not ins:
            skipped += 1
            continue
        shapeless.append({"out": out, "in": ins})

print("未解析样例:", dbg[:3])
print(f"有形状 {len(shaped)} 条，无形状 {len(shapeless)} 条，跳过 {skipped} 条")

# 4) 生成 PHP
def php_arr(a):
    return "[" + ", ".join(str(x) for x in a) + "]"

lines = ["<?php", "declare(strict_types=1);", "", "namespace PhpMc;", "",
         "// 由 tools/extract_recipes.py 从 1.8.9 客户端自动生成，不要手改。",
         "// out = [id, count, meta]；pat = 图案字符串；keys = 字符 -> [id, meta]",
         "final class RecipeTable", "{",
         "    public const SHAPED = ["]
for r in shaped:
    keys = ", ".join(f"'{k}' => {php_arr(v)}" for k, v in r["keys"].items())
    pats = ", ".join(f"'{p}'" for p in r["pat"])
    lines.append(f"        [{php_arr(r['out'])}, [{pats}], [{keys}]],")
lines.append("    ];")
lines.append("")
lines.append("    public const SHAPELESS = [")
for r in shapeless:
    ins = ", ".join(php_arr(v) for v in r["in"])
    lines.append(f"        [{php_arr(r['out'])}, [{ins}]],")
lines.append("    ];")
lines.append("}")

out_path = os.path.dirname(os.path.dirname(os.path.abspath(__file__))) + "/src/RecipeTable.php"
with open(out_path, "w", encoding="utf-8", newline="\n") as f:
    f.write("\n".join(lines) + "\n")
print("已写入", out_path)
