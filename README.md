This repository contains scripts intended to benchmark operations of data 
structure classes from the PHP SPL extension against their array counterparts.

# Requirements

* PHP 5.4+
* GD extension with FreeType support enabled

# Installation

Clone the repository and use [Composer](http://getcomposer.org) to install dependencies.

```bash
git clone https://github.com/elazar/spl-benchmarks.git
cd spl-benchmarks
composer install -o
```

# Usage

To invoke the benchmark runner, run this from the repository root directory:

```bash
./bin/spl-benchmarks
```

To learn about the runner's supported arguments:

```bash
./bin/spl-benchmarks --help
```

# Results

By default, results are written to the `results` subdirectory of the repository
root direction. All raw data is written in CSV format to `raw.csv`. Charts are
generated in PNG format for executions per second and memory for each
benchmark.

The `results` subdirectory also contains an `index.html` file for convenient
viewing of all generated chart files.
