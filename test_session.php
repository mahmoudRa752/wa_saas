<?php
session_start();
$_SESSION['test'] = 'ok';
session_write_close();
echo session_id();
