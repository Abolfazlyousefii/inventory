<?php
namespace Tests\Feature;

use App\Http\Middleware\EnsurePageAccess;
use App\Models\User;
use App\Support\{PageAccessCatalog,PermissionCatalog};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdministratorPageAccessTest extends TestCase
{
    use RefreshDatabase;

    private const REPORTS = [
        'finance.reports.receipts','finance.reports.receipts.detail','finance.reports.receipts.export',
        'finance.reports.top-products','finance.reports.top-products.detail','finance.reports.top-products.export','finance.reports.top-products.image',
    ];

    public function test_new_financial_report_routes_belong_to_the_finance_page(): void
    {
        foreach(self::REPORTS as $name){
            $this->assertNotNull(app('router')->getRoutes()->getByName($name));
            $this->assertSame(['page.finance.accounts'],PageAccessCatalog::permissionsForRoute($name),$name);
            $this->assertSame('finance.reports.view',PermissionCatalog::routePermissions()[$name]??null,$name);
        }
    }

    public function test_admin_role_aliases_can_access_all_pages_and_authenticated_unmapped_pages(): void
    {
        foreach(['Admin','admin','ادمین'] as $roleName){
            $user=User::factory()->create();$user->assignRole(Role::findOrCreate($roleName,'web'));
            $this->assertFalse($user->isSuperAdmin(),'Page access must not promote Admin to Super Admin');
            foreach(PageAccessCatalog::permissions() as $permission)$this->assertTrue(PageAccessCatalog::userCan($user,$permission),$roleName.':'.$permission);
            $request=Request::create('/future-admin-report','GET',[],[],[],['HTTP_ACCEPT'=>'application/json']);
            $route=new \Illuminate\Routing\Route(['GET'],'/future-admin-report',fn()=>response('ok'));$route->name('private.new-report');
            $request->setUserResolver(fn()=>$user);$request->setRouteResolver(fn()=>$route);
            $this->assertTrue(PageAccessCatalog::userCanRoute($user,'private.new-report'));
            $this->assertSame(200,app(EnsurePageAccess::class)->handle($request,fn()=>response('ok'))->getStatusCode());
        }
    }

    public function test_financial_report_access_stays_denied_for_other_roles_without_permission(): void
    {
        $user=User::factory()->create();$user->assignRole(Role::findOrCreate('Sales','web'));
        foreach(self::REPORTS as $name){
            $this->assertFalse(PageAccessCatalog::userCanRoute($user,$name));
            $this->assertSame(403,$this->check($user,$name));
        }
        $role=Role::findOrCreate('FinancialReportReader','web');
        $permission=\Spatie\Permission\Models\Permission::query()->where('key','page.finance.accounts')->firstOrFail();
        $role->givePermissionTo($permission);$user->assignRole($role);$user=$user->fresh();
        foreach(self::REPORTS as $name)$this->assertSame(200,$this->check($user,$name),$name);
        $this->assertFalse(PageAccessCatalog::userCanRoute($user,'private.new-report'));
    }

    private function check(User $user,string $name): int
    {
        $route=app('router')->getRoutes()->getByName($name);
        $request=Request::create('/'.$route->uri(),'GET',[],[],[],['HTTP_ACCEPT'=>'application/json']);
        $request->setRouteResolver(fn()=>$route);$request->setUserResolver(fn()=>$user);
        return app(EnsurePageAccess::class)->handle($request,fn()=>response('ok'))->getStatusCode();
    }
}
