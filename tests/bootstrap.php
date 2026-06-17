<?php
/**
 * PHPUnit bootstrap.
 *
 * Sets the test flag so requiring the bot file defines its classes/functions
 * without executing the webhook handler, then loads it.
 */
putenv('TGWP_TEST=1');

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

require __DIR__ . '/../telegram_wordpress_bot.php';
