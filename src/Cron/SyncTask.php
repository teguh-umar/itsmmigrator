<?php

namespace GlpiPlugin\Itsmmigrator\Cron;

/**
 * The SdpSync automatic action (weekly, external mode). The engine arrives in M2-M5; until then the
 * task is registered but switched off and does nothing.
 */
final class SyncTask
{
    public const TASK = 'SdpSync';

    /** @return array<string,string> */
    public static function cronInfo(string $name): array
    {
        return $name === self::TASK
            ? ['description' => __('Weekly sync of every active ITSM source', 'itsmmigrator')]
            : [];
    }

    /** @return int 0: nothing was done (no engine in this build) */
    public static function cronSdpSync(\CronTask $task): int
    {
        $task->log('itsmmigrator: the sync engine is not built yet (planned for M5); nothing to do');
        return 0;
    }
}
