<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('allows a user row to be stored with a null email after the migration', function (): void {
    $user = User::factory()->create([
        'email' => null,
        'phone' => '09120000501',
        'is_active' => true,
        'can_access_erp' => true,
    ]);

    expect($user->fresh()->email)->toBeNull()
        ->and(DB::table('users')->whereNull('email')->count())->toBe(1);
});

it('keeps the users.email column nullable in the schema', function (): void {
    // sqlite/mysql agnostic: a direct null insert is the portable proof.
    $id = DB::table('users')->insertGetId([
        'name' => 'کاربر بدون ایمیل',
        'email' => null,
        'phone' => '09120000502',
        'password' => Hash::make('secret-password'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('users')->where('id', $id)->value('email'))->toBeNull();
});

it('does not disturb other user columns when email is null', function (): void {
    $user = User::factory()->create([
        'email' => null,
        'name' => 'کاربر تلفنی',
        'phone' => '09120000503',
        'is_active' => true,
        'can_access_erp' => true,
    ]);

    $fresh = $user->fresh();

    expect($fresh->name)->toBe('کاربر تلفنی')
        ->and($fresh->phone)->toBe('09120000503')
        ->and((bool) $fresh->is_active)->toBeTrue()
        ->and((bool) $fresh->can_access_erp)->toBeTrue()
        ->and($fresh->password)->not->toBeEmpty();
});

it('leaves an existing user with an email untouched', function (): void {
    $user = User::factory()->create([
        'email' => 'existing@example.test',
        'phone' => '09120000504',
    ]);

    expect($user->fresh()->email)->toBe('existing@example.test');
});

it('still enforces email uniqueness for users that do have an email', function (): void {
    User::factory()->create(['email' => 'duplicate@example.test', 'phone' => '09120000505']);

    expect(fn () => User::factory()->create(['email' => 'duplicate@example.test', 'phone' => '09120000506']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('allows more than one user with a null email', function (): void {
    User::factory()->create(['email' => null, 'phone' => '09120000507']);
    User::factory()->create(['email' => null, 'phone' => '09120000508']);

    expect(DB::table('users')->whereNull('email')->count())->toBe(2);
});

it('renders the profile page for a user with a null email without crashing', function (): void {
    $user = User::factory()->create([
        'email' => null,
        'name' => 'کاربر بدون ایمیل',
        'phone' => '09120000509',
        'is_active' => true,
        'can_access_erp' => true,
    ]);

    $response = $this->actingAs($user)->get(route('profile.edit'));

    $response->assertOk();
    $response->assertSee('کاربر بدون ایمیل');
});

it('renders the login page with the phone and password fields and CSRF protection', function (): void {
    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertSee('name="phone"', escape: false);
    $response->assertSee('name="password"', escape: false);
    $response->assertSee('name="_token"', escape: false);
});

it('renders the profile forms with their expected method spoofing and field names', function (): void {
    $user = User::factory()->create([
        'is_active' => true,
        'can_access_erp' => true,
    ]);

    $response = $this->actingAs($user)->get(route('profile.edit'));

    $response->assertOk();
    $response->assertSee('name="_token"', escape: false);
    $response->assertSee('name="current_password"', escape: false);
    $response->assertSee('name="password_confirmation"', escape: false);
    $response->assertSee('value="patch"', escape: false);
    $response->assertSee('value="put"', escape: false);
    $response->assertSee('value="delete"', escape: false);
});

it('updates the profile name and email through the normal form payload', function (): void {
    $user = User::factory()->create([
        'name' => 'نام قدیمی',
        'email' => 'old@example.test',
        'is_active' => true,
        'can_access_erp' => true,
    ]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'نام جدید',
            'email' => 'new@example.test',
        ])
        ->assertRedirect();

    $fresh = $user->fresh();

    expect($fresh->name)->toBe('نام جدید')
        ->and($fresh->email)->toBe('new@example.test');
});

it('changes the password and rejects a wrong current password', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('current-password'),
        'crm_user_id' => null,
        'is_active' => true,
        'can_access_erp' => true,
    ]);

    $this->actingAs($user)
        ->put(route('password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
        ->assertSessionHasErrors('current_password', errorBag: 'updatePassword');

    expect(Hash::check('current-password', $user->fresh()->password))->toBeTrue();

    $this->actingAs($user)
        ->put(route('password.update'), [
            'current_password' => 'current-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('brand-new-password', $user->fresh()->password))->toBeTrue();
});

it('requires the password confirmation to delete the account', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('current-password'),
        'is_active' => true,
        'can_access_erp' => true,
    ]);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password', errorBag: 'userDeletion');

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});
