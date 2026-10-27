<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$settings=app(App\Services\CommunicationSettings::class);
$values=app(App\Services\AuthVerification::class)->settings();
$values['template_name']='exowork_auth_otp_v1';
$values['template_language']='en_US';
$values['gupshup_template_id']='79d31f40-de23-4afd-a5bf-140feda70ab1';
$settings->save('authentication',$values);
$saved=$settings->get('authentication');
echo json_encode(['saved'=>$saved['template_name']===$values['template_name']&&$saved['gupshup_template_id']===$values['gupshup_template_id'],'template_name'=>$saved['template_name'],'language'=>$saved['template_language'],'template_id'=>$saved['gupshup_template_id']],JSON_PRETTY_PRINT);
