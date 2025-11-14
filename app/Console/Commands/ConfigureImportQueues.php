<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConfigureImportQueues extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:configure-queues 
                            {--workers=auto : Number of workers to start (auto, or specific number)}
                            {--queues=all : Which queues to configure (all, small, medium, large)}
                            {--dry-run : Show configuration without starting workers}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configure and start optimized queue workers for employee imports';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Configuring Employee Import Queue Workers');
        $this->newLine();

        // Validate configuration
        if (!$this->validateConfiguration()) {
            return Command::FAILURE;
        }

        $workers = $this->option('workers');
        $queues = $this->option('queues');
        $dryRun = $this->option('dry-run');

        // Show current configuration
        $this->showCurrentConfiguration();

        // Configure workers
        $workerCommands = $this->generateWorkerCommands($workers, $queues);

        if ($dryRun) {
            $this->info('🔍 Dry run mode - showing commands that would be executed:');
            $this->newLine();
            
            foreach ($workerCommands as $command) {
                $this->line("  {$command}");
            }
            
            $this->newLine();
            $this->info('To actually start the workers, run without --dry-run flag');
            return Command::SUCCESS;
        }

        // Start workers
        $this->info('🔧 Starting queue workers...');
        $this->newLine();

        foreach ($workerCommands as $description => $command) {
            $this->line("Starting: {$description}");
            $this->line("Command: {$command}");
            
            if ($this->confirm('Start this worker?', true)) {
                $this->info("✅ Worker configuration ready: {$description}");
                $this->warn("Note: Run the following command in a separate terminal or process manager:");
                $this->line("  {$command}");
                $this->newLine();
            }
        }

        $this->info('✨ Queue worker configuration complete!');
        $this->newLine();
        
        $this->showMonitoringCommands();
        
        return Command::SUCCESS;
    }

    /**
     * Validate the current configuration.
     */
    private function validateConfiguration(): bool
    {
        $this->info('🔍 Validating configuration...');

        // Check if Redis is available (if using Redis queues)
        $queueConnection = config('import.queue.connection');
        if (str_contains($queueConnection, 'redis')) {
            try {
                Cache::store('redis')->put('test', 'value', 1);
                Cache::store('redis')->forget('test');
                $this->info('✅ Redis connection: OK');
            } catch (\Exception $e) {
                $this->error('❌ Redis connection failed: ' . $e->getMessage());
                return false;
            }
        }

        // Check database connection
        try {
            DB::connection()->getPdo();
            $this->info('✅ Database connection: OK');
        } catch (\Exception $e) {
            $this->error('❌ Database connection failed: ' . $e->getMessage());
            return false;
        }

        // Check if required tables exist
        $requiredTables = ['import_jobs', 'import_errors', 'employees', 'jobs'];
        foreach ($requiredTables as $table) {
            if (!DB::getSchemaBuilder()->hasTable($table)) {
                $this->error("❌ Required table '{$table}' not found. Run migrations first.");
                return false;
            }
        }
        $this->info('✅ Required tables: OK');

        $this->info('✅ Configuration validation passed');
        $this->newLine();
        
        return true;
    }

    /**
     * Show current configuration.
     */
    private function showCurrentConfiguration(): void
    {
        $this->info('📋 Current Import Configuration:');
        $this->newLine();

        $config = config('import');
        
        $this->table(['Setting', 'Value'], [
            ['Queue Connection', $config['queue']['connection']],
            ['Small Files Queue', $config['queue']['queues']['small']],
            ['Medium Files Queue', $config['queue']['queues']['medium']],
            ['Large Files Queue', $config['queue']['queues']['large']],
            ['Small Files Workers', $config['queue']['workers']['small_files']],
            ['Medium Files Workers', $config['queue']['workers']['medium_files']],
            ['Large Files Workers', $config['queue']['workers']['large_files']],
            ['Max Attempts', $config['queue']['retries']['max_attempts']],
            ['CSV Chunk Size', $config['processing']['chunk_sizes']['csv']],
            ['Excel Chunk Size', $config['processing']['chunk_sizes']['excel']],
            ['Cache Store', $config['cache']['store']],
        ]);

        $this->newLine();
    }

    /**
     * Generate worker commands based on options.
     */
    private function generateWorkerCommands(string $workers, string $queues): array
    {
        $config = config('import');
        $commands = [];

        $queueConfig = [
            'small' => [
                'queue' => $config['queue']['queues']['small'],
                'workers' => $config['queue']['workers']['small_files'],
                'timeout' => $config['queue']['timeouts']['small'],
            ],
            'medium' => [
                'queue' => $config['queue']['queues']['medium'],
                'workers' => $config['queue']['workers']['medium_files'],
                'timeout' => $config['queue']['timeouts']['medium'],
            ],
            'large' => [
                'queue' => $config['queue']['queues']['large'],
                'workers' => $config['queue']['workers']['large_files'],
                'timeout' => $config['queue']['timeouts']['large'],
            ],
        ];

        $queuesToProcess = $queues === 'all' ? array_keys($queueConfig) : [$queues];

        foreach ($queuesToProcess as $queueType) {
            if (!isset($queueConfig[$queueType])) {
                continue;
            }

            $queueInfo = $queueConfig[$queueType];
            $workerCount = $workers === 'auto' ? $queueInfo['workers'] : (int) $workers;

            for ($i = 1; $i <= $workerCount; $i++) {
                $description = "Worker {$i} for {$queueType} files ({$queueInfo['queue']})";
                $command = sprintf(
                    'php artisan queue:work %s --queue=%s --timeout=%d --tries=%d --sleep=3 --max-jobs=100 --max-time=3600',
                    $config['queue']['connection'],
                    $queueInfo['queue'],
                    $queueInfo['timeout'],
                    $config['queue']['retries']['max_attempts']
                );

                $commands[$description] = $command;
            }
        }

        return $commands;
    }

    /**
     * Show monitoring commands.
     */
    private function showMonitoringCommands(): void
    {
        $this->info('📊 Monitoring Commands:');
        $this->newLine();

        $monitoringCommands = [
            'Check queue status' => 'php artisan queue:monitor',
            'View failed jobs' => 'php artisan queue:failed',
            'Retry failed jobs' => 'php artisan queue:retry all',
            'Clear failed jobs' => 'php artisan queue:flush',
            'Import statistics' => 'php artisan import:stats',
        ];

        foreach ($monitoringCommands as $description => $command) {
            $this->line("  {$description}: {$command}");
        }

        $this->newLine();
        $this->info('💡 Tip: Use a process manager like Supervisor for production deployments');
    }
}
