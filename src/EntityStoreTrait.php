<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 实体持久化：展示框、盔甲架、画、船。
 *
 * 这些实体以前只活在内存里，服务端一重启就没了。现在落到
 * cache/entities-<存档名>.json，启动时读回来；eid 重新分配，
 * 之后靠「每 2 秒重发」把它们送到客户端（那条逻辑已经在跑）。
 *
 * 注意：这不是原版做法（原版存进区块的 Entities 标签），
 * 但改动小、不碰区块读写，坏处是实体不跟着区块走。
 */
trait EntityStoreTrait
{
    private bool $entitiesLoaded = false;

    private function entityFile(): string
    {
        $name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $this->world->levelName());

        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . 'entities-' . $name . '.json';
    }

    /** 把当前内存里的实体写盘（展示框/盔甲架/画/船） */
    private function saveEntities(): void
    {
        $frames = [];
        foreach ($this->spawnedEntities as $ent) {
            $frames[] = [
                'type' => (int) $ent['type'],
                'x' => (float) $ent['x'],
                'y' => (float) $ent['y'],
                'z' => (float) $ent['z'],
                'data' => (int) ($ent['data'] ?? 0),
            ];
        }

        $armor = [];
        foreach ($this->armorStands as $pos) {
            $armor[] = ['x' => $pos[0], 'y' => $pos[1], 'z' => $pos[2]];
        }

        $paint = [];
        foreach ($this->paintings as $p) {
            $paint[] = [
                'x' => $p[0], 'y' => $p[1], 'z' => $p[2],
                'title' => $p[3], 'dir' => $p[4], 'yaw' => $p[5],
            ];
        }

        $data = ['frames' => $frames, 'armor' => $armor, 'paintings' => $paint];
        $dir = dirname($this->entityFile());
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($this->entityFile(), json_encode($data), LOCK_EX);
    }

    /** 启动时读回来，重新分配 eid（位置和朝向都还原） */
    private function loadEntities(): void
    {
        if ($this->entitiesLoaded) {
            return;
        }
        $this->entitiesLoaded = true;
        $f = $this->entityFile();
        if (!is_file($f)) {
            return;
        }
        $json = json_decode((string) @file_get_contents($f), true);
        if (!is_array($json)) {
            return;
        }

        $n = 0;
        foreach ($json['frames'] ?? [] as $fr) {
            $eid = $this->nextEntityId++;
            $this->spawnedEntities[$eid] = [
                'type' => (int) $fr['type'],
                'x' => (float) $fr['x'],
                'y' => (float) $fr['y'],
                'z' => (float) $fr['z'],
                'data' => (int) ($fr['data'] ?? 0),
            ];
            $n++;
        }
        foreach ($json['armor'] ?? [] as $ar) {
            $eid = $this->nextEntityId++;
            $this->armorStands[$eid] = [(int) $ar['x'], (int) $ar['y'], (int) $ar['z']];
            $n++;
        }
        foreach ($json['paintings'] ?? [] as $p) {
            $this->paintings[] = [
                (int) $p['x'], (int) $p['y'], (int) $p['z'],
                (string) $p['title'], (int) $p['dir'], (float) $p['yaw'],
            ];
            $n++;
        }
        if ($n > 0) {
            $this->log("  实体还原 {$n} 个（展示框/盔甲架/画）");
        }
    }
}
