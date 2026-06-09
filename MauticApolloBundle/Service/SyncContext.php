<?php

namespace MauticPlugin\MauticApolloBundle\Service;

class SyncContext
{
    private bool $apolloImportRunning = false;

    public function beginApolloImport(): void
    {
        $this->apolloImportRunning = true;
    }

    public function endApolloImport(): void
    {
        $this->apolloImportRunning = false;
    }

    public function isApolloImportRunning(): bool
    {
        return $this->apolloImportRunning;
    }
}
