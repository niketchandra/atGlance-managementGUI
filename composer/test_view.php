<?php

$app = require __DIR__ . '/bootstrap/app.php';
$app->boot();

try {
    $view = view('app');
    echo "✓ View loaded successfully\n";
    echo "View name: " . $view->getName() . "\n";
    echo "View file: " . $view->getPath() . "\n";
    echo "\nFirst 100 chars of rendered output:\n";
    $output = $view->render();
    echo substr($output, 0, 100) . "...\n";
} catch (Exception $e) {
    echo "✗ Error loading view: " . $e->getMessage() . "\n";
}
