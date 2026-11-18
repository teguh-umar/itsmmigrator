<?php

namespace GlpiPlugin\Itsmmigrator;

/**
 * Pure plan for which profile rights to write on install/upgrade (hub §4).
 * Install re-runs on every upgrade, so:
 * - ordinary profiles receive nothing: a migrator right is never derived from another right;
 * - the super-admin (the profile holding UPDATE on core `config`, found by right, never by name
 *   or id) receives the full mask on EVERY run.
 * If no profile holds that right nobody gets anything.
 */
final class RightSeeder
{
    /**
     * @param string[]                                          $existing_rights right names already registered (unused: kept for the suite signature)
     * @param array<int, array{interface: string, config: int}> $profiles        profile id => core masks
     * @return array<int, array<string, int>> profile id => right name => mask to write
     */
    public static function seed(array $existing_rights, array $profiles): array
    {
        $out = [];
        foreach ($profiles as $id => $profile) {
            if (($profile['config'] & UPDATE) === UPDATE) {
                $out[$id] = RightDefinition::ALL;
            }
        }
        return $out;
    }
}
