<?php

namespace Elazar\SplBenchmarks;

use React\EventLoop\LoopInterface;
use React\EventLoop\Factory as LoopFactory;
use React\ChildProcess\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class RunBenchmarksCommand extends Command
{
    /**
     * @var \Symfony\Component\Finder\Finder
     */
    protected $finder;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    protected $fs;

    /**
     * @var \React\EventLoop\LoopInterface
     */
    protected $loop;

    /**
     * @var array
     */
    protected $valid_benchmarks;

    protected function configure()
    {
        $this->valid_benchmarks = [
            'dll',
			'fa',
			'mh',
			'os',
			'pq',
			'q',
			's',
        ];
        $benchmarks = implode(',', $this->valid_benchmarks);

        $this
            ->setName('spl-benchmarks')
            ->setDescription('Runs a set of benchmarks for PHP SPL classes and equivalent array-based implementations')
            ->addOption(
                'benchmarks',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Comma-delimited list of benchmark pairs from benchmarks/ to run, defaults to "' . $benchmarks . '"',
                $benchmarks
            )
            ->addOption(
                'cpus',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Number of processors to use when running benchmarks, defaults to 2',
                2
            )
            ->addOption(
                'destination',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Path to which to write the benchmark results, defaults to ./results',
                './results'
            )
            ->addOption(
                'elements',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Comma-delimited list of quantities of elements to use per test, defaults to "10,100,500,1000,5000"',
                '10,100,500,1000,5000'
            )
            ->addOption(
                'executions',
                'x',
                InputOption::VALUE_OPTIONAL,
                'Number of executions per test, defaults to 50',
                50
            )
            ->addOption(
                'php-path',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Path to the php CLI executable'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Define a function for deriving commands to execute
        $php_path = $this->getPhpPath($input);
        $base_path = __DIR__ . '/../benchmarks';
        $get_cmd = function($benchmark, $sub_benchmark, $count)
            use ($base_path, $php_path) {
            $file = $base_path . '/' . $benchmark . '-' . $sub_benchmark . '.php';
            return $php_path
                . ' -d max_execution_time=0'
                . ' -d auto_append_file="' . $base_path . '/memory.php"'
                . ' ' . $file . ' ' . $count;
        };

        // Derive all needed parameter permutations for the commands to execute
        $elements = $this->getElements($input);
        $benchmarks = $this->getBenchmarks($input);
        $executions = $this->getExecutions($input);
        $cmds = [];
        foreach ($elements as $count) {
            foreach ($benchmarks as $benchmark) {
                foreach (['s', 'a'] as $sub_benchmark) {
                    foreach (range(1, $executions) as $execution) {
                        $cmds[] = [$benchmark, $sub_benchmark, $count];
                    }
                }
            }
        }

        // Define a function for creating processes to execute the commands
        $loop = $this->getLoop();
        $processes = $stdout = $stderr = [];
        $process_id = 0;
        $queue_process = function()
            use ($get_cmd, $loop, $output, &$queue_process, &$cmds, &$processes, &$process_id) {
            if (empty($cmds)) {
                return;
            }
            list($benchmark, $sub_benchmark, $count) = array_shift($cmds);
            $output->writeln('<info>Start: ' . $benchmark . '-' . $sub_benchmark . ' ' . $count . '</info>');
            $cmd = $get_cmd($benchmark, $sub_benchmark, $count);
            $key = serialize([$benchmark, $sub_benchmark, $count]);
            $stdout[$key] = $stderr[$key] = '';
            $current_process_id = $process_id;
            $process_id++;
            $processes[$current_process_id] = $process = new Process($cmd);
            $process->on('exit', function($code, $signal)
                use ($queue_process, $output, $benchmark, $sub_benchmark, $count, &$processes, &$current_process_id) {
                $output->writeln('<info>End: ' . $benchmark . '-' . $sub_benchmark . ' ' . $count . '</info>');
                unset($processes[$current_process_id]);
                $queue_process();
            });
            $loop->addTimer(0.001, function($timer) use ($process, $key, &$stdout, &$stderr) {
                $process->start($timer->getLoop());
                $process->stdout->on('data', function($data) use (&$stdout, $key) {
                    $stdout[$key] .= $data;
                });
                $process->stderr->on('data', function($data) use (&$stderr, $key) {
                    $stderr[$key] .= $data;
                });
            });
        };

        // Queue the initial set of processes, one per CPU
        $cpus = $this->getCpus($input);
        while (count($processes) < $cpus) {
            $queue_process();
        }
        $loop->run();

        // Parse and store the results
        $destination = $this->getDestination($input);
        $path = $destination . '/raw.csv';
        $lines = [];
        foreach ($stdout as $key => $stdout_data) {
            $stdout_lines = explode(PHP_EOL, $stdout_data);
            $memory = array_sum($stdout_lines) / $executions;

            $stderr_fields = explode(' ', trim($stderr[$key]));
            $time = $stderr_fields[2] / $executions;
            $eps = 1 / $time;

            list($benchmark, $sub_benchmark, $count) = unserialize($key);
            $file = $benchmark . '-' . $sub_benchmark . '.php';
            $lines[] = '"' . implode('","', [
                    $count,
                    $file,
                    $time,
                    $eps,
                    $memory
                ]) . '"';
        }
        $this->getFilesystem()->dumpFile($path, implode(PHP_EOL, $lines));
    }

    protected function getBenchmarks(InputInterface $input)
    {
        $b = explode(',', $input->getOption('benchmarks'));
        $diff = array_diff($b, $this->valid_benchmarks);
        if (count($diff)) {
            $invalid = implode(',', $diff);
            throw new \DomainException('--benchmarks contains invalid values: ' . $invalid);
        }
        return $b;
    }

    protected function getCpus(InputInterface $input)
    {
        $c = $input->getOption('cpus');
        if (!is_int($c) && !ctype_digit($c)) {
            throw new \DomainException('--cpus must be a positive integer: ' . $c);
        }
        return $c;
    }

    protected function getDestination(InputInterface $input)
    {
        $d = $input->getOption('destination');
        $finder = $this->getFinder();
        $finder->directories()->followLinks()->ignoreUnreadableDirs()->path($d);
        if (!count($finder)) {
            try {
                $this->getFilesystem()->mkdir($d);
            } catch (IOExceptionInterface $e) {
                throw new \RuntimeException('--destination does not exist and cannot be created: ' . $e->getMessage());
            }
        }
        return $d;
    }

    protected function getElements(InputInterface $input)
    {
        $e = explode(',', $input->getOption('elements'));
        $filtered = array_filter($e, 'ctype_digit');
        if ($e !== $filtered) {
            $invalid = implode(', ', array_diff($e, $filtered));
            throw new \RuntimeException('--elements has invalid values: ' . $invalid);
        }
        return $e;
    }

    protected function getExecutions(InputInterface $input)
    {
        $e = $input->getOption('executions');
        if (!is_int($e) && !ctype_digit($e)) {
            throw new \RuntimeException('--executions must be a positive integer: ' . $e);
        }
        return $e;
    }

    protected function getPhpPath(InputInterface $input)
    {
        $p = $input->getOption('php-path');
        if ($p === null) {
            $finder = $this->getFinder();
            $finder->files()->in(\PHP_BINDIR)->name('php');
            if (count($finder)) {
                $p = reset(iterator_to_array($finder));
            }
        }
        if ($p === null) {
            throw new \RuntimeException('--php-path cannot be determined');
        }
        return $p;
    }

    public function setFinder(Finder $finder)
    {
        $this->finder = $finder;
    }

    public function getFinder()
    {
        if (!$this->finder) {
            $this->finder = new Finder;
        }
        return $this->finder;
    }

    public function setFilesystem(Filesystem $fs)
    {
        $this->fs = $fs;
    }

    public function getFilesystem()
    {
        if (!$this->fs) {
            $this->fs = new Filesystem;
        }
        return $this->fs;
    }

    public function setLoop(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function getLoop()
    {
        if (!$this->loop) {
            $this->loop = LoopFactory::create();
        }
        return $this->loop;
    }
}
