<?php
$view->layout('master');
$view->title = __e('Forbidden');
?>

<div id="error-page">
    <p id="error-code">403</p>
    <span id="error-separator"></span>
    <p><?= $message ?? __e('Forbidden') ?></p>
</div>