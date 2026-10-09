<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DivanApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('tbl_user')->insert(['Username' => 'fixture-admin', 'Password' => hash('sha256', 'fixture-adminfixture-password'), 'Email' => 'admin@example.test']);
        DB::table('tbl_news_category')->insert([
            ['cid' => 61, 'category_name' => 'دسته اول', 'category_image' => 'one.png', 'author' => 'نویسنده', 'status' => 1],
            ['cid' => 62, 'category_name' => 'دسته دوم', 'category_image' => 'two.png', 'author' => 'نویسنده', 'status' => 1],
        ]);
    }

    private function login(): string
    {
        $response = $this->postJson('/mobile-api.php?action=login', ['username' => ' FIXTURE-ADMIN ', 'password' => 'fixture-password'])->assertOk()->assertJsonPath('token_type', 'Bearer');
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $token = $response->json('access_token');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertDatabaseHas('divan_api_tokens', ['token_hash' => hash('sha256', $token)]);

        return $token;
    }

    private function postFields(): array
    {
        return ['news_heading' => 'عنوان فارسی', 'news_date' => 'عنوان فرعی', 'cid' => '61', 'news_description' => '<p dir="rtl"><strong>متن اصلی</strong></p>'];
    }

    public function test_anonymous_legacy_reader_contract_and_crud_visibility(): void
    {
        $categories = $this->getJson('/api.php')->assertOk()->json('AndroidEbookApp');
        $this->assertSame(['62', '61'], array_column($categories, 'cid'));
        $this->assertSame(['cid', 'category_name', 'category_image', 'author', 'status'], array_keys($categories[0]));
        foreach ($categories[0] as $value) {
            $this->assertIsString($value);
        }
        $this->getJson('/api.php?nid=999')->assertExactJson([]);
        $this->getJson('/api.php?cat_id=61')->assertExactJson([]);
        $token = $this->login();
        $fields = $this->postFields();
        $id = $this->withToken($token)->postJson('/mobile-api.php?action=create', $fields)->assertOk()->json('nid');
        $this->assertIsString($id);
        $this->flushHeaders();
        $legacyRead = $this->getJson('/api.php?nid='.$id)->assertOk()->json('AndroidEbookApp.0');
        $this->assertSame(['cid', 'category_name', 'category_image', 'author', 'status', 'nid', 'news_heading', 'cat_id', 'news_status', 'news_date', 'news_image', 'news_description'], array_keys($legacyRead));
        $read = $this->getJson('/api.php?nid='.$id.'&include_dates=1')->assertOk()->json('AndroidEbookApp.0');
        $this->assertSame($fields['news_description'], $read['news_description']);
        $this->assertSame(['cid', 'category_name', 'category_image', 'author', 'status', 'nid', 'news_heading', 'cat_id', 'news_status', 'news_date', 'news_image', 'news_description', 'created_at', 'updated_at'], array_keys($read));
        foreach (array_slice($read, 0, 12) as $value) {
            $this->assertIsString($value);
        }
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $read['created_at']);
        $this->assertSame($read['created_at'], $read['updated_at']);
        $second = $this->withToken($token)->postJson('/mobile-api.php?action=create', $fields)->json('nid');
        $this->flushHeaders();
        $this->assertSame([$id, $second], array_column($this->getJson('/api.php?cat_id=61')->json('AndroidEbookApp'), 'nid'));
        $this->assertSame($id, $this->getJson('/api.php?latest_news=1')->json('AndroidEbookApp.0.nid'));
        $this->assertSame($id, $this->getJson('/api.php?cat_id=61&nid='.$second)->json('AndroidEbookApp.0.nid'));
        $fields['news_heading'] = 'عنوان ویرایش‌شده';
        $this->withToken($token)->postJson('/mobile-api.php?action=update', $fields + ['id' => $id])->assertOk();
        $this->flushHeaders();
        $this->getJson('/api.php?nid='.$id)->assertJsonPath('AndroidEbookApp.0.news_heading', $fields['news_heading']);
        $this->assertNotNull(DB::table('tbl_news')->where('nid', $id)->value('created_at'));
        $this->assertNotNull(DB::table('tbl_news')->where('nid', $id)->value('updated_at'));
        $this->withToken($token)->postJson('/mobile-api.php?action=delete', ['id' => $id])->assertOk();
        $this->flushHeaders();
        $this->getJson('/api.php?nid='.$id)->assertExactJson([]);
    }

    public function test_only_bearer_authorizes_management_and_logout_revokes_it(): void
    {
        $this->withCookie('PHPSESSID', 'legacy-session')->postJson('/mobile-api.php?action=create', $this->postFields())->assertUnauthorized();
        $this->getJson('/mobile-api.php?action=posts')->assertUnauthorized();
        $this->postJson('/mobile-api.php?action=login', ['username' => 'fixture-admin', 'password' => 'wrong'])->assertUnauthorized();
        $token = $this->login();
        $this->withToken($token)->getJson('/mobile-api.php?action=me')->assertJsonPath('username', 'fixture-admin');
        $this->withToken($token)->postJson('/mobile-api.php?action=logout')->assertOk();
        $this->withToken($token)->getJson('/mobile-api.php?action=me')->assertUnauthorized();
        $this->flushHeaders();
        $this->getJson('/api.php')->assertOk();
    }

    public function test_app_accounts_use_phone_otp_then_name_only_for_new_users_and_are_listed_in_panel(): void
    {
        $started = $this->postJson('/mobile-api.php?action=user_start', ['mobile' => '۰۹۱۲۳۴۵۶۷۸۹'])
            ->assertOk()->assertJsonPath('mode', 'register');
        $challenge = $started->json('challenge_id');
        $otp = $started->json('test_otp');
        $this->postJson('/mobile-api.php?action=user_verify', ['challenge_id' => $challenge, 'otp' => $otp])
            ->assertOk()->assertJsonPath('needs_name', true);
        $registered = $this->postJson('/mobile-api.php?action=user_verify', [
            'challenge_id' => $challenge, 'otp' => $otp, 'name' => 'کاربر تازه',
        ])->assertOk()->assertJsonPath('user.name', 'کاربر تازه');
        $userToken = $registered->json('access_token');
        $this->withToken($userToken)->getJson('/mobile-api.php?action=user_me')
            ->assertOk()->assertJsonPath('user.mobile', '+989123456789');
        $admin = $this->login();
        $this->withToken($admin)->getJson('/mobile-api.php?action=users')
            ->assertOk()->assertJsonPath('users.0.name', 'کاربر تازه')
            ->assertJsonPath('users.0.mobile', '+989123456789');
        $this->withToken($userToken)->postJson('/mobile-api.php?action=user_logout')->assertOk();
        $this->withToken($userToken)->getJson('/mobile-api.php?action=user_me')->assertUnauthorized();
    }

    public function test_article_views_are_separate_from_legacy_payload_until_flutter_opts_in(): void
    {
        $admin = $this->login();
        $id = $this->withToken($admin)->postJson('/mobile-api.php?action=create', $this->postFields())->assertOk()->json('nid');
        $this->postJson('/mobile-api.php?action=article_view', ['nid' => $id])->assertOk()->assertJsonPath('views', 1);
        $this->postJson('/mobile-api.php?action=article_view', ['nid' => $id])->assertOk()->assertJsonPath('views', 2);
        $this->flushHeaders();
        $legacy = $this->getJson('/api.php?nid='.$id)->assertOk()->json('AndroidEbookApp.0');
        $this->assertArrayNotHasKey('view_count', $legacy);
        $withViews = $this->getJson('/api.php?nid='.$id.'&include_dates=1&include_views=1')->assertOk()->json('AndroidEbookApp.0');
        $this->assertSame('2', $withViews['view_count']);
        $this->assertArrayHasKey('created_at', $withViews);
    }

    public function test_expired_token_and_changed_password_fail_closed(): void
    {
        $token = $this->login();
        DB::table('divan_api_tokens')->update(['expires_at' => time() - 1]);
        $this->withToken($token)->getJson('/mobile-api.php?action=me')->assertUnauthorized();
        $token = $this->login();
        DB::table('tbl_user')->update(['Password' => 'changed']);
        $this->withToken($token)->postJson('/mobile-api.php?action=delete', ['id' => 1])->assertUnauthorized();
    }

    public function test_password_change_upgrades_hash_and_invalidates_all_old_tokens(): void
    {
        $first = $this->login();
        $second = $this->login();
        $this->withToken($first)->getJson('/mobile-api.php?action=account')->assertJsonMissingPath('account.Password');
        $this->postJson('/mobile-api.php?action=account_update', [
            'email' => 'updated@example.test', 'old_password' => 'fixture-password',
            'new_password' => 'fresh-fixture-password', 'confirm_password' => 'fresh-fixture-password',
        ])->assertJsonPath('reauthenticate', true);
        $this->assertTrue(password_verify('fresh-fixture-password', DB::table('tbl_user')->value('Password')));
        $this->withToken($first)->getJson('/mobile-api.php?action=me')->assertUnauthorized();
        $this->withToken($second)->getJson('/mobile-api.php?action=me')->assertUnauthorized();
        $this->postJson('/mobile-api.php?action=login', ['username' => 'fixture-admin', 'password' => 'fresh-fixture-password'])->assertOk();
    }

    public function test_real_uploaded_image_and_category_mutations(): void
    {
        Storage::fake('category_images');
        $this->withToken($this->login());
        $fields = ['category_name' => 'دسته تازه', 'author' => 'نویسنده'];
        $this->postJson('/mobile-api.php?action=category_create', $fields)->assertUnprocessable();
        $id = $this->post('/mobile-api.php?action=category_create', $fields + ['category_image' => UploadedFile::fake()->image('picture.png')], ['Accept' => 'application/json'])->assertOk()->json('cid');
        $image = DB::table('tbl_news_category')->where('cid', $id)->value('category_image');
        Storage::disk('category_images')->assertExists($image);
        $this->post('/mobile-api.php?action=category_update', ['id' => $id, 'category_name' => 'نام تازه', 'author' => 'نویسنده'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($image, DB::table('tbl_news_category')->where('cid', $id)->value('category_image'));
        $this->post('/mobile-api.php?action=category_update', ['id' => $id] + $fields + [
            'category_image' => new UploadedFile('', 'large.png', 'image/png', UPLOAD_ERR_INI_SIZE, true),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/mobile-api.php?action=category_update', ['id' => $id] + $fields + ['category_image' => UploadedFile::fake()->createWithContent('evil.png', '<?php echo 1;')], ['Accept' => 'application/json'])->assertUnprocessable();
        $nid = $this->postJson('/mobile-api.php?action=create', array_replace($this->postFields(), ['cid' => $id]))->assertOk()->json('nid');
        $this->postJson('/mobile-api.php?action=category_delete', ['id' => $id])->assertConflict();
        $this->postJson('/mobile-api.php?action=delete', ['id' => $nid])->assertOk();
        $this->postJson('/mobile-api.php?action=category_delete', ['id' => $id])->assertOk();
        Storage::disk('category_images')->assertExists($image);
    }

    public function test_content_images_upload_to_the_host_and_are_returned_as_inline_media_urls(): void
    {
        Storage::fake('news_media');
        $this->withToken($this->login());
        $response = $this->post('/mobile-api.php?action=media_upload', [
            'media_type' => 'image', 'media_file' => UploadedFile::fake()->image('درج-در-متن.png'),
        ], ['Accept' => 'application/json'])->assertOk();
        $url = $response->json('media.url');
        $this->assertStringStartsWith('/upload/news-media/', $url);
        $this->assertStringEndsWith('.png', $url);
        Storage::disk('news_media')->assertExists(substr($url, strlen('/upload/news-media/')));
        $media = $this->getJson('/mobile-api.php?action=media_list')->assertOk()->json('media.0');
        $this->assertSame('image', $media['type']);
        $id = basename(parse_url($media['url'], PHP_URL_PATH));
        $this->postJson('/mobile-api.php?action=media_update', ['id' => $id, 'name' => 'تصویر یادبود'])->assertOk()->assertJsonPath('media.name', 'تصویر یادبود');
        $this->postJson('/mobile-api.php?action=media_delete', ['id' => $id])->assertOk();
        Storage::disk('news_media')->assertMissing($id);
        $this->post('/mobile-api.php?action=media_upload', [
            'media_type' => 'image', 'media_file' => UploadedFile::fake()->createWithContent('wrong.png', '<?php echo 1;'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->getJson('/mobile-api.php?action=media_list')->assertOk()->assertJsonPath('videos', []);
    }

    public function test_public_support_message_gets_private_receipt_and_admin_reply_returns_to_app(): void
    {
        config(['divan.require_https' => false]);
        $message = "انتقاد و پیشنهاد فارسی\nسطر دوم";
        $response = $this->postJson('/mobile-api.php?action=support_create', ['message' => $message])
            ->assertOk()->assertJsonPath('ok', true);
        $receipt = $response->json('receipt');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $receipt);
        $this->assertDatabaseHas('divan_support_messages', ['message' => $message]);
        $this->postJson('/mobile-api.php?action=support_check', ['receipt' => $receipt])
            ->assertOk()->assertJsonPath('ticket.message', $message)->assertJsonPath('ticket.reply', null);

        $token = $this->login();
        $this->withToken($token)->getJson('/mobile-api.php?action=support_list')
            ->assertOk()->assertJsonPath('messages.0.message', $message);
        $this->withToken($token)->postJson('/mobile-api.php?action=support_reply', ['id' => '1', 'reply' => 'پاسخ مدیر'])
            ->assertOk();
        $this->flushHeaders();
        $this->postJson('/mobile-api.php?action=support_check', ['receipt' => $receipt])
            ->assertOk()->assertJsonPath('ticket.reply', 'پاسخ مدیر')->assertJsonPath('ticket.replied_at', fn ($value) => is_string($value));
        $this->postJson('/mobile-api.php?action=support_create', ['message' => ''])
            ->assertUnprocessable();
    }

    public function test_validation_preserves_database_and_public_reader_is_injection_safe(): void
    {
        $this->withToken($this->login());
        $fields = $this->postFields();
        foreach ([['cid' => "61' OR 1=1"], ['news_description' => ''], ['news_heading' => str_repeat('آ', 501)], ['news_description' => str_repeat('آ', 2500001)]] as $invalid) {
            $this->postJson('/mobile-api.php?action=create', array_replace($fields, $invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('tbl_news', 0);
        $this->getJson('/api.php?nid=1%20OR%201=1')->assertUnprocessable();
        $this->getJson('/api.php?cat_id[]=61')->assertUnprocessable();
        $this->getJson('/mobile-api.php?action=login')->assertStatus(405);
        $this->postJson('/api.php', [])->assertStatus(405);
    }

    public function test_longtext_content_round_trips_beyond_the_old_text_limit(): void
    {
        $this->withToken($this->login());
        $body = str_repeat('متن فارسی ', 8000);
        $this->assertGreaterThan(65535, strlen($body));
        $id = $this->postJson('/mobile-api.php?action=create', array_replace($this->postFields(), ['news_description' => $body]))->assertOk()->json('nid');
        $this->flushHeaders();
        $this->getJson('/api.php?nid='.$id)->assertJsonPath('AndroidEbookApp.0.news_description', $body);
    }

    public function test_transport_cors_https_and_old_panel_endpoints(): void
    {
        $shell = $this->get('/')->assertOk()->assertSee('ورود به مدیریت')->assertSee('assets/panel/app.js', false);
        $this->assertFalse($shell->headers->has('Set-Cookie'));
        $this->get('/edit-menu.php?id=7')->assertOk();
        $this->postJson('/add-menu.php', $this->postFields())->assertStatus(410);
        $this->postJson('/public/add-menu.php', $this->postFields())->assertStatus(410);
        $this->withHeader('Origin', 'https://reader.example.test')->getJson('/api.php')->assertHeader('Access-Control-Allow-Origin', '*');
        $this->options('/mobile-api.php', [], ['Origin' => 'https://reader.example.test', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization,content-type'])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');
        config(['divan.require_https' => true]);
        $this->postJson('/mobile-api.php?action=login', ['username' => 'fixture-admin', 'password' => 'fixture-password'])->assertStatus(426);
        $this->getJson('/api.php')->assertOk();
        $this->postJson('https://localhost/mobile-api.php?action=login', ['username' => 'fixture-admin', 'password' => 'fixture-password'])->assertOk();
        $this->assertDatabaseCount('tbl_news', 0);
    }

    public function test_migration_keeps_existing_accounts_content_tokens_and_schema(): void
    {
        $token = $this->login();
        $this->withToken($token)->postJson('/mobile-api.php?action=create', $this->postFields())->assertOk();
        $before = [];
        foreach (['tbl_user', 'tbl_news_category', 'tbl_news', 'divan_api_tokens'] as $table) {
            $before[$table] = DB::table($table)->get()->toArray();
        }
        $migration = require database_path('migrations/2026_10_06_000001_prepare_divan_tables.php');
        $migration->up();
        foreach ($before as $table => $rows) {
            $this->assertEquals($rows, DB::table($table)->get()->toArray());
        }
        $this->getJson('/mobile-api.php?action=me')->assertOk();
        $this->artisan('divan:check')->assertSuccessful();
    }

    public function test_password_spaces_and_body_whitespace_are_not_trimmed(): void
    {
        DB::table('tbl_user')->update(['Password' => hash('sha256', 'fixture-admin spaced password ')]);
        $token = $this->postJson('/mobile-api.php?action=login', ['username' => 'fixture-admin', 'password' => ' spaced password '])->assertOk()->json('access_token');
        $body = " \n<p>متن</p>\n ";
        $id = $this->withToken($token)->postJson('/mobile-api.php?action=create', array_replace($this->postFields(), ['news_description' => $body]))->assertOk()->json('nid');
        $this->getJson('/api.php?nid='.$id)->assertJsonPath('AndroidEbookApp.0.news_description', $body);
    }

    public function test_legacy_latest_limit_does_not_inherit_the_mobile_api_cap(): void
    {
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = ['news_heading' => 'عنوان', 'cat_id' => 61, 'news_date' => 'زیرعنوان', 'news_description' => 'متن', 'news_image' => ''];
        }
        DB::table('tbl_news')->insert($rows);
        $this->getJson('/api.php?latest_news=501')->assertJsonCount(501, 'AndroidEbookApp');
        $this->getJson('/mobile-api.php?latest_news=501')->assertJsonCount(500, 'AndroidEbookApp');
    }
}
