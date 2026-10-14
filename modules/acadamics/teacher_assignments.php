<?php
/**
 * Teacher assignments - thin wrapper around the shared, scope-aware module engine.
 *
 * Resource definition: config/resources.php => 'teacher_assignments'
 */
declare(strict_types=1);

$RESOURCE_KEY = 'teacher_assignments';
require __DIR__ . '/../_module.php';
