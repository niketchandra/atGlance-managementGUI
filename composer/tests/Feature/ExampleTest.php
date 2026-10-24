<?php

namespace Tests\Feature;

use App\Support\InstallationState;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The home page answers: before installation it sends the visitor to the installer,
     * afterwards it shows the sign-in page. Whether the installer marker exists depends on
     * other tests in the run, so both cases are checked explicitly.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        if (InstallationState::isInstalled()) {
            $response->assertOk();
        } else {
            $response->assertRedirect(route('install.show'));
        }
    }
}
