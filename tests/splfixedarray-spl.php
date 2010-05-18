<?php
$a = new SplFixedArray($argv[1]);
for ($i = 0; $i < $argv[1]; $i++) {
    $a[$i] = $i;
    $i = $a[$i];
}
