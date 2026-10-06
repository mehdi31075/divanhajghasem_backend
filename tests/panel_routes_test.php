<?php
$root=realpath(__DIR__.'/..');$checks=0;
function check($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAILED: '.$label);$checks++;}
function execute($path,$method){
 $script='register_shutdown_function(function(){fwrite(STDERR,(string)http_response_code());}); $_SERVER["REQUEST_METHOD"]='.var_export($method,true).'; $_SESSION=array("user"=>"fixture-admin","timeout"=>PHP_INT_MAX); require '.var_export($path,true).';';
 $pipes=array();$proc=proc_open(array(PHP_BINARY,'-r',$script),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
 $body=stream_get_contents($pipes[1]);$status=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 if(proc_close($proc)!==0)throw new RuntimeException('PHP route crashed: '.$path);return array($body,$status);
}
foreach(glob($root.'/*.php') as $path){
 if(in_array(basename($path),array('api.php','mobile-api.php'),true))continue;
 list($body,$status)=execute($path,'POST');check($status==='410','old cookie POST retired: '.basename($path));
 list($body,$status)=execute($path,'GET');check(strpos($body,'id="login-form"')!==false,'entry opens token panel: '.basename($path));
 check(strpos(file_get_contents($path),'session_start')===false,'no PHP session in entry');
}
foreach(glob($root.'/public/*.php') as $path){list($body,$status)=execute($path,'POST');check($status==='410','direct legacy include retired: '.basename($path));}
$base=shell_exec('git -C '.escapeshellarg($root).' show 7678bac:api.php');
check($base===file_get_contents($root.'/api.php'),'anonymous old api.php byte-for-byte unchanged');
echo 'PASS: '.$checks." token-only panel route checks.\n";
