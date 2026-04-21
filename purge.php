<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo 'OPcache reset OK';
} elseif (function_exists('opcache_invalidate')) {
    opcache_invalidate(__DIR__ . '/index.php', true);
    echo 'OPcache invalidate OK';
} else {
    echo 'OPcache functions not available';
}
echo ' — <a href="index.php">retour</a>';
