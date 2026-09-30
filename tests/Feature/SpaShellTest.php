<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaShellTest extends TestCase
{
    public function test_login_and_application_routes_serve_the_vue_shell(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('id="app"', false)
            ->assertSee('Դիագեն Պլյուս · Պահեստ');

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->get('/inventory/1/act')
            ->assertOk()
            ->assertSee('id="app"', false);
    }
}
