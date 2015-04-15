<?php

namespace Elazar\SplBenchmarks;

class BenchmarkIterator implements \Iterator
{
    protected $benchmarks;
    protected $subbenchmarks;
    protected $counts;
    protected $executions;
    protected $php_path;
    protected $benchmarks_path;

    protected $current_key;
    protected $current_benchmark;
    protected $current_subbenchmark;
    protected $current_count;
    protected $current_execution;
    protected $valid;

    public function __construct($benchmarks, $subbenchmarks, $counts, $executions, $php_path, $benchmarks_path)
    {
        $this->benchmarks = $benchmarks;
        $this->subbenchmarks = $subbenchmarks;
        $this->counts = $counts;
        $this->executions = $executions;
        $this->php_path = $php_path;
        $this->benchmarks_path = $benchmarks_path;

        $this->rewind();
    }

    public function current()
    {
        $b = new Benchmark;
        $b->benchmark = $this->benchmarks[$this->current_benchmark];
        $b->subbenchmark = $this->subbenchmarks[$this->current_subbenchmark];
        $b->count = $this->counts[$this->current_count];
        $b->execution = $this->current_execution;
        $b->command = $this->getCommand($b->benchmark, $b->subbenchmark, $b->count);
        return $b;
    }

    public function key()
    {
        return $this->current_key;
    }

    public function next()
    {
        $this->current_key++;

        $this->current_execution++;
        if ($this->current_execution <= $this->executions) {
            return;
        }
        $this->current_execution = 1;

        $this->current_count++;
        if (isset($this->counts[$this->current_count])) {
            return;
        }
        $this->current_count = 0;

        $this->current_subbenchmark++;
        if (isset($this->subbenchmarks[$this->current_subbenchmark])) {
            return;
        }
        $this->current_subbenchmark = 0;

        $this->current_benchmark++;
        if (isset($this->benchmarks[$this->current_benchmark])) {
            return;
        }
        $this->valid = false;
    }

    public function rewind()
    {
        $this->current_key = 0;
        $this->current_benchmark = 0;
        $this->current_subbenchmark = 0;
        $this->current_count = 0;
        $this->current_execution = 1;
        $this->valid = true;
    }

    public function valid()
    {
        return $this->valid;
    }

    protected function getCommand($benchmark, $subbenchmark, $count)
    {
        $file = $this->benchmarks_path . '/' . $benchmark . '-' . $subbenchmark . '.php';
        return $this->php_path
            . ' -d max_execution_time=0'
            . ' -d auto_append_file="' . $this->benchmarks_path . '/memory.php"'
            . ' ' . $file . ' ' . $count;
    }
}
