<?php

namespace Elazar\SplBenchmarks;

use React\EventLoop\LoopInterface;
use React\EventLoop\Factory as LoopFactory;
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
                'Comma-delimited list of benchmark pairs from benchmarks/ to run',
                $benchmarks
            )
            ->addOption(
                'chart-font',
                'cf',
                InputOption::VALUE_OPTIONAL,
                'Path to TrueType Font file to use in generated charts',
                '/usr/share/fonts/truetype/msttcorefonts/arial.ttf'
            )
            ->addOption(
                'chart-dimensions',
                'cd',
                InputOption::VALUE_OPTIONAL,
                'Dimensions for generated charts in the form WxH',
                '800x450'
            )
            ->addOption(
                'cpus',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Number of processors to use when running benchmarks',
                2
            )
            ->addOption(
                'destination',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Path to which to write the benchmark results',
                './results'
            )
            ->addOption(
                'elements',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Comma-delimited list of quantities of elements to use per test',
                '10,100,500,1000,5000'
            )
            ->addOption(
                'executions',
                'x',
                InputOption::VALUE_OPTIONAL,
                'Number of executions per test',
                50
            )
            ->addOption(
                'php-path',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Path to the php CLI executable',
                \PHP_BINDIR . '/php'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $destination = $this->getDestination($input);
        $name = 'raw.csv';
        $path = $destination . '/' . $name;

        $finder = $this->getFinder()
            ->files()
            ->in($destination)
            ->name($name);

        if (count($finder) > 0) {
            $output->writeln('<info>Existing results file found: ' . $path . '</info>');
            $results = $this->readRawResults($finder->getIterator()->current()->getContents());
        } else {
            $output->writeln('<info>No results file found, running benchmarks</info>');

            $runner = new BenchmarkRunner(
                $output,
                $this->getLoop(),
                $this->getBenchmarkIterator($input),
                $this->getCpus($input)
            );
            $runner->run();
            $results = $runner->getResults();

            $output->writeln('<info>Storing data in results file: ' . $path . '</info>');
            $this->writeRawResults($path, $results);
        }

        $output->writeln('<info>Generating charts</info>');
        $this->generateCharts($input, $output, $destination, $results);

        $output->writeln('<info>Done</info>');
    }

    protected function getBenchmarkIterator(InputInterface $input)
    {
        return new BenchmarkIterator(
            $this->getBenchmarks($input),
            ['s', 'a'],
            $this->getElements($input),
            $this->getExecutions($input),
            $this->getPhpPath($input),
            __DIR__ . '/../benchmarks'
        );
    }

    protected function writeRawResults($path, array $results)
    {
        $lines = ['"' . implode('","', ['Elements', 'File', 'Time (μs)', 'Executions/Second', 'Memory (b)']) . '"'];
        foreach ($results as $result) {
            $file = $result->benchmark . '-' . $result->subbenchmark . '.php';
            $lines[] = '"' . implode('","', [
                    $result->count,
                    $file,
                    $result->time,
                    $result->eps,
                    $result->memory,
                ]) . '"';
        }
        $this->getFilesystem()->dumpFile($path, implode(PHP_EOL, $lines));
    }

    protected function readRawResults($contents)
    {
        $lines = array_slice(explode(PHP_EOL, $contents), 1);
        $results = [];
        foreach ($lines as $line) {
            list($count, $file, $time, $eps, $memory) = str_getcsv($line);
            list($benchmark, $subbenchmark) = explode('-', str_replace('.php', '', $file));
            $result = new BenchmarkResult;
            $result->benchmark = $benchmark;
            $result->subbenchmark = $subbenchmark;
            $result->count = $count;
            $result->time = $time;
            $result->eps = $eps;
            $result->memory = $memory;
            $results[] = $result;
        }
        return $results;
    }

    protected function generateCharts(InputInterface $input, OutputInterface $output, $destination, array $results)
    {
        $gd_info = gd_info();
        if (!$gd_info['FreeType Support']) {
            throw new \RuntimeException('gd extension must be compiled with FreeType support');
        }

        $font = $this->getChartFont($input);
        list($width, $height) = $this->getChartDimensions($input);
        $chart_data = $this->getChartData($results);

        foreach ($chart_data as $measure => $benchmarks) {
            foreach ($benchmarks as $benchmark => $subbenchmarks) {
                $file = $destination . '/' . $benchmark . '_' . $measure . '.png';
                $output->writeln('<comment>Writing ' . $file . '</comment>');

                $driver = new \ezcGraphGdDriver;
                $driver->options->supersampling = 1;
                $driver->options->imageFormat = IMG_PNG;

                $graph = new \ezcGraphBarChart;
                $graph->driver = $driver;
                $graph->options->font = $font;
                $graph->xAxis->label = 'Elements';
                $graph->yAxis->label = ($measure == 'memory') ? 'Memory (KB)' : 'Executions / Second';
                $graph->title = $benchmark . ' - ' . $measure;
                foreach ($subbenchmarks as $subbenchmark => $elements) {
                    $graph->data[$subbenchmark] = new \ezcGraphArrayDataSet($elements);
                }

                $graph->render($width, $height, $file);
            }
        }
    }

    protected function getChartData(array $results)
    {
        $chart_data = [];
        foreach ($results as $result) {
            $benchmark = $result->benchmark;
            $subbenchmark = $result->subbenchmark;
            $count = $result->count;

            foreach (['memory', 'eps'] as $measure) {
                if (!isset($chart_data[$measure][$benchmark])) {
                    $chart_data[$measure][$benchmark] = [];
                }
                if (!isset($chart_data[$measure][$benchmark][$subbenchmark])) {
                    $chart_data[$measure][$benchmark][$subbenchmark] = [];
                }
                if (!isset($chart_data[$measure][$benchmark][$subbenchmark][$count])) {
                    $chart_data[$measure][$benchmark][$subbenchmark][$count] = [];
                }
            }

            $chart_data['memory'][$benchmark][$subbenchmark][$count] = ceil($result->memory / 1024); // convert to KB
            $chart_data['eps'][$benchmark][$subbenchmark][$count] = $result->eps;
        }
        return $chart_data;
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

    protected function getChartFont(InputInterface $input)
    {
        $cf = $input->getOption('chart-font');
        if (!is_readable($cf)) {
            throw new \RuntimeException('--chart-font must reference a readable file path');
        }
        return $cf;
    }

    protected function getChartDimensions(InputInterface $input)
    {
        $cd = $input->getOption('chart-dimensions');
        if (!preg_match('/^(?P<width>[0-9]+)[xX](?P<height>[0-9]+)$/', $cd, $match)) {
            throw new \DomainException('--chart-dimensions must be of the form WxH');
        }
        return [(int) $match['width'], (int) $match['height']];
    }

    protected function getCpus(InputInterface $input)
    {
        $c = $input->getOption('cpus');
        if (!is_int($c) && !ctype_digit($c)) {
            throw new \DomainException('--cpus must be a positive integer: ' . $c);
        }
        return (int) $c;
    }

    protected function getDestination(InputInterface $input)
    {
        $d = $input->getOption('destination');
        $dirname = dirname($d);
        $basename = basename($d);
        $finder = $this->getFinder();
        $finder
            ->in($dirname)
            ->name($basename)
            ->directories()
            ->followLinks()
            ->ignoreUnreadableDirs();
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
        return array_map('intval', $e);
    }

    protected function getExecutions(InputInterface $input)
    {
        $e = $input->getOption('executions');
        if (!is_int($e) && !ctype_digit($e)) {
            throw new \RuntimeException('--executions must be a positive integer: ' . $e);
        }
        return (int) $e;
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
