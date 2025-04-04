<?php
$view->layout('master');
$view->title = __e('Server Error');
?>

<div id="error-page">
    <p id="error-code"><?= $code ?? 500 ?></p>
    <span id="error-separator"></span>
    <p><?= $message ?? __e('Internal Server Error') ?></p>
</div>