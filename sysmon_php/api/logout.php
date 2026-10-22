<?php
require_once '../includes/data.php';
session_start();
session_destroy();
json_response(['ok' => true]);
