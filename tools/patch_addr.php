<?php
declare(strict_types=1);

$path = __DIR__ . '/../bin/server.php';
$t = file_get_contents($path);

$old = "\$wssKey = \$props['wss-key'] ?? (__DIR__ . '/../certs/server.key');";
$new = $old . "\n// 公网地址：进服广播里用。没配就用本机内网 IP。\n\$publicHost = \$props['public-address'] ?? '';";

if (substr_count($t, $old) !== 1) {
    fwrite(STDERR, "锚点 1 命中 " . substr_count($t, $old) . " 次\n");
    exit(1);
}
$t = str_replace($old, $new, $t);

$old2 = '$viewDistance ?? 3, $wssPort ?? 0, $wssCert, $wssKey);';
$new2 = '$viewDistance ?? 3, $wssPort ?? 0, $wssCert, $wssKey, $publicHost ?? \'\');';

if (substr_count($t, $old2) !== 1) {
    fwrite(STDERR, "锚点 2 命中 " . substr_count($t, $old2) . " 次\n");
    exit(1);
}
$t = str_replace($old2, $new2, $t);

file_put_contents($path, $t);
echo "bin/server.php 已接线\n";
