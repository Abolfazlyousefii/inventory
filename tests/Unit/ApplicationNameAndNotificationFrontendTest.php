<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class ApplicationNameAndNotificationFrontendTest extends TestCase
{
    private const APP_NAME = 'نرم افزار داخلی آریا گستر';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.name' => self::APP_NAME,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    public function test_application_name_is_consistent_in_config_layouts_and_visible_views(): void
    {
        $this->assertSame(self::APP_NAME, config('app.name'));
        $this->assertStringContainsString(
            "'name' => env('APP_NAME', '".self::APP_NAME."')",
            file_get_contents(config_path('app.php'))
        );
        $this->assertStringContainsString(
            'APP_NAME="'.self::APP_NAME.'"',
            file_get_contents(base_path('.env.example'))
        );

        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $guest = file_get_contents(resource_path('views/layouts/guest.blade.php'));
        $sidebar = file_get_contents(resource_path('views/layouts/sidebar.blade.php'));
        $this->assertStringContainsString("\$pageDocumentTitle.' | '.\$appName", $layout);
        $this->assertStringContainsString('str_contains($pageDocumentTitle, $appName)', $layout);
        $this->assertStringContainsString("\$guestPageTitle.' | '.\$appName", $guest);
        $this->assertStringContainsString(self::APP_NAME, $sidebar);

        $viewFiles = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('views'))
        );
        foreach ($viewFiles as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $this->assertStringNotContainsString(
                    'Laravel',
                    file_get_contents($file->getPathname()),
                    "Visible framework name remains in {$file->getPathname()}"
                );
            }
        }
    }

    public function test_page_and_login_titles_append_the_application_name_once(): void
    {
        $products = Blade::render(
            "@extends('layouts.app') @section('title', 'نمایش کالاها') @section('content', 'فهرست')"
        );
        $this->assertStringContainsString(
            '<title>نمایش کالاها | '.self::APP_NAME.'</title>',
            $products
        );

        $alreadyNamed = Blade::render(
            "@extends('layouts.app') @section('title', '".self::APP_NAME."') @section('content', 'خانه')"
        );
        $this->assertStringContainsString('<title>'.self::APP_NAME.'</title>', $alreadyNamed);
        $this->assertStringNotContainsString(self::APP_NAME.' | '.self::APP_NAME, $alreadyNamed);

        $login = view('auth.login', ['errors' => new ViewErrorBag()])->render();
        $this->assertStringContainsString('<title>ورود | '.self::APP_NAME.'</title>', $login);
    }

    public function test_notification_frontend_has_badge_only_polling_and_no_automatic_toasts(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $script = file_get_contents(public_path('js/app-shell.js'));
        $styles = file_get_contents(public_path('css/app-shell.css'));

        foreach ([
            'notifToastStack',
            'notif-toast-stack',
            'showNotificationToast',
            'showToast',
            'notificationsSeenIds',
            'notifOverlay',
            'notif-overlay',
        ] as $removedFeature) {
            $this->assertStringNotContainsString($removedFeature, $layout.$script.$styles);
        }

        $this->assertStringContainsString('async function refreshNotificationCount()', $script);
        $this->assertStringContainsString('async function loadNotificationList()', $script);
        $this->assertStringContainsString('if (!countUrl || state.countRequest)', $script);
        $this->assertStringContainsString('state.listController?.abort()', $script);
        $this->assertStringContainsString('sequence !== state.listSequence', $script);
        $this->assertStringContainsString("count > 99 ? '+99'", $script);
        $this->assertStringContainsString('document.hidden ? 60000 : 30000', $script);
        $this->assertStringContainsString('refreshNotificationCount().finally(schedule)', $script);
        $this->assertStringNotContainsString('Promise.all', $script);
        $this->assertStringContainsString("aria-label=\"اعلان‌ها\"", $layout);
        $this->assertStringContainsString('aria-hidden="true"', $layout);
    }
}
