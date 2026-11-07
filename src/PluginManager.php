<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 极简插件系统。
 *
 * 一个插件就是一个 PHP 文件，返回一个数组：
 *
 *     return [
 *         'name' => '自定义掉落',
 *         'onBlockBreak' => function (Server $s, Connection $p, int $x, int $y, int $z, int $id) {
 *             if ($id === 87) {
 *                 $s->apiGiveItem($p, 264, 1);
 *                 $s->apiChat($p, '地狱岩额外掉了个钻石');
 *             }
 *         },
 *     ];
 *
 * 设计上有意做得很小：
 *   - 没有热重载（PHP 常驻进程，改插件要重启服务端）
 *   - 没有依赖管理、没有版本约束
 *   - 插件抛异常只记日志，绝不影响主循环
 *
 * 想加新事件就在 Server 里找对应位置调 $this->emit('xxx', ...)。
 */
final class PluginManager
{
    /** @var array<string, array<int, callable>> 事件名 => 处理函数 */
    private array $handlers = [];

    /** @var array<string, string> 插件名 => 文件路径 */
    private array $loaded = [];

    /** 这些键是元信息，不当成事件 */
    private const META_KEYS = ['name', 'author', 'version', 'description'];

    public function loadDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            return;
        }
        sort($files);
        foreach ($files as $file) {
            $this->loadFile($file);
        }
    }

    public function loadFile(string $file): bool
    {
        try {
            /** @psalm-suppress UnresolvableInclude */
            $def = require $file;
        } catch (\Throwable $e) {
            error_log(sprintf('[plugin] 载入失败 %s: %s', basename($file), $e->getMessage()));

            return false;
        }

        if (!is_array($def)) {
            error_log(sprintf('[plugin] %s 没有返回数组，跳过', basename($file)));

            return false;
        }

        $name = (string) ($def['name'] ?? basename($file, '.php'));

        foreach ($def as $key => $fn) {
            if (in_array($key, self::META_KEYS, true) || !str_starts_with((string) $key, 'on')) {
                continue;
            }
            if (!is_callable($fn)) {
                continue;
            }
            $this->handlers[$key][] = $fn;
        }

        $this->loaded[$name] = $file;

        return true;
    }

    /**
     * 广播一个事件。任何处理函数抛异常都只记日志，不影响其他插件。
     */
    public function emit(string $event, mixed ...$args): void
    {
        $list = $this->handlers[$event] ?? [];
        if ($list === []) {
            return;
        }

        foreach ($list as $i => $fn) {
            try {
                $fn(...$args);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[plugin] %s 的第 %d 个处理函数抛异常: %s @ %s:%d',
                    $event,
                    $i + 1,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
            }
        }
    }

    public function count(): int
    {
        return count($this->loaded);
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->loaded);
    }

    /** @return string[] 已注册的事件名 */
    public function events(): array
    {
        return array_keys($this->handlers);
    }
}
