<?php
// This file is part of the MotionBase plugin for Moodle.

/**
 * Version details.
 *
 * @package    filter_motionbase
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'filter_motionbase';
$plugin->version = 2026102900;
$plugin->requires = 2024100700; // Moodle 4.5.
$plugin->supported = [405, 502];
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '1.0.0';
$plugin->dependencies = [
    'mod_assign' => ANY_VERSION,
    'mod_book' => ANY_VERSION,
    'mod_lti' => ANY_VERSION,
    'mod_page' => ANY_VERSION,
];
