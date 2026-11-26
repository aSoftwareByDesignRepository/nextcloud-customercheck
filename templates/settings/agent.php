<?php

declare(strict_types=1);

/**
 * Thin bridge: the dispatcher maps 'agent' => this file while the shared
 * agent-auth pack syncs its settings partial into templates/parts/settings/.
 * The partial is generated — never edit it app-side.
 */
include __DIR__ . '/../parts/settings/agent.php';
