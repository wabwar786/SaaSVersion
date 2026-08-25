<?php
namespace Aio;
final class View {
    public static function render(string $view,array $data=[]): void {
        extract($data); $viewFile=dirname(__DIR__).'/views/'.$view.'.php';
        if(!is_file($viewFile)) throw new \RuntimeException('View not found: '.$view);
        include dirname(__DIR__).'/views/layout.php';
    }
}

// build: V17.1 build 2026-08-25
