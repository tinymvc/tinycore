<?php
$view->layout('master');
$view->title = __e('Page Not found');
?>

<div id="error-page">
    <p id="error-code">404</p>
    <span id="error-separator"></span>
    <p><?= $message ?? __e('Not found') ?></p>
</div>