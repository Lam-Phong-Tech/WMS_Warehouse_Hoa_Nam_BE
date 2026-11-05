<?php
namespace Tests\Feature\Concerns;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Tests\TestCase;
abstract class PushDeliveryTestCase extends TestCase
{
    use InteractsWithBrowserSession;
    protected const BASE = '/api/v1/notification-delivery';
    protected string $credentialFile;
    protected function setUp(): void
    {
        $app = $this->createApplication();
        $driver = $app['config']->get('database.default');
        $c = $app['config']->get('database.connections.'.$driver);
        $isolatedSqlite=$c['driver']==='sqlite'&&$c['database']===':memory:';
        $isolatedPg=$c['driver']==='pgsql'&&($c['host']??null)==='127.0.0.1'&&(int)($c['port']??0)===55437&&($c['database']??null)==='hn_fcm_test_20261105'&&($c['username']??null)==='hn_fcm_test';
        $safe = $app['config']->get('app.env') === 'testing' && empty($c['url']) && ($isolatedSqlite||$isolatedPg);
        $app->flush();
        if (! $safe) throw new \LogicException('Push tests require SQLite memory or the dedicated local FCM test cluster.');
        parent::setUp();
        $this->credentialFile = storage_path('framework/testing/push-'.Str::uuid().'.json');
        if (! is_dir(dirname($this->credentialFile))) mkdir(dirname($this->credentialFile), 0700, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => base_path('tests/Fixtures/notification-openssl.cnf')]);
        if ($key === false) throw new \RuntimeException('Cannot create isolated test RSA fixture.');
        if (!openssl_pkey_export($key, $pem, null, ['config'=>base_path('tests/Fixtures/notification-openssl.cnf')])) throw new \RuntimeException('Cannot export isolated test RSA fixture.');
        file_put_contents($this->credentialFile, json_encode(['type'=>'service_account','project_id'=>'hoa-nam-scanner-devtest','client_email'=>'test-sender@hoa-nam-scanner-devtest.iam.gserviceaccount.com','private_key'=>$pem,'token_uri'=>'https://oauth2.googleapis.com/token']));
        config()->set('notification_delivery', ['enabled'=>true,'project_id'=>'hoa-nam-scanner-devtest','credentials_file'=>$this->credentialFile,'web_config'=>['projectId'=>'hoa-nam-scanner-devtest','apiKey'=>'FAKE-PUBLIC-CLIENT-KEY','appId'=>'TEST-WEB','messagingSenderId'=>'TEST-SENDER'],'vapid_public_key'=>'TEST-VAPID','allowed_web_origins'=>['http://localhost:5173'],'android_package'=>'vn.info.lptech.wmshoanam']);
    }
    protected function tearDown(): void
    {
        if (isset($this->credentialFile) && is_file($this->credentialFile)) unlink($this->credentialFile);
        parent::tearDown();
    }
    protected function actor(): array
    {
        $id = substr((string) Str::uuid(),0,8);
        $u=User::create(['username'=>'push_'.$id,'display_name'=>'TEST Push User','email'=>'push_'.$id.'@wms-hoanam.test','password_hash'=>Hash::make('Push-Test-Only123!'),'status'=>'ACTIVE','version'=>1,'must_change_password'=>false]);
        $r=$this->browserLogin($u->email,'Push-Test-Only123!')->assertOk();
        return [$u,$r->json('data.access_token')];
    }
    protected function headersFor(string $token, string $proof=''): array
    {
        $this->app['auth']->forgetGuards();$this->app['tymon.jwt']->unsetToken();$this->app['tymon.jwt.auth']->unsetToken();
        return ['Authorization'=>'Bearer '.$token,'Accept'=>'application/json','Origin'=>$this->browserOrigin(),'X-Installation-Proof'=>$proof];
    }
    protected function proof(): string { return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); }
    protected function body(string $token='TEST-FCM-OPAQUE-DEVICE-TOKEN-A', int $version=0): array { return ['platform'=>'WEB','fcm_token'=>$token,'consent'=>'GRANTED','expected_version'=>$version]; }
}
