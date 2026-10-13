<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection::findOrFail(11);
$im=imagecreatetruecolor(320,320);$white=imagecolorallocate($im,255,255,255);$red=imagecolorallocate($im,255,0,0);imagefill($im,0,0,$white);imagefilledrectangle($im,80,80,240,240,$red);ob_start();imagepng($im);$bytes=ob_get_clean();
$r=Illuminate\Support\Facades\Http::withToken($c->getApiKey())->timeout(35)->post('https://integrate.api.nvidia.com/v1/chat/completions',['model'=>'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning','messages'=>[['role'=>'user','content'=>[['type'=>'text','text'=>'Describe the central shape and its colour in at most 15 words.'],['type'=>'image_url','image_url'=>['url'=>'data:image/png;base64,'.base64_encode($bytes)]]]]],'max_tokens'=>120,'temperature'=>0,'chat_template_kwargs'=>['enable_thinking'=>false]]);
echo json_encode(['status'=>$r->status(),'model'=>'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning','answer'=>$r->json('choices.0.message.content'),'usage'=>$r->json('usage')]).PHP_EOL;
