<?php
header('Content-Type: text/plain; charset=utf-8');
echo "Estrutura a partir de: " . __DIR__ . "\n\n";

function listar($dir, $nivel = 0) {
    $itens = @scandir($dir);
    if (!$itens) return;
    foreach ($itens as $f) {
        if ($f === '.' || $f === '..') continue;
        $path = $dir . '/' . $f;
        echo str_repeat('  ', $nivel) . $f . (is_dir($path) ? '/' : '') . "\n";
        if (is_dir($path)) listar($path, $nivel + 1);
    }
}
listar(__DIR__);
