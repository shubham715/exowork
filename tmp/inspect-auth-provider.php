<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
 $s=app(App\Services\CommunicationSettings::class);$s->apply();
 echo json_encode(['provider'=>config('whatsapp.provider'),'missing'=>app(App\Services\WhatsAppCloud::class)->missing(),'authentication'=>app(App\Services\AuthVerification::class)->settings(),'settings_channels'=>Illuminate\Support\Facades\DB::table('communication_settings')->pluck('channel')],JSON_PRETTY_PRINT);
} catch(Throwable $e){echo json_encode(['error_type'=>get_class($e),'code'=>$e->getCode()]);exit(1);}
