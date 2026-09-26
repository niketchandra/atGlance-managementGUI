<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\UserAvatar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class UserAvatarTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    public function test_initials_use_first_and_last_name(): void
    {
        $this->assertSame('NC', UserAvatar::initials(['first_name' => 'Niket', 'last_name' => 'Chandrawanshi', 'name' => 'nik']));
        $this->assertSame('NC', UserAvatar::initials(['first_name' => ' niket ', 'last_name' => 'chandrawanshi']));
    }

    public function test_initials_fall_back_to_name(): void
    {
        $this->assertSame('NC', UserAvatar::initials(['name' => 'Niket Chandrawanshi']));
        $this->assertSame('JS', UserAvatar::initials(['name' => 'john ronald smith']));
        $this->assertSame('P', UserAvatar::initials(['name' => 'Priya']));
        // Only one of first/last set: the full name is used.
        $this->assertSame('AB', UserAvatar::initials(['first_name' => 'Zed', 'name' => 'Asha Bose']));
        $this->assertSame('Z', UserAvatar::initials(['first_name' => 'Zed', 'name' => '']));
    }

    public function test_initials_fall_back_to_email_and_handle_unicode(): void
    {
        $this->assertSame('J', UserAvatar::initials(['name' => '', 'email' => 'jane@example.test']));
        $this->assertSame('JD', UserAvatar::initials(['name' => null, 'email' => 'jane.doe@example.test']));
        $this->assertSame('ÉÖ', UserAvatar::initials(['name' => 'élodie östberg']));
        $this->assertSame('न', UserAvatar::initials(['name' => 'निकेत']));
        $this->assertSame('?', UserAvatar::initials(['name' => '---']));
    }

    public function test_profile_and_header_show_initials(): void
    {
        $user = $this->actingAsRole(102);
        $user->forceFill(['name' => 'Niket Chandrawanshi'])->save();

        $this->assertSame('NC', $user->initials());

        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('class="user-avatar"', false)
            ->assertSee('>NC</span>', false)
            ->assertSee('background:#000000;', false)
            // The old generic avatar: a 150px circle with a 60px user icon.
            ->assertDontSee('font-size: 60px; color: white; margin: 0 auto;', false);
    }

    public function test_admin_user_list_shows_initials(): void
    {
        $admin = $this->actingAsRole(100);
        $admin->forceFill(['first_name' => 'Asha', 'last_name' => 'Bose'])->save();

        $this->get(route('admin.users'))
            ->assertOk()
            ->assertSee('>AB</span>', false);
    }
}
