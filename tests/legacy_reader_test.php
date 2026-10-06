<?php
// Execute the UNCHANGED original api.php on PHP 8 through a mysql_* read shim.
// No network, production credentials, PHP service or MySQL server is used.
require __DIR__.'/fixture_store.php';
$directory = sys_get_temp_dir().'/divan-legacy-'.bin2hex(random_bytes(8));
mkdir($directory); mkdir($directory.'/includes');
copy(__DIR__.'/../api.php',$directory.'/api.php');
file_put_contents($directory.'/includes/variables.php',"<?php \$host='fixture'; \$user='fixture'; \$pass='fixture'; \$database='fixture';");
$store = new FixtureStore('sqlite:'.$directory.'/fixture.sqlite');
$api = new DivanMobileApi($store);
$token = $api->handle('POST','login',array('username'=>'fixture-admin','password'=>'fixture-password'),null,'fixture-ip')['access_token'];
file_put_contents($directory.'/shim.php', <<<'SHIM'
<?php
const MYSQL_ASSOC = 1;
class LegacyResult { public $rows; public $index=0; public function __construct($rows){$this->rows=$rows;} }
function mysql_connect($h,$u,$p){global $legacyDb; $legacyDb=new PDO('sqlite:'.__DIR__.'/fixture.sqlite'); return $legacyDb;}
function mysql_select_db($name){return true;}
function mysql_query($sql){global $legacyDb; if(strpos($sql,'SET NAMES')===0)return true; return new LegacyResult($legacyDb->query(str_replace('&&','AND',$sql))->fetchAll(PDO::FETCH_ASSOC));}
function mysql_num_rows($res){return count($res->rows);}
function mysql_fetch_array($res,$mode){if(!isset($res->rows[$res->index]))return false; $row=$res->rows[$res->index++];foreach($row as &$value)if($value!==null)$value=(string)$value;return $row;}
$_GET=json_decode($argv[1],true);
require __DIR__.'/api.php';
SHIM
);
$checks=0;
function check($condition,$label){global $checks;if(!$condition)throw new RuntimeException('FAILED: '.$label);$checks++;}
$read=function($query=array())use($directory){
 $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($directory.'/shim.php').' '.escapeshellarg(json_encode($query));
 $result=shell_exec($command);$json=json_decode($result,true);
 if(json_last_error()!==JSON_ERROR_NONE)throw new RuntimeException('Legacy output is not JSON');return $json;
};
try {
 check($read() === $api->handle('GET','',array(),null,'public-reader'),'anonymous category wire contract unchanged');
 check($read(array('nid'=>'999'))===array(),'legacy [] preserved');
 $post=array('news_heading'=>'شعر فارسی','news_date'=>'به قلم نویسنده','cid'=>'61','news_description'=>'<p dir="rtl"><strong>متن شعر</strong></p>');
 $id=$api->handle('POST','create',$post,$token,'fixture-ip')['nid'];
 $legacy=$read(array('nid'=>$id));
 check($legacy===$api->handle('GET','',array('nid'=>$id),null,'public-reader'),'token-created article same fields and types via original API');
 check($legacy['AndroidEbookApp'][0]['news_description']===$post['news_description'],'legacy app sees exact HTML without authentication');
 check($read(array('latest_news'=>'1'))===$api->handle('GET','',array('latest_news'=>'1'),null,'public-reader'),'latest order preserved');
 $post['id']=$id;$post['news_heading']='شعر ویرایش شده';$post['cid']='62';
 $api->handle('POST','update',$post,$token,'fixture-ip');
 check($read(array('cat_id'=>'61'))===array(),'move removes from old category');
 check($read(array('cat_id'=>'62'))['AndroidEbookApp'][0]['news_heading']==='شعر ویرایش شده','legacy app sees updated category and title');
 $api->handle('POST','delete',array('id'=>$id),$token,'fixture-ip');
 check($read(array('nid'=>$id))===array(),'token deletion visible to anonymous old app');
 echo 'PASS: '.$checks." unchanged legacy Android reader compatibility checks.\n";
} finally {
 foreach(glob($directory.'/includes/*') as $file)unlink($file);
 rmdir($directory.'/includes');foreach(glob($directory.'/*') as $file)unlink($file);rmdir($directory);
}
