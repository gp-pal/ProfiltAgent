<?php

namespace Tests\Feature;

use App\Filament\Resources\ProfileFields\Pages\CreateProfileField;
use App\Models\ProfileField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileFieldAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@profiltagent.example', 'password' => 'password123']);
    }

    public function test_list_page_requires_authentication(): void
    {
        $this->get('/admin/profile-fields')->assertRedirect('/admin/login');
    }

    public function test_admin_sees_the_seeded_catalogue(): void
    {
        // "positioning" alone is a false-positive needle: it is a substring of
        // Filament's own `fi-fixed-positioning-context` CSS class, so it "matches"
        // even with an empty table. Assert on the seeded row's actual label instead,
        // which is unique Lithuanian text that only the rendered `label` column emits.
        $field = ProfileField::query()->where('key', 'positioning')->firstOrFail();

        $this->actingAs($this->admin())
            ->get('/admin/profile-fields')
            ->assertStatus(200)
            ->assertSee($field->label);
    }

    public function test_admin_can_reach_create_and_edit(): void
    {
        $field = ProfileField::query()->where('key', 'brands')->firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/profile-fields/create')->assertStatus(200);
        $this->actingAs($admin)->get("/admin/profile-fields/{$field->getKey()}/edit")->assertStatus(200);
    }

    public function test_a_reserved_key_is_rejected_by_the_form(): void
    {
        // `quality` is the worst of the five: OfficialSiteSource would harvest the
        // match judge's own verdict array into fields['quality'], and the profile
        // infolist's scalarize() throws on an array value.
        $this->actingAs($this->admin());

        Livewire::test(CreateProfileField::class)
            ->fillForm(['key' => 'quality', 'label' => 'Kokybė', 'type' => 'string'])
            ->call('create')
            ->assertHasFormErrors(['key']);

        $this->assertNull(ProfileField::query()->where('key', 'quality')->first());
    }

    public function test_a_non_reserved_key_still_saves(): void
    {
        // Guards against the blacklist rule being too broad and rejecting everything.
        $this->actingAs($this->admin());

        Livewire::test(CreateProfileField::class)
            ->fillForm(['key' => 'headcount', 'label' => 'Darbuotojų skaičius', 'type' => 'string'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNotNull(ProfileField::query()->where('key', 'headcount')->first());
    }
}
