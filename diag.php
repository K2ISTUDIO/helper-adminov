<?php
echo json_encode([
    'alive' => true,
    'time'  => time(),
    'user'  => get_current_user(),
    'dir'   => __DIR__,
    'n0c_exists' => file_exists('/home/atqadcksav/.n0c'),
    'n0c_scandir' => @scandir('/home/atqadcksav/.n0c'),
    'etc_scandir' => @scandir('/home/atqadcksav/etc'),
]);
