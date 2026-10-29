<?php
/**
 * Empty stand-in for wp-admin/includes/file.php.
 *
 * ABSPATH points at tests/ (see bootstrap.php). Code paths that require_once
 * the wp-admin media helpers before doing anything else (e.g. the publish
 * route's inline-image sideload) would otherwise fatal in unit tests. The
 * functions those files define are stubbed per-test via Brain Monkey.
 */
