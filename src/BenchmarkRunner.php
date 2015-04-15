<?php

namespace Elazar\SplBenchmarks;

use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BenchmarkRunner
{
    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;

    /**
     * @var \React\EventLoop\LoopInterface
     */
    protected $loop;

    /**
     * @var \Elazar\SplBenchmarks\BenchmarkIterator
     */
    protected $benchmarks;

    /**
     * @var int
     */
    protected $cpus;

    /**
     * @var \React\ChildProcess\Process[]
     */
    protected $processes;

    /**
     * @var string[]
     */
    protected $stdout;

    /**
     * @var float[]
     */
    protected $runtimes;

    /**
     * @var int
     */
    protected $process_id;

    /**
     * @var \Elazar\SplBenchmarks\BenchmarkResult[]
     */
    protected $results;

    public function __construct(OutputInterface $output, LoopInterface $loop, BenchmarkIterator $benchmarks, $cpus)
    {
        $this->output = $output;
        $this->loop = $loop;
        $this->benchmarks = $benchmarks;
        $this->cpus = $cpus;
    }

    public function run()
    {
        $this->processes = $this->stdout = $this->runtimes = $this->results = [];
        $this->process_id = 0;

        while (count($this->processes) < $this->cpus) {
            $this->queueProcess();
        }
        $this->loop->run();

        $this->calculateResults();
    }

    public function getResults()
    {
        return $this->results;
    }

    protected function calculateResults()
    {
        foreach ($this->stdout as $key => $stdout_data) {
            $stdout_lines = array_filter(explode(PHP_EOL, $stdout_data));
            $memory = array_sum($stdout_lines) / $executions;
            $time = array_sum($this->runtimes[$key]) / $executions;
            $eps = 1 / $time;
            list($benchmark, $subbenchmark, $count) = unserialize($key);

            $result = new BenchmarkResult;
            $result->benchmark = $benchmark;
            $result->subbenchmark = $subbenchmark;
            $result->count = $count;
            $result->time = $time;
            $result->eps = $eps;
            $result->memory = $memory;
            $this->results[] = $result;
        }
    }

    protected function queueProcess()
    {
        if (!$this->benchmarks->valid()) {
            return;
        }
        $b = $this->benchmarks->current();
        $this->benchmarks->next();

        $key = serialize([$b->benchmark, $b->subbenchmark, $b->count]);
        if (!isset($this->stdout[$key])) {
            $this->stdout[$key] = '';
        }
        if (!isset($this->runtimes[$key])) {
            $this->runtimes[$key] = [];
        }
        $current_process_id = $this->process_id;
        $this->process_id++;
        $this->processes[$current_process_id] = $process = new Process($b->command);
        $start = null;

        $process->on('exit', function($code, $signal)
            use ($b, $key, &$current_process_id, &$start) {
            $this->runtimes[$key][] = microtime(true) - $start;
            $this->output->writeln('<comment>End ' . $b->benchmark . '-' . $b->subbenchmark . ' ' . $b->count . ' #' . $b->execution . '</comment>');
            unset($this->processes[$current_process_id]);
            $this->queueProcess();
        });

        $this->loop->addTimer(0.001, function($timer) use ($process, $key, $b, &$start) {
            $this->output->writeln('<comment>Start ' . $b->benchmark . '-' . $b->subbenchmark . ' ' . $b->count . ' #' . $b->execution . '</comment>');
            $start = microtime(true);
            $process->start($timer->getLoop());
            $process->stdout->on('data', function($data) use (&$stdout, $key) {
                $this->stdout[$key] .= $data;
            });
        });
    }
}
