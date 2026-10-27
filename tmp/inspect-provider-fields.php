<?php
require __DIR__.'/../vendor/autoload.php';$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$s=app(App\Services\CommunicationSettings::class)->get('whatsapp');$out=[];foreach($s as $k=>$v){$out[$k]=preg_match('/key|secret|token/i',$k)?['present'=>!empty($v)]:$v;}echo json_encode($out,JSON_PRETTY_PRINT);
