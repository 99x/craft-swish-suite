<?php
/**
 * Minimal Craft config for the plugin's integration test suite.
 * Points at a throwaway database — never used for real content.
 */

return [
    'allowAdminChanges' => true,
    'disallowRobots' => true,
    'omitScriptNameInUrls' => true,
    'securityKey' => 'test-only-security-key-not-for-production-use',
];
