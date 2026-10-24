<?php
declare(strict_types=1);

namespace PhpMc\Skin;

/**
 * 皮肤解析：按名字去 Mojang 换一份带签名的 textures 属性。
 *
 * 1.8 的客户端只认「玩家档案上的 textures 属性」，不会自己按名字去猜。
 * 离线服不发这段，所有人都是默认 Steve —— 这就是「后面进来的人看不到皮肤」的原因。
 *
 * 两条硬约束：
 *  1. 只查白名单（skins.conf.json）里的名字。离线号不能按名字瞎查 ——
 *     "Nave" 这种离线名很可能撞上某个陌生人的正版号，把人家的皮肤贴你头上更糟。
 *  2. value/signature 必须原样转发。签名是 Mojang 私钥签的，客户端拿内置公钥验，
 *     自己编一段假的只会让客户端拒绝渲染。
 */
final class Skins
{
    /** @var array<string, array{value:string,signature:string}|null> */
    private array $mem = [];
    /** @var array<string, array{value:string,signature:string,at:int}> */
    private array $disk = [];

    /**
     * @param array<string, string> $allow 游戏内名字（小写）=> 要查的正版名
     */
    public function __construct(
        private string $cacheFile,
        private array $allow = [],
    ) {
        if (is_file($cacheFile)) {
            $json = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($json)) {
                $this->disk = $json;
            }
        }
    }

    public function allowed(string $name): bool
    {
        return isset($this->allow[strtolower($name)]);
    }

    /** @return array{value:string,signature:string}|null */
    public function of(string $name): ?array
    {
        $key = strtolower($name);
        $target = $this->allow[$key] ?? null;
        if (!is_string($target) || $target === '') {
            return null; // 不在名单里：默认皮肤，不去猜
        }

        if (array_key_exists($key, $this->mem)) {
            return $this->mem[$key];
        }

        $cached = $this->disk[$key] ?? null;
        if (is_array($cached) && isset($cached['value']) && (time() - ($cached['at'] ?? 0)) < 7 * 86400) {
            return $this->mem[$key] = [
                'value' => $cached['value'],
                'signature' => $cached['signature'] ?? '',
            ];
        }

        $textures = $this->fetch($target);
        if ($textures !== null) {
            $this->disk[$key] = $textures + ['at' => time()];
            $this->save();
        }

        return $this->mem[$key] = $textures;
    }

    /** @return array{value:string,signature:string}|null */
    private function fetch(string $name): ?array
    {
        $profile = $this->get('https://api.mojang.com/users/profiles/minecraft/' . rawurlencode($name));
        if ($profile === null || empty($profile['id'])) {
            return null;
        }

        $session = $this->get(
            'https://sessionserver.mojang.com/session/minecraft/profile/'
            . $profile['id'] . '?unsigned=false'
        );
        if ($session === null) {
            return null;
        }

        foreach ($session['properties'] ?? [] as $prop) {
            if (($prop['name'] ?? '') !== 'textures') {
                continue;
            }
            $value = (string) ($prop['value'] ?? '');
            if ($value === '') {
                return null;
            }

            return ['value' => $value, 'signature' => (string) ($prop['signature'] ?? '')];
        }

        return null;
    }

    private function get(string $url): ?array
    {
        // 先按正常证书校验走；公司代理/自签 CA 环境下会失败，再退一步不校验证书。
        // 皮肤这段的安全边界其实是 Mojang 的签名（客户端会验），不是 TLS，
        // 所以退让不会让谁伪造成别人的皮肤。
        $body = $this->raw($url, true) ?? $this->raw($url, false);

        if ($body === null || $body === '') {
            return null;
        }

        $json = json_decode($body, true);

        return is_array($json) ? $json : null;
    }

    private function raw(string $url, bool $verifyPeer): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_USERAGENT => 'PhpMc/0.1 (+minecraft 1.8.9)',
        ];
        if (!$verifyPeer) {
            $opt[CURLOPT_SSL_VERIFYPEER] = false;
            $opt[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $opt);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $body === '' || ($status !== 0 && $status >= 400)) {
            return null;
        }

        return $body;
    }

    private function save(): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents(
            $this->cacheFile,
            json_encode($this->disk, JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
