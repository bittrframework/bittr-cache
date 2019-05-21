<?php

declare(strict_types=1);

use Bittr\Cache;

require 'vendor/autoload.php';

$file = (new Cache('mick.c'))->exec(false)->ask(function (string $file)
{
    $patterns = ['/\{\{(.*?)\}\}/', '/\{%/', '/%\}/'];
    $replacements = ['<?= $1 ?>', '<?php', '?>'];

    return preg_replace($patterns, $replacements, file_get_contents($file));
});


var_dump($file);

