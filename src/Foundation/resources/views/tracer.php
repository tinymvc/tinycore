<?php

$view->layout('master');
$view->title = "{$type}: $message";
?>

<div class="tracer">
    <div class="error-box">
        <h1><?= e($type) ?>: <?= e($message) ?></h1>
        <p><span class="file"><?= e($file) ?></span> at line <span class="line"><?= e($line) ?></span></p>
    </div>
    <?php if (isset($trace) && !empty($trace)): ?>
        <div class="trace-box">
            <h2>Stack Trace</h2>
            <pre><?php foreach ($trace as $index => $frame) {
                $file = $frame['file'] ?? '[internal function]';
                $line = $frame['line'] ?? '';
                $function = $frame['function'] ?? '';
                $class = $frame['class'] ?? '';
                $type = $frame['type'] ?? '';
                $args = isset($frame['args']) ? implode(
                    ', ',
                    array_map(
                        fn($arg) => is_object($arg) ? get_class($arg) : gettype($arg),
                        $frame['args']
                    )
                ) : '';
                echo "#{$index} {$file}({$line}): {$class}{$type}{$function}({$args})\n";
            } ?></pre>
        </div>
    <?php endif ?>
</div>