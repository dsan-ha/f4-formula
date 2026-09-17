<?php if(!defined('SITE_ROOT')) exit();

/**
 * @deprecated Bootstrap/DI container are owned by App\\Base\\Kernel.
 * Kept so legacy DataLoader::loadOrdered() does not start a second container.
 */
\App\Base\Kernel::instance();
