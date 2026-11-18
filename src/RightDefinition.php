<?php

namespace GlpiPlugin\Itsmmigrator;

/**
 * Single source of truth for the plugin's profile rights.
 *
 * | right                      | mask          | gates                                            |
 * |----------------------------|---------------|--------------------------------------------------|
 * | plugin_itsmmigrator_config | READ | UPDATE | source list and forms, global settings          |
 * | plugin_itsmmigrator_run    | READ | UPDATE | run page, run history; UPDATE starts a run       |
 */
final class RightDefinition
{
    public const CONFIG = 'plugin_itsmmigrator_config';
    public const RUN    = 'plugin_itsmmigrator_run';

    /** right name => the full mask a super-admin receives */
    public const ALL = [
        self::CONFIG => READ | UPDATE,
        self::RUN    => READ | UPDATE,
    ];

    /** @return array{config: string, run: string} */
    public static function names(): array
    {
        return ['config' => self::CONFIG, 'run' => self::RUN];
    }

    /** @return array<int, string> column labels for Profile::displayRightsChoiceMatrix */
    public static function rightsMatrix(string $name): array
    {
        return match ($name) {
            self::CONFIG, self::RUN => [READ => __('Read'), UPDATE => __('Update')],
            default                 => [],
        };
    }
}
