<?php

use App\Livewire\Profile\ProfileForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('requires authentication to view the profile page', function () {
    $this->get(route('profile.show'))->assertRedirect(route('login'));
});

it('renders the profile page with the livewire form', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertSeeLivewire('profile.profile-form')
        ->assertSee('الملف الشخصي');
});

it('loads the persisted user data into the form', function () {
    $user = User::factory()->create([
        'name' => 'أحمد علي',
        'email' => 'ahmed@example.com',
    ]);

    $this->actingAs($user);

    Livewire::test(ProfileForm::class)
        ->assertSet('name', 'أحمد علي')
        ->assertSet('email', 'ahmed@example.com');
});

it('updates the profile without a page reload', function () {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@example.com',
    ]);

    $this->actingAs($user);

    Livewire::test(ProfileForm::class)
        ->set('name', 'New Name')
        ->set('email', 'NEW@example.com')
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertSet('email', 'new@example.com')
        ->assertDispatched('profile-updated')
        ->assertDispatched('toast');

    expect($user->refresh()->name)->toBe('New Name')
        ->and($user->email)->toBe('new@example.com');
});

it('allows the user to keep their own email', function () {
    $user = User::factory()->create(['email' => 'same@example.com']);

    $this->actingAs($user);

    Livewire::test(ProfileForm::class)
        ->set('name', 'Updated Name')
        ->set('email', 'same@example.com')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($user->refresh()->name)->toBe('Updated Name');
});

it('rejects an email that belongs to another user', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'mine@example.com']);

    $this->actingAs($user);

    Livewire::test(ProfileForm::class)
        ->set('name', 'My Name')
        ->set('email', 'taken@example.com')
        ->call('saveProfile')
        ->assertHasErrors(['email']);

    expect($user->refresh()->email)->toBe('mine@example.com');
});

it('validates profile fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(ProfileForm::class)
        ->set('name', '')
        ->set('email', 'not-an-email')
        ->call('saveProfile')
        ->assertHasErrors(['name', 'email']);
});

it('changes the password and clears the password fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(ProfileForm::class)
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('savePassword')
        ->assertHasNoErrors()
        ->assertSet('current_password', '')
        ->assertSet('password', '')
        ->assertSet('password_confirmation', '')
        ->assertDispatched('toast');

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

it('rejects a wrong current password', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(ProfileForm::class)
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('savePassword')
        ->assertHasErrors(['current_password']);

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

it('validates the new password confirmation', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(ProfileForm::class)
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'different-password')
        ->call('savePassword')
        ->assertHasErrors(['password']);
});
