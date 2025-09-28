<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../getDB.php';

$raw = getDB();
class CachingDBTest { private $inner, $cache=[]; public function __construct($i){$this->inner=$i;} public function readAll($c,$cond=[],$o=null,$l=null){$k=md5($c.serialize($cond).$l); if(isset($this->cache[$k])) return $this->cache[$k]; $t = microtime(true); $res = $this->inner->readAll($c,$cond,$o,$l); $dt = microtime(true)-$t; echo "readAll($c) took " . round($dt,4) . "s\n"; $this->cache[$k]=$res; return $res; }}
$db = new CachingDBTest($raw);
$db->readAll('roles');
$db->readAll('stores');
$db->readAll('user_activities');
// repeat to show caching benefit
$db->readAll('roles');
$db->readAll('stores');
$db->readAll('user_activities');
