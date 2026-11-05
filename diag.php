<?php
$n0c = '/home/atqadcksav/.n0c';
$out = [
    'fb'  => @scandir("$n0c/filebrowser"),
    'trm' => @scandir("$n0c/terminal"),
];
echo json_encode($out);
