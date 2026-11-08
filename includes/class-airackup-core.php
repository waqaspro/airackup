<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Airackup_Core
 * Main plugin singleton coordinator.
 */
class Airackup_Core {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
    }

    private function init() {
        Airackup_Security::init_vault_directory();
    }
}
