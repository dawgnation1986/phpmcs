<?php
declare(strict_types=1);

namespace PhpMc;

use PhpMc\Nbt\Nbt;
use PhpMc\Nbt\Tag;

/**
 * 旗帜（方块 176 站立 / 177 挂墙）。
 *
 * 颜色不在方块里，在 TileEntity 的 Base 字段（0~15）。
 * 客户端 handleUpdateTileEntity 的 action=6 就是 banner 分支，
 * 所以只要发 0x35(action=6) + NBT，客户端就会按颜色渲染。
 *
 * 图案（Patterns）这一版先不做，只存底色。
 */
trait BannerTrait
{
    /** @var array<string,int> "x,y,z" => 颜色 0~15 */
    private array $banners = [];
    private bool $bannersLoaded = false;

    private function bannerFile(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'banners.json';
    }

    private function loadBanners(): void
    {
        if ($this->bannersLoaded) {
            return;
        }
        $this->bannersLoaded = true;
        $f = $this->bannerFile();
        if (is_file($f)) {
            $json = json_decode((string) @file_get_contents($f), true);
            if (is_array($json)) {
                $this->banners = $json;
            }
        }
    }

    private function saveBanners(): void
    {
        $dir = dirname($this->bannerFile());
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($this->bannerFile(), json_encode($this->banners), LOCK_EX);
    }

    private function setBanner(int $x, int $y, int $z, int $color): void
    {
        $this->loadBanners();
        $this->banners[$x . ',' . $y . ',' . $z] = max(0, min(15, $color));
        $this->saveBanners();
    }

    private function removeBanner(int $x, int $y, int $z): void
    {
        $this->loadBanners();
        unset($this->banners[$x . ',' . $y . ',' . $z]);
        $this->saveBanners();
    }

    /** 供 AnvilWorld 写区块用：旗帜的 TE */
    public function bannerTilesForChunk(int $cx, int $cz): array
    {
        $this->loadBanners();
        $out = [];
        foreach ($this->banners as $k => $color) {
            $p = explode(',', (string) $k);
            if (count($p) !== 3) {
                continue;
            }
            [$x, $y, $z] = [(int) $p[0], (int) $p[1], (int) $p[2]];
            if (($x >> 4) !== $cx || ($z >> 4) !== $cz) {
                continue;
            }
            $out[] = \PhpMc\Nbt\Tag::compound([
                'id' => \PhpMc\Nbt\Tag::str('Banner'),
                'x' => \PhpMc\Nbt\Tag::int($x),
                'y' => \PhpMc\Nbt\Tag::int($y),
                'z' => \PhpMc\Nbt\Tag::int($z),
                'Base' => \PhpMc\Nbt\Tag::int((int) $color),
                'Patterns' => \PhpMc\Nbt\Tag::list(\PhpMc\Nbt\Tag::COMPOUND, []),
            ]);
        }

        return $out;
    }

    /** 0x35(action=6) 让客户端建出 TileEntityBanner 并按颜色渲染 */
    private function sendBanner(Connection $c, int $x, int $y, int $z): void
    {
        $this->loadBanners();
        $k = $x . ',' . $y . ',' . $z;
        if (!isset($this->banners[$k])) {
            return;
        }
        $nbt = Nbt::write(Tag::compound([
            'id' => Tag::str('Banner'),
            'x' => Tag::int($x), 'y' => Tag::int($y), 'z' => Tag::int($z),
            'Base' => Tag::int((int) $this->banners[$k]),
            'Patterns' => Tag::list(Tag::COMPOUND, []),
        ]));
        $c->send(self::C_UPDATE_TILE_ENTITY, (new Writer())
            ->raw($this->packPositionBytes($x, $y, $z))
            ->byte(6)
            ->raw($nbt));
    }
}
