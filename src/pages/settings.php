<?php

/**
 * The settings page.
 *
 * @author Jay Trees <github.jay@grandel.anonaddy.me>
 */

namespace wishthis;

$page = new Page(__FILE__, __('Settings'), 100);
$page->header();
$page->bodyStart();
$page->navigation();

?>

<main>
    <div class="ui container">
        <h1 class="ui header"><?= $page->title ?></h1>

        <div class="ui segment">
            <h2 class="ui header"><?= __('Email') ?></h2>
            <p><?= __('Emails (password reset, wish requests) are sent directly from this server.') ?></p>
            <p><?= __('Sender') ?>: <strong><?= \htmlspecialchars(Email::getFrom()) ?></strong></p>
            <p><?= \sprintf(__('To use a different sender, define %s in %s. The domain of the sender should have SPF and DKIM set up, otherwise emails may end up as spam.'), '<code>EMAIL_FROM</code>', '<code>src/config/config.php</code>') ?></p>
        </div>
    </div>
</main>

<?php
$page->bodyEnd();
?>
