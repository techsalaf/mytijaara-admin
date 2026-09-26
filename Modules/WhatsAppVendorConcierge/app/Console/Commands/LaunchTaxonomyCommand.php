<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Services\LaunchTaxonomyService;

class LaunchTaxonomyCommand extends Command
{
    protected $signature = 'whatsapp:launch-taxonomy {--lookups : Include verified units and attribute names} {--apply : Write additive records after backup} {--module= : Restrict to module ID} {--manifest= : Local JSON output path} {--validate-only : Validate dataset without writing}';

    protected $description = 'Preview or safely import launch categories; existing categories are preserved';

    public function handle(LaunchTaxonomyService $service): int
    {
        $report = $service->run($this->option('apply') && ! $this->option('validate-only'), $this->option('module') ? (int) $this->option('module') : null, (bool) $this->option('lookups'));
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($path = $this->option('manifest')) {
            if (file_put_contents($path, $json) === false) {
                throw new \RuntimeException('Cannot write manifest');
            }
        }
        $this->line($json);

        return empty($report['counts']['conflict']) ? self::SUCCESS : self::FAILURE;
    }
}
